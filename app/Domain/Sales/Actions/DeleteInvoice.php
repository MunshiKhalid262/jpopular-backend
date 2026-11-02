<?php

declare(strict_types=1);

namespace App\Domain\Sales\Actions;

use App\Domain\Inventory\Services\StockLedger;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Deletes an invoice outright, whatever its status, and unwinds its stock.
 *
 * NOTE ON GST. A finalized invoice has been issued under a numbered series.
 * Deleting it leaves a hole in that series, and a hole is something an audit
 * asks about -- which is why `CancelInvoice` exists and is the safer route for
 * a document that has already left the building. This action is the blunt one,
 * provided deliberately for correcting a mistake rather than for routine use.
 * The counter is NOT rewound: reissuing a number that has been on a document
 * would be worse than the gap, and would risk two invoices sharing a number.
 *
 * STOCK. `products.current_stock` is a cache of SUM(stock_movements.quantity),
 * so the ledger rows are removed and the cache is then REBUILT from what
 * remains rather than adjusted by hand. That is what makes this correct for
 * every status without special-casing any of them:
 *
 *   - a draft has no movements, so nothing changes;
 *   - a finalized invoice has one `invoice_sale` per line, and dropping it
 *     returns the goods to stock;
 *   - a cancelled invoice has an `invoice_sale` AND its `invoice_cancel`
 *     reversal, which net to zero, so dropping both leaves stock untouched.
 *
 * Hand-rolled arithmetic would have to get all three cases right; recomputing
 * the sum cannot get them wrong.
 */
final class DeleteInvoice
{
    public function __construct(private readonly StockLedger $ledger) {}

    public function handle(Invoice $invoice, User $actor): void
    {
        $number = $invoice->invoice_number ?? ('draft #'.$invoice->getKey());
        $status = $invoice->status->value;

        DB::transaction(function () use ($invoice): void {
            $items = $invoice->items()->get();

            /** @var list<int> $productIds */
            $productIds = $items->pluck('product_id')
                ->filter()
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all();

            // Deterministic order, same as every other writer, so two
            // concurrent deletions cannot deadlock against each other.
            $this->ledger->lockProducts($productIds);

            StockMovement::query()
                ->where('reference_type', 'InvoiceItem')
                ->whereIn('reference_id', $items->pluck('id')->all())
                ->delete();

            /*
             * forceDelete, not delete. Invoice and Payment both soft-delete,
             * while items, charges and movements do not -- so an ordinary
             * delete left a soft-deleted invoice whose lines had actually
             * gone: a shell that could be "restored" into a document with no
             * contents and stock already returned. If the record is to go, it
             * goes.
             *
             * Payments first: payments.invoice_id is RESTRICT, so a real row
             * left behind would block the invoice delete outright.
             */
            $invoice->payments()->forceDelete();
            $invoice->charges()->delete();
            $invoice->items()->delete();
            $invoice->forceDelete();

            foreach (Product::query()->whereIn('id', $productIds)->get() as $product) {
                $this->ledger->recalculate($product, fix: true);
            }
        });

        /*
         * Worth a line in the log: this is the one operation that removes a
         * numbered document, and the gap it leaves has no other explanation
         * anywhere in the system.
         */
        Log::warning('Invoice deleted', [
            'invoice_number' => $number,
            'status' => $status,
            'deleted_by' => $actor->getKey(),
        ]);
    }
}
