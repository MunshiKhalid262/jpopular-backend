<?php

declare(strict_types=1);

namespace App\Domain\Sales\Actions;

use App\Domain\Inventory\Services\StockLedger;
use App\Domain\Sales\Data\InvoiceLineInput;
use App\Domain\Sales\Exceptions\InvoiceStateException;
use App\Domain\Sales\Services\InvoiceComposer;
use App\Domain\Sales\Services\InvoiceNumberGenerator;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\StockMovementType;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Turns a draft into an issued invoice.
 *
 * Everything happens in ONE transaction, in this order, and the order matters:
 *
 *   1. lock every product on the invoice, in deterministic id order
 *   2. recompute totals and freeze the line snapshots
 *   3. allocate the invoice number under a row lock
 *   4. deduct stock through the ledger, one movement per line
 *   5. mark the invoice finalized
 *
 * Products are locked FIRST, before any write, so two concurrent
 * finalizations of invoices sharing a product serialise here rather than
 * discovering the conflict half-written. The lock is held to commit.
 *
 * Stock deduction references the INVOICE ITEM, not the invoice, so the same
 * product may legitimately appear on two lines. The ledger's
 * UNIQUE(type, reference_type, reference_id) then makes a repeated
 * finalization physically incapable of deducting twice.
 */
final class FinalizeInvoice
{
    public function __construct(
        private readonly InvoiceComposer $composer,
        private readonly InvoiceNumberGenerator $numbers,
        private readonly StockLedger $ledger,
    ) {}

    /**
     * @param  list<InvoiceLineInput>  $lines  the draft's lines, re-read at finalize time
     */
    public function handle(Invoice $invoice, array $lines, User $actor, string $invoiceDiscount = '0', array $charges = []): Invoice
    {
        if ($invoice->isFinalized()) {
            throw InvoiceStateException::alreadyFinalized();
        }

        if ($invoice->isCancelled()) {
            throw InvoiceStateException::alreadyCancelled();
        }

        if ($lines === []) {
            throw InvoiceStateException::noLines();
        }

        return DB::transaction(function () use ($invoice, $lines, $actor, $invoiceDiscount, $charges): Invoice {
            // 1. Deterministic lock order prevents deadlock between two
            //    invoices touching the same products in opposite orders.
            $this->ledger->lockProducts(
                array_map(static fn (InvoiceLineInput $line): int => (int) $line->product->getKey(), $lines)
            );

            // 2. Snapshot: from here the invoice no longer depends on the
            //    live product rows.
            $this->composer->apply($invoice, $lines, $invoiceDiscount, $charges);

            // 3. Serialised by a row lock on the sequence, never MAX+1.
            $invoice->forceFill([
                // Dealer and customer invoices draw from separate series.
                'invoice_number' => $this->numbers->next($invoice->invoice_date, $invoice->invoice_type),
                'financial_year' => $this->numbers->financialYear($invoice->invoice_date),
            ])->save();

            // 4. One movement per line, referencing the item.
            foreach ($invoice->items()->get() as $item) {
                if ($item->product_id === null) {
                    continue;
                }

                $this->ledger->recordLocked([
                    'product' => $item->product()->firstOrFail(),
                    'type' => StockMovementType::InvoiceSale,
                    'quantity' => (string) $item->quantity,
                    'reference_type' => 'InvoiceItem',
                    'reference_id' => (int) $item->getKey(),
                    'user_id' => (int) $actor->getKey(),
                    'occurred_at' => $invoice->invoice_date,
                    'note' => 'Sold on invoice '.$invoice->invoice_number,
                ]);
            }

            // 5. Payment status starts unpaid; recording payments is a
            //    separate concern that updates it later.
            $invoice->forceFill([
                'status' => InvoiceStatus::Finalized->value,
                'payment_status' => PaymentStatus::Unpaid->value,
                'finalized_at' => now(),
                'finalized_by' => $actor->getKey(),
            ])->save();

            return $invoice->refresh();
        });
    }
}
