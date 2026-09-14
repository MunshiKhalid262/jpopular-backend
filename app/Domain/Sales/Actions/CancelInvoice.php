<?php

declare(strict_types=1);

namespace App\Domain\Sales\Actions;

use App\Domain\Inventory\Services\StockLedger;
use App\Domain\Sales\Exceptions\InvoiceStateException;
use App\Enums\InvoiceStatus;
use App\Enums\StockMovementType;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Cancels a finalized invoice and returns its stock.
 *
 * The invoice is NOT deleted and its number is NOT reused: a cancelled invoice
 * stays in the sequence and remains viewable, because a gap in invoice numbers
 * is itself a question a GST audit will ask.
 *
 * Stock is restored with compensating `invoice_cancel` movements rather than
 * by deleting the original sale rows. The ledger is append-only, so the
 * history shows that stock went out and came back -- which is what happened.
 */
final class CancelInvoice
{
    public function __construct(private readonly StockLedger $ledger) {}

    public function handle(Invoice $invoice, string $reason, User $actor): Invoice
    {
        if ($invoice->isCancelled()) {
            throw InvoiceStateException::alreadyCancelled();
        }

        if (! $invoice->isFinalized()) {
            throw InvoiceStateException::notFinalized();
        }

        return DB::transaction(function () use ($invoice, $reason, $actor): Invoice {
            $items = $invoice->items()->get();

            $this->ledger->lockProducts(
                $items->pluck('product_id')->filter()->map(fn ($id): int => (int) $id)->all()
            );

            foreach ($items as $item) {
                if ($item->product_id === null) {
                    continue;
                }

                /*
                 * Referencing the same invoice item as the sale, but with a
                 * different type -- so the idempotency index treats it as a
                 * distinct movement while still refusing a SECOND cancellation
                 * of the same line.
                 */
                $this->ledger->recordLocked([
                    'product' => $item->product()->firstOrFail(),
                    'type' => StockMovementType::InvoiceCancel,
                    'quantity' => (string) $item->quantity,
                    'reference_type' => 'InvoiceItem',
                    'reference_id' => (int) $item->getKey(),
                    'user_id' => (int) $actor->getKey(),
                    'note' => 'Returned from cancelled invoice '.$invoice->invoice_number,
                ]);
            }

            $invoice->forceFill([
                'status' => InvoiceStatus::Cancelled->value,
                'cancelled_at' => now(),
                'cancelled_by' => $actor->getKey(),
                'cancellation_reason' => $reason,
            ])->save();

            return $invoice->refresh();
        });
    }
}
