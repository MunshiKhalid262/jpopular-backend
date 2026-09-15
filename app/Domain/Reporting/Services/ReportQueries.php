<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Services;

use App\Enums\InvoiceStatus;
use App\Enums\TaxType;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\StockMovement;
use App\Support\BusinessPeriod;
use App\Support\StockStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The filtered query behind every report.
 *
 * THE POINT OF THIS CLASS: the on-screen report and its CSV export are built
 * from the SAME builder. An export therefore cannot drift from what the
 * operator is looking at -- selecting September and GST exports exactly the
 * September GST rows, because there is only one definition of "the September
 * GST rows".
 *
 * WHAT COUNTS AS A SALE: finalized, non-cancelled invoices. A cancelled
 * invoice is excluded from every sales total by default, because the sale did
 * not happen -- but it is not hidden from history either: passing
 * `status=cancelled` inspects them deliberately.
 *
 * `invoice_date` is a DATE column, so it is compared against business-timezone
 * dates. `received_at` and `occurred_at` are timestamps stored in UTC, so they
 * are compared against converted instants. Mixing those up is exactly the
 * boundary bug BusinessPeriod exists to prevent.
 */
final class ReportQueries
{
    /**
     * Invoices for the sales-style reports.
     *
     * @return Builder<Invoice>
     */
    public function invoices(Request $request, BusinessPeriod $period): Builder
    {
        $status = $request->string('status')->toString();

        return Invoice::query()
            ->with('customer:id,name,gstin,state_code')
            ->whereBetween('invoice_date', $period->dateColumnBounds())
            ->when(
                $status !== '' && in_array($status, array_column(InvoiceStatus::cases(), 'value'), true),
                // An explicit status inspects exactly that set, cancelled included.
                fn ($query) => $query->where('status', $status),
                // Otherwise: issued invoices only. Drafts are not sales yet and
                // cancelled ones never were.
                fn ($query) => $query->where('status', InvoiceStatus::Finalized->value),
            )
            ->when(
                $request->filled('tax_type'),
                fn ($query) => $query->where('tax_type', $request->string('tax_type')->toString())
            )
            ->when(
                $request->filled('customer_id'),
                fn ($query) => $query->where('customer_id', $request->integer('customer_id'))
            )
            ->when(
                $request->filled('payment_status'),
                fn ($query) => $query->where('payment_status', $request->string('payment_status')->toString())
            )
            ->orderBy('invoice_date')
            ->orderBy('id');
    }

    /**
     * GST invoices only, with the supply-type filter.
     *
     * @return Builder<Invoice>
     */
    public function gstInvoices(Request $request, BusinessPeriod $period): Builder
    {
        return $this->invoices($request, $period)
            ->where('tax_type', TaxType::Gst->value)
            ->when(
                $request->filled('supply_type'),
                fn ($query) => $query->where('supply_type', $request->string('supply_type')->toString())
            );
    }

    /**
     * @return Builder<Invoice>
     */
    public function nonGstInvoices(Request $request, BusinessPeriod $period): Builder
    {
        return $this->invoices($request, $period)->where('tax_type', TaxType::NonGst->value);
    }

    /**
     * Payments actually received in the period.
     *
     * Voided payments are excluded: the money was given back, so counting it
     * as received would overstate collections.
     *
     * @return Builder<Payment>
     */
    public function payments(Request $request, BusinessPeriod $period): Builder
    {
        [$from, $to] = $period->utcBounds();

        return Payment::query()
            ->with(['invoice:id,invoice_number,customer_id', 'invoice.customer:id,name', 'createdBy:id,name'])
            ->effective()
            ->whereBetween('received_at', [$from, $to])
            ->when(
                $request->filled('payment_method'),
                fn ($query) => $query->where('payment_method', $request->string('payment_method')->toString())
            )
            ->when(
                $request->filled('customer_id'),
                fn ($query) => $query->whereHas(
                    'invoice',
                    fn ($invoice) => $invoice->where('customer_id', $request->integer('customer_id'))
                )
            )
            ->orderBy('received_at')
            ->orderBy('id');
    }

    /**
     * Finalized invoices still owing money.
     *
     * NOT date-filtered by default: receivables are a position as at today,
     * not a flow over a period. A date range narrows which invoices are
     * considered when one is supplied.
     *
     * @return Builder<Invoice>
     */
    public function outstanding(Request $request, ?BusinessPeriod $period = null): Builder
    {
        return Invoice::query()
            ->with('customer:id,name,phone')
            // Cancelled invoices owe nothing, whatever their totals say.
            ->where('status', InvoiceStatus::Finalized->value)
            ->whereRaw('(grand_total - paid_amount) > 0')
            ->when(
                $period !== null,
                fn ($query) => $query->whereBetween('invoice_date', $period->dateColumnBounds())
            )
            ->when(
                $request->filled('customer_id'),
                fn ($query) => $query->where('customer_id', $request->integer('customer_id'))
            )
            // Oldest first: the most overdue is what needs chasing.
            ->orderBy('invoice_date')
            ->orderBy('id');
    }

    /**
     * Stock on hand.
     *
     * Reuses StockStatus so the report's idea of "low stock" is the same one
     * the Inventory module uses, rather than a second definition that drifts.
     *
     * @return Builder<Product>
     */
    public function inventory(Request $request): Builder
    {
        return Product::query()
            ->with(['category:id,name', 'brand:id,name'])
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = '%'.$request->string('search')->toString().'%';
                $query->where(fn ($inner) => $inner->where('name', 'like', $term)->orWhere('sku', 'like', $term));
            })
            ->when(
                $request->filled('category_id'),
                fn ($query) => $query->where('category_id', $request->integer('category_id'))
            )
            ->when(
                $request->filled('brand_id'),
                fn ($query) => $query->where('brand_id', $request->integer('brand_id'))
            )
            ->when(
                $request->filled('stock_status')
                    && in_array($request->string('stock_status')->toString(), StockStatus::values(), true),
                fn ($query) => StockStatus::scope($query, $request->string('stock_status')->toString())
            )
            ->orderBy('current_stock')
            ->orderBy('name');
    }

    /**
     * The existing stock ledger, filtered. No second history table.
     *
     * @return Builder<StockMovement>
     */
    public function stockMovements(Request $request, BusinessPeriod $period): Builder
    {
        [$from, $to] = $period->utcBounds();

        return StockMovement::query()
            ->with(['product:id,name,sku,unit', 'createdBy:id,name'])
            ->whereBetween('occurred_at', [$from, $to])
            ->when(
                $request->filled('product_id'),
                fn ($query) => $query->where('product_id', $request->integer('product_id'))
            )
            ->when(
                $request->filled('type'),
                fn ($query) => $query->where('type', $request->string('type')->toString())
            )
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');
    }
}
