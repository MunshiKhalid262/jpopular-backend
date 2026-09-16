<?php

declare(strict_types=1);

namespace App\Domain\Sales\Actions;

use App\Domain\Sales\Data\InvoiceLineInput;
use App\Domain\Sales\Exceptions\InvoiceStateException;
use App\Domain\Sales\Services\InvoiceComposer;
use App\Enums\InvoiceStatus;
use App\Models\BusinessSettings;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Draft invoice lifecycle: create, edit, delete.
 *
 * Only drafts are writable here. A finalized invoice is a legal document with
 * an allocated number and stock already deducted; changing it after the fact
 * is refused rather than accommodated.
 */
final class ManageInvoice
{
    public function __construct(private readonly InvoiceComposer $composer) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<InvoiceLineInput>  $lines
     */
    public function create(array $attributes, array $lines, User $actor, string $invoiceDiscount = '0', array $charges = []): Invoice
    {
        return DB::transaction(function () use ($attributes, $lines, $actor, $invoiceDiscount, $charges): Invoice {
            $invoice = new Invoice;
            $invoice->fill($attributes);
            $invoice->status = InvoiceStatus::Draft;
            $invoice->created_by = $actor->getKey();

            /*
             * Snapshot the pricing mode at creation rather than reading it at
             * print time. Switching the shop to MRP-inclusive pricing next
             * month must not silently re-interpret the figures on an invoice
             * raised today, and a draft must finalize under the rules it was
             * started with.
             */
            $invoice->prices_include_tax = (bool) BusinessSettings::current()->prices_include_tax;

            $invoice->save();

            // Totals come from the same engine that will freeze them at
            // finalization, so the draft shows the figure that will be issued.
            if ($lines !== []) {
                $this->composer->apply($invoice->refresh(), $lines, $invoiceDiscount, $charges);
            }

            return $invoice->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<InvoiceLineInput>  $lines
     */
    public function update(Invoice $invoice, array $attributes, array $lines, string $invoiceDiscount = '0', array $charges = []): Invoice
    {
        $this->assertDraft($invoice);

        return DB::transaction(function () use ($invoice, $attributes, $lines, $invoiceDiscount, $charges): Invoice {
            $invoice->fill($attributes)->save();

            $this->composer->apply($invoice->refresh(), $lines, $invoiceDiscount, $charges);

            return $invoice->refresh();
        });
    }

    /**
     * Deletes a DRAFT only. A finalized invoice is cancelled, never deleted,
     * so its number stays in the sequence.
     */
    public function delete(Invoice $invoice): void
    {
        if (! $invoice->isDraft()) {
            throw InvoiceStateException::cannotDeleteFinalized();
        }

        DB::transaction(function () use ($invoice): void {
            $invoice->items()->delete();
            $invoice->charges()->delete();
            $invoice->delete();
        });
    }

    private function assertDraft(Invoice $invoice): void
    {
        if (! $invoice->isDraft()) {
            throw InvoiceStateException::notDraft();
        }
    }
}
