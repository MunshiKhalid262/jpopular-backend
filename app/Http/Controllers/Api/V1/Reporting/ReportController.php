<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Reporting;

use App\Domain\Reporting\Services\ReportQueries;
use App\Enums\PermissionName;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\StockMovement;
use App\Support\ApiResponse;
use App\Support\BusinessPeriod;
use App\Support\CsvExport;
use App\Support\StockStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The reports.
 *
 * Every report is a pair: a paginated JSON view and a CSV export built from
 * THE SAME ReportQueries builder, so an export always contains exactly the
 * filtered dataset on screen.
 *
 * Exporting additionally requires reports.export, checked alongside the
 * report's own permission -- so a direct call to an export URL cannot bypass
 * the gate on the report it exports.
 */
class ReportController extends Controller
{
    private const MAX_PER_PAGE = 200;

    public function __construct(private readonly ReportQueries $queries) {}

    /* ------------------------------------------------------------- guards */

    private function authorizeReport(Request $request, PermissionName $permission): void
    {
        $user = $request->user();

        abort_unless($user?->can(PermissionName::ReportsView->value) ?? false, 403);
        abort_unless($user->can($permission->value), 403);
    }

    private function authorizeExport(Request $request, PermissionName $permission): void
    {
        $this->authorizeReport($request, $permission);

        abort_unless($request->user()->can(PermissionName::ReportsExport->value), 403);
    }

    private function perPage(Request $request): int
    {
        return min($request->integer('per_page', 50), self::MAX_PER_PAGE);
    }

    /* --------------------------------------------------------- sales */

    public function sales(Request $request): JsonResponse
    {
        $this->authorizeReport($request, PermissionName::ReportsSales);

        $period = BusinessPeriod::fromRequest($request);
        $query = $this->queries->invoices($request, $period);

        // Totals are aggregated in SQL over the WHOLE filtered set, not just
        // the current page, and not by adding up rows in PHP.
        $totals = (clone $query)->reorder()->selectRaw(
            'COUNT(*) as count,
             COALESCE(SUM(subtotal), 0) as subtotal,
             COALESCE(SUM(discount_amount), 0) as discount,
             COALESCE(SUM(taxable_amount), 0) as taxable,
             COALESCE(SUM(total_tax), 0) as tax,
             COALESCE(SUM(grand_total), 0) as grand_total,
             COALESCE(SUM(paid_amount), 0) as paid'
        )->first();

        return ApiResponse::success(
            $query->paginate($this->perPage($request))->withQueryString()->through(
                fn (Invoice $invoice): array => $this->salesRow($invoice)
            ),
            meta: [
                'period' => $period->toArray(),
                'summary' => [
                    'invoice_count' => (int) $totals->count,
                    'subtotal' => bcadd((string) $totals->subtotal, '0', 2),
                    'discount' => bcadd((string) $totals->discount, '0', 2),
                    'taxable' => bcadd((string) $totals->taxable, '0', 2),
                    'tax' => bcadd((string) $totals->tax, '0', 2),
                    'grand_total' => bcadd((string) $totals->grand_total, '0', 2),
                    'paid' => bcadd((string) $totals->paid, '0', 2),
                    'outstanding' => bcsub(
                        bcadd((string) $totals->grand_total, '0', 2),
                        bcadd((string) $totals->paid, '0', 2),
                        2,
                    ),
                ],
            ],
        );
    }

    public function exportSales(Request $request): StreamedResponse
    {
        $this->authorizeExport($request, PermissionName::ReportsSales);

        $period = BusinessPeriod::fromRequest($request);
        $query = $this->queries->invoices($request, $period);

        return CsvExport::download(
            "sales-report-{$period->label()}",
            ['Date', 'Invoice No', 'Customer', 'Type', 'Status', 'Subtotal', 'Discount', 'Taxable', 'Tax', 'Grand Total', 'Paid', 'Balance', 'Payment Status'],
            $this->rows($query, fn (Invoice $invoice): array => [
                $invoice->invoice_date?->toDateString(),
                $invoice->invoice_number,
                $invoice->customer?->name ?? 'Walk-in',
                $invoice->tax_type->label(),
                $invoice->status->value,
                $invoice->subtotal,
                $invoice->discount_amount,
                $invoice->taxable_amount,
                $invoice->total_tax,
                $invoice->grand_total,
                $invoice->paid_amount,
                bcsub((string) $invoice->grand_total, (string) $invoice->paid_amount, 2),
                $invoice->payment_status->value,
            ]),
        );
    }

    /* ----------------------------------------------------------- GST */

    public function gst(Request $request): JsonResponse
    {
        $this->authorizeReport($request, PermissionName::ReportsGst);

        $period = BusinessPeriod::fromRequest($request);
        $query = $this->queries->gstInvoices($request, $period);

        $totals = (clone $query)->reorder()->selectRaw(
            'COUNT(*) as count,
             COALESCE(SUM(taxable_amount), 0) as taxable,
             COALESCE(SUM(cgst_amount), 0) as cgst,
             COALESCE(SUM(sgst_amount), 0) as sgst,
             COALESCE(SUM(igst_amount), 0) as igst,
             COALESCE(SUM(total_tax), 0) as tax,
             COALESCE(SUM(grand_total), 0) as grand_total'
        )->first();

        return ApiResponse::success(
            $query->paginate($this->perPage($request))->withQueryString()->through(
                fn (Invoice $invoice): array => [
                    'id' => $invoice->id,
                    'invoice_date' => $invoice->invoice_date?->toDateString(),
                    'invoice_number' => $invoice->invoice_number,
                    'customer_name' => $invoice->customer?->name,
                    'customer_gstin' => $invoice->customer?->gstin,
                    'supply_type' => $invoice->supply_type?->value,
                    'place_of_supply' => $invoice->place_of_supply_state_code,
                    'taxable_amount' => $invoice->taxable_amount,
                    'cgst_amount' => $invoice->cgst_amount,
                    'sgst_amount' => $invoice->sgst_amount,
                    'igst_amount' => $invoice->igst_amount,
                    'total_tax' => $invoice->total_tax,
                    'grand_total' => $invoice->grand_total,
                    'status' => $invoice->status->value,
                ]
            ),
            meta: [
                'period' => $period->toArray(),
                'summary' => [
                    'invoice_count' => (int) $totals->count,
                    'taxable' => bcadd((string) $totals->taxable, '0', 2),
                    'cgst' => bcadd((string) $totals->cgst, '0', 2),
                    'sgst' => bcadd((string) $totals->sgst, '0', 2),
                    'igst' => bcadd((string) $totals->igst, '0', 2),
                    'total_tax' => bcadd((string) $totals->tax, '0', 2),
                    'grand_total' => bcadd((string) $totals->grand_total, '0', 2),
                ],
            ],
        );
    }

    public function exportGst(Request $request): StreamedResponse
    {
        $this->authorizeExport($request, PermissionName::ReportsGst);

        $period = BusinessPeriod::fromRequest($request);
        $query = $this->queries->gstInvoices($request, $period);

        return CsvExport::download(
            "gst-sales-report-{$period->label()}",
            ['Date', 'Invoice No', 'Customer', 'Customer GSTIN', 'Supply Type', 'Taxable', 'CGST', 'SGST', 'IGST', 'Total GST', 'Invoice Total'],
            $this->rows($query, fn (Invoice $invoice): array => [
                $invoice->invoice_date?->toDateString(),
                $invoice->invoice_number,
                $invoice->customer?->name ?? 'Walk-in',
                $invoice->customer?->gstin,
                $invoice->supply_type?->value,
                $invoice->taxable_amount,
                $invoice->cgst_amount,
                $invoice->sgst_amount,
                $invoice->igst_amount,
                $invoice->total_tax,
                $invoice->grand_total,
            ]),
        );
    }

    /* ------------------------------------------------------- non-GST */

    public function nonGst(Request $request): JsonResponse
    {
        $this->authorizeReport($request, PermissionName::ReportsSales);

        $period = BusinessPeriod::fromRequest($request);
        $query = $this->queries->nonGstInvoices($request, $period);

        $totals = (clone $query)->reorder()->selectRaw(
            'COUNT(*) as count,
             COALESCE(SUM(subtotal), 0) as subtotal,
             COALESCE(SUM(discount_amount), 0) as discount,
             COALESCE(SUM(grand_total), 0) as grand_total,
             COALESCE(SUM(paid_amount), 0) as paid'
        )->first();

        return ApiResponse::success(
            $query->paginate($this->perPage($request))->withQueryString()->through(
                // No CGST/SGST/IGST columns at all: they would be a meaningless
                // zero on a bill that charges no GST.
                fn (Invoice $invoice): array => [
                    'id' => $invoice->id,
                    'invoice_date' => $invoice->invoice_date?->toDateString(),
                    'invoice_number' => $invoice->invoice_number,
                    'customer_name' => $invoice->customer?->name,
                    'subtotal' => $invoice->subtotal,
                    'discount_amount' => $invoice->discount_amount,
                    'grand_total' => $invoice->grand_total,
                    'paid_amount' => $invoice->paid_amount,
                    'balance' => bcsub((string) $invoice->grand_total, (string) $invoice->paid_amount, 2),
                    'payment_status' => $invoice->payment_status->value,
                    'status' => $invoice->status->value,
                ]
            ),
            meta: [
                'period' => $period->toArray(),
                'summary' => [
                    'invoice_count' => (int) $totals->count,
                    'subtotal' => bcadd((string) $totals->subtotal, '0', 2),
                    'discount' => bcadd((string) $totals->discount, '0', 2),
                    'grand_total' => bcadd((string) $totals->grand_total, '0', 2),
                    'paid' => bcadd((string) $totals->paid, '0', 2),
                    'balance' => bcsub(
                        bcadd((string) $totals->grand_total, '0', 2),
                        bcadd((string) $totals->paid, '0', 2),
                        2,
                    ),
                ],
            ],
        );
    }

    public function exportNonGst(Request $request): StreamedResponse
    {
        $this->authorizeExport($request, PermissionName::ReportsSales);

        $period = BusinessPeriod::fromRequest($request);
        $query = $this->queries->nonGstInvoices($request, $period);

        return CsvExport::download(
            "non-gst-sales-report-{$period->label()}",
            ['Date', 'Invoice No', 'Customer', 'Subtotal', 'Discount', 'Grand Total', 'Paid', 'Balance'],
            $this->rows($query, fn (Invoice $invoice): array => [
                $invoice->invoice_date?->toDateString(),
                $invoice->invoice_number,
                $invoice->customer?->name ?? 'Walk-in',
                $invoice->subtotal,
                $invoice->discount_amount,
                $invoice->grand_total,
                $invoice->paid_amount,
                bcsub((string) $invoice->grand_total, (string) $invoice->paid_amount, 2),
            ]),
        );
    }

    /* ------------------------------------------------------ payments */

    public function payments(Request $request): JsonResponse
    {
        $this->authorizeReport($request, PermissionName::ReportsPayments);

        $period = BusinessPeriod::fromRequest($request);
        $query = $this->queries->payments($request, $period);

        $total = (clone $query)->reorder()->selectRaw('COUNT(*) as count, COALESCE(SUM(amount), 0) as total')->first();

        // Totals per method, in SQL rather than by grouping in PHP.
        $byMethod = (clone $query)->reorder()
            ->groupBy('payment_method')
            ->selectRaw('payment_method, COUNT(*) as count, COALESCE(SUM(amount), 0) as total')
            ->get()
            ->map(fn ($row): array => [
                'payment_method' => $row->payment_method->value,
                'label' => $row->payment_method->label(),
                'count' => (int) $row->count,
                'total' => bcadd((string) $row->total, '0', 2),
            ])
            ->all();

        return ApiResponse::success(
            $query->paginate($this->perPage($request))->withQueryString()->through(
                fn (Payment $payment): array => [
                    'id' => $payment->id,
                    'received_at' => $payment->received_at?->toIso8601String(),
                    'invoice_id' => $payment->invoice_id,
                    'invoice_number' => $payment->invoice?->invoice_number,
                    'customer_name' => $payment->invoice?->customer?->name,
                    'payment_method' => $payment->payment_method->value,
                    'payment_method_label' => $payment->payment_method->label(),
                    'reference' => $payment->reference,
                    'amount' => $payment->amount,
                    'received_by' => $payment->createdBy?->name,
                ]
            ),
            meta: [
                'period' => $period->toArray(),
                'summary' => [
                    'payment_count' => (int) $total->count,
                    'total_received' => bcadd((string) $total->total, '0', 2),
                    'by_method' => $byMethod,
                ],
            ],
        );
    }

    public function exportPayments(Request $request): StreamedResponse
    {
        $this->authorizeExport($request, PermissionName::ReportsPayments);

        $period = BusinessPeriod::fromRequest($request);
        $query = $this->queries->payments($request, $period);

        return CsvExport::download(
            "payments-report-{$period->label()}",
            ['Received At', 'Invoice No', 'Customer', 'Method', 'Reference', 'Amount', 'Received By'],
            $this->rows($query, fn (Payment $payment): array => [
                $payment->received_at?->setTimezone(BusinessPeriod::timezone())->format('Y-m-d H:i'),
                $payment->invoice?->invoice_number,
                $payment->invoice?->customer?->name ?? 'Walk-in',
                $payment->payment_method->label(),
                $payment->reference,
                $payment->amount,
                $payment->createdBy?->name,
            ]),
        );
    }

    /* --------------------------------------------------- outstanding */

    public function outstanding(Request $request): JsonResponse
    {
        $this->authorizeReport($request, PermissionName::ReportsPayments);

        $period = $request->filled('date_from') || $request->filled('date_to')
            ? BusinessPeriod::fromRequest($request)
            : null;

        $query = $this->queries->outstanding($request, $period);

        $totals = (clone $query)->reorder()->selectRaw(
            'COUNT(*) as count,
             COALESCE(SUM(grand_total), 0) as billed,
             COALESCE(SUM(paid_amount), 0) as paid,
             COALESCE(SUM(grand_total - paid_amount), 0) as outstanding'
        )->first();

        $today = BusinessPeriod::now()->startOfDay();

        return ApiResponse::success(
            $query->paginate($this->perPage($request))->withQueryString()->through(
                fn (Invoice $invoice): array => [
                    'id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'invoice_date' => $invoice->invoice_date?->toDateString(),
                    'customer_name' => $invoice->customer?->name,
                    'customer_phone' => $invoice->customer?->phone,
                    'grand_total' => $invoice->grand_total,
                    'paid_amount' => $invoice->paid_amount,
                    'outstanding' => bcsub((string) $invoice->grand_total, (string) $invoice->paid_amount, 2),
                    // Whole days since the invoice date, in business time.
                    'days_outstanding' => $invoice->invoice_date
                        ? (int) $invoice->invoice_date->copy()->startOfDay()->diffInDays($today)
                        : null,
                    'payment_status' => $invoice->payment_status->value,
                ]
            ),
            meta: [
                'period' => $period?->toArray(),
                'summary' => [
                    'invoice_count' => (int) $totals->count,
                    'billed' => bcadd((string) $totals->billed, '0', 2),
                    'paid' => bcadd((string) $totals->paid, '0', 2),
                    'outstanding' => bcadd((string) $totals->outstanding, '0', 2),
                ],
            ],
        );
    }

    public function exportOutstanding(Request $request): StreamedResponse
    {
        $this->authorizeExport($request, PermissionName::ReportsPayments);

        $period = $request->filled('date_from') || $request->filled('date_to')
            ? BusinessPeriod::fromRequest($request)
            : null;

        $query = $this->queries->outstanding($request, $period);
        $today = BusinessPeriod::now()->startOfDay();

        return CsvExport::download(
            'outstanding-report-'.($period?->label() ?? BusinessPeriod::now()->toDateString()),
            ['Invoice No', 'Invoice Date', 'Customer', 'Phone', 'Grand Total', 'Paid', 'Outstanding', 'Days Outstanding'],
            $this->rows($query, fn (Invoice $invoice): array => [
                $invoice->invoice_number,
                $invoice->invoice_date?->toDateString(),
                $invoice->customer?->name ?? 'Walk-in',
                $invoice->customer?->phone,
                $invoice->grand_total,
                $invoice->paid_amount,
                bcsub((string) $invoice->grand_total, (string) $invoice->paid_amount, 2),
                $invoice->invoice_date ? (int) $invoice->invoice_date->copy()->startOfDay()->diffInDays($today) : null,
            ]),
        );
    }

    /* ----------------------------------------------------- inventory */

    public function inventory(Request $request): JsonResponse
    {
        $this->authorizeReport($request, PermissionName::ReportsInventory);

        $query = $this->queries->inventory($request);

        $totals = (clone $query)->reorder()->selectRaw(
            'COUNT(*) as count,
             SUM(CASE WHEN current_stock <= 0 THEN 1 ELSE 0 END) as out_of_stock,
             SUM(CASE WHEN current_stock > 0 AND current_stock <= min_stock_level THEN 1 ELSE 0 END) as low_stock'
        )->first();

        return ApiResponse::success(
            $query->paginate($this->perPage($request))->withQueryString()->through(
                fn (Product $product): array => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'sku' => $product->sku,
                    'category_name' => $product->category?->name,
                    'brand_name' => $product->brand?->name,
                    'unit' => $product->unit,
                    'current_stock' => $product->current_stock,
                    'min_stock_level' => $product->min_stock_level,
                    'stock_status' => StockStatus::for($product),
                    'is_active' => $product->is_active,
                ]
            ),
            meta: [
                'summary' => [
                    'product_count' => (int) $totals->count,
                    'low_stock_count' => (int) $totals->low_stock,
                    'out_of_stock_count' => (int) $totals->out_of_stock,
                ],
            ],
        );
    }

    public function exportInventory(Request $request): StreamedResponse
    {
        $this->authorizeExport($request, PermissionName::ReportsInventory);

        $query = $this->queries->inventory($request);

        return CsvExport::download(
            'inventory-report-'.BusinessPeriod::now()->toDateString(),
            ['Product', 'SKU', 'Category', 'Brand', 'Unit', 'Current Stock', 'Minimum Stock', 'Status'],
            $this->rows($query, fn (Product $product): array => [
                $product->name,
                $product->sku,
                $product->category?->name,
                $product->brand?->name,
                $product->unit,
                $product->current_stock,
                $product->min_stock_level,
                StockStatus::for($product),
            ]),
        );
    }

    /* ----------------------------------------------- stock movements */

    public function stockMovements(Request $request): JsonResponse
    {
        $this->authorizeReport($request, PermissionName::ReportsStock);

        $period = BusinessPeriod::fromRequest($request);
        $query = $this->queries->stockMovements($request, $period);

        return ApiResponse::success(
            $query->paginate($this->perPage($request))->withQueryString()->through(
                fn (StockMovement $movement): array => [
                    'id' => $movement->id,
                    'occurred_at' => $movement->occurred_at?->toIso8601String(),
                    'product_id' => $movement->product_id,
                    'product_name' => $movement->product?->name,
                    'sku' => $movement->product?->sku,
                    'unit' => $movement->product?->unit,
                    'type' => $movement->type->value,
                    'type_label' => $movement->type->label(),
                    'increases_stock' => $movement->type->increasesStock(),
                    'quantity' => $movement->quantity,
                    'previous_stock' => $movement->previous_stock,
                    'new_stock' => $movement->new_stock,
                    'reference_label' => $movement->referenceLabel(),
                    'note' => $movement->note,
                    'user_name' => $movement->createdBy?->name,
                ]
            ),
            meta: ['period' => $period->toArray()],
        );
    }

    public function exportStockMovements(Request $request): StreamedResponse
    {
        $this->authorizeExport($request, PermissionName::ReportsStock);

        $period = BusinessPeriod::fromRequest($request);
        $query = $this->queries->stockMovements($request, $period);

        return CsvExport::download(
            "stock-movements-report-{$period->label()}",
            ['Date', 'Product', 'SKU', 'Movement', 'Quantity', 'Previous Stock', 'New Stock', 'Reference', 'User', 'Note'],
            $this->rows($query, fn (StockMovement $movement): array => [
                $movement->occurred_at?->setTimezone(BusinessPeriod::timezone())->format('Y-m-d H:i'),
                $movement->product?->name,
                $movement->product?->sku,
                $movement->type->label(),
                $movement->quantity,
                $movement->previous_stock,
                $movement->new_stock,
                $movement->referenceLabel(),
                $movement->createdBy?->name,
                $movement->note,
            ]),
        );
    }

    /* ------------------------------------------------------- helpers */

    /**
     * Streams the filtered set in chunks, so a large export never has to fit
     * in memory all at once.
     *
     * @return \Generator<int, list<string|int|float|null>>
     */
    private function rows(mixed $query, callable $map): \Generator
    {
        foreach ($query->lazy(500) as $model) {
            yield $map($model);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function salesRow(Invoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'invoice_date' => $invoice->invoice_date?->toDateString(),
            'invoice_number' => $invoice->invoice_number,
            'customer_name' => $invoice->customer?->name,
            'tax_type' => $invoice->tax_type->value,
            'status' => $invoice->status->value,
            'subtotal' => $invoice->subtotal,
            'discount_amount' => $invoice->discount_amount,
            // Taxable value is a GST concept; on a non-GST bill it is null
            // rather than a number that implies a tax base.
            'taxable_amount' => $invoice->chargesGst() ? $invoice->taxable_amount : null,
            'total_tax' => $invoice->total_tax,
            'grand_total' => $invoice->grand_total,
            'paid_amount' => $invoice->paid_amount,
            'balance' => bcsub((string) $invoice->grand_total, (string) $invoice->paid_amount, 2),
            'payment_status' => $invoice->payment_status->value,
        ];
    }
}
