<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Services;

use App\Enums\InvoiceStatus;
use App\Enums\TaxType;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\StockMovement;
use App\Support\BusinessPeriod;
use App\Support\StockStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard figures, computed with SQL aggregates.
 *
 * Nothing here loads a table into PHP to add it up: every total is one
 * aggregate query, and the recent-activity lists are bounded and eager-loaded
 * so the dashboard is a fixed number of queries no matter how much history
 * exists.
 *
 * SALES AND PAYMENTS ARE NOT THE SAME NUMBER, and are deliberately computed
 * from different tables. Sales is what was invoiced (invoices.grand_total);
 * payments received is what was actually collected (payments.amount). Invoice
 * 50,000 today and collect 30,000 and the dashboard says exactly that.
 */
final class DashboardMetrics
{
    private const RECENT_LIMIT = 8;

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $today = BusinessPeriod::today();
        $month = BusinessPeriod::thisMonth();

        return [
            'period' => [
                'today' => $today->toArray(),
                'month' => $month->toArray(),
                'timezone' => BusinessPeriod::timezone(),
            ],
            'sales' => $this->sales($today, $month),
            'payments' => $this->payments($today, $month),
            'inventory' => $this->inventory(),
            'customers' => $this->customers(),
            'tax_split' => $this->taxSplit($month),
            'sales_trend' => $this->salesTrend(),
            'recent_invoices' => $this->recentInvoices(),
            'recent_movements' => $this->recentMovements(),
            'low_stock_products' => $this->lowStockProducts(),
        ];
    }

    /**
     * Invoiced value. Finalized only -- a draft is not a sale and a cancelled
     * invoice never was.
     *
     * @return array<string, string|int>
     */
    private function sales(BusinessPeriod $today, BusinessPeriod $month): array
    {
        $todayRow = $this->invoiceTotals($today);
        $monthRow = $this->invoiceTotals($month);

        return [
            'today_total' => $todayRow->total,
            'today_count' => (int) $todayRow->count,
            'month_total' => $monthRow->total,
            'month_count' => (int) $monthRow->count,
        ];
    }

    private function invoiceTotals(BusinessPeriod $period): object
    {
        /** @var object{total: string, count: int} $row */
        $row = Invoice::query()
            ->where('status', InvoiceStatus::Finalized->value)
            ->whereBetween('invoice_date', $period->dateColumnBounds())
            ->selectRaw('COALESCE(SUM(grand_total), 0) as total, COUNT(*) as count')
            ->first();

        return (object) [
            'total' => bcadd((string) $row->total, '0', 2),
            'count' => (int) $row->count,
        ];
    }

    /**
     * Cash actually collected, and what is still owed.
     *
     * @return array<string, string>
     */
    private function payments(BusinessPeriod $today, BusinessPeriod $month): array
    {
        [$todayFrom, $todayTo] = $today->utcBounds();
        [$monthFrom, $monthTo] = $month->utcBounds();

        $received = fn ($from, $to): string => bcadd(
            (string) Payment::query()->effective()->whereBetween('received_at', [$from, $to])->sum('amount'),
            '0',
            2,
        );

        // Receivables are a position as at now, not a flow, so they are not
        // date-bounded. Cancelled invoices owe nothing.
        $outstanding = Invoice::query()
            ->where('status', InvoiceStatus::Finalized->value)
            ->whereRaw('(grand_total - paid_amount) > 0')
            ->selectRaw('COALESCE(SUM(grand_total - paid_amount), 0) as due')
            ->value('due');

        return [
            'today_received' => $received($todayFrom, $todayTo),
            'month_received' => $received($monthFrom, $monthTo),
            'outstanding_total' => bcadd((string) $outstanding, '0', 2),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function inventory(): array
    {
        /*
         * One pass over products with conditional aggregates, rather than three
         * COUNT queries. The status rules match App\Support\StockStatus: out of
         * stock is checked first, because zero is also at or below any
         * threshold.
         */
        /** @var object $row */
        $row = Product::query()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active')
            ->selectRaw('SUM(CASE WHEN current_stock <= 0 THEN 1 ELSE 0 END) as out_of_stock')
            ->selectRaw('SUM(CASE WHEN current_stock > 0 AND current_stock <= min_stock_level THEN 1 ELSE 0 END) as low_stock')
            ->first();

        return [
            'total_products' => (int) $row->total,
            'active_products' => (int) $row->active,
            'low_stock_count' => (int) $row->low_stock,
            'out_of_stock_count' => (int) $row->out_of_stock,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function customers(): array
    {
        return [
            'active_customers' => Customer::query()->where('is_active', true)->count(),
            'total_customers' => Customer::query()->count(),
        ];
    }

    /**
     * GST vs non-GST split for the current month.
     *
     * @return array<string, string|int>
     */
    private function taxSplit(BusinessPeriod $month): array
    {
        $rows = Invoice::query()
            ->where('status', InvoiceStatus::Finalized->value)
            ->whereBetween('invoice_date', $month->dateColumnBounds())
            ->groupBy('tax_type')
            ->selectRaw('tax_type, COALESCE(SUM(grand_total), 0) as total, COUNT(*) as count')
            ->get()
            ->keyBy('tax_type');

        $of = fn (TaxType $type, string $column, string $default): string => (string) (
            $rows->get($type->value)->{$column} ?? $default
        );

        return [
            'gst_total' => bcadd($of(TaxType::Gst, 'total', '0'), '0', 2),
            'gst_count' => (int) $of(TaxType::Gst, 'count', '0'),
            'non_gst_total' => bcadd($of(TaxType::NonGst, 'total', '0'), '0', 2),
            'non_gst_count' => (int) $of(TaxType::NonGst, 'count', '0'),
        ];
    }

    /**
     * Invoiced value per day for the last 7 days, oldest first.
     *
     * Days with no sales are filled with zero so the chart has an even
     * x-axis rather than silently collapsing empty days.
     *
     * @return list<array<string, string>>
     */
    private function salesTrend(int $days = 7): array
    {
        $period = BusinessPeriod::lastDays($days);

        $totals = Invoice::query()
            ->where('status', InvoiceStatus::Finalized->value)
            ->whereBetween('invoice_date', $period->dateColumnBounds())
            ->groupBy('invoice_date')
            ->selectRaw('invoice_date, COALESCE(SUM(grand_total), 0) as total, COUNT(*) as count')
            ->get()
            /*
             * Keyed by DATE only. `invoice_date` is cast to a Carbon date, so
             * stringifying it yields "2026-09-15 00:00:00" and would never
             * match the plain "2026-09-15" the series below looks up.
             */
            ->keyBy(fn ($row): string => $row->invoice_date instanceof Carbon
                ? $row->invoice_date->toDateString()
                : substr((string) $row->invoice_date, 0, 10));

        $series = [];
        $cursor = $period->start->copy();

        while ($cursor->lessThanOrEqualTo($period->end)) {
            $key = $cursor->toDateString();
            $row = $totals->get($key);

            $series[] = [
                'date' => $key,
                'label' => $cursor->format('d M'),
                'total' => bcadd((string) ($row->total ?? '0'), '0', 2),
                'count' => (int) ($row->count ?? 0),
            ];

            $cursor->addDay();
        }

        return $series;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentInvoices(): array
    {
        return Invoice::query()
            ->with('customer:id,name')
            ->whereIn('status', [InvoiceStatus::Finalized->value, InvoiceStatus::Cancelled->value])
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->limit(self::RECENT_LIMIT)
            ->get()
            ->map(fn (Invoice $invoice): array => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'invoice_date' => $invoice->invoice_date?->toDateString(),
                'customer_name' => $invoice->customer?->name,
                'tax_type' => $invoice->tax_type->value,
                'status' => $invoice->status->value,
                'payment_status' => $invoice->payment_status->value,
                'grand_total' => $invoice->grand_total,
                'due_amount' => $invoice->due_amount,
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentMovements(): array
    {
        return StockMovement::query()
            ->with(['product:id,name,sku,unit', 'createdBy:id,name'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(self::RECENT_LIMIT)
            ->get()
            ->map(fn (StockMovement $movement): array => [
                'id' => $movement->id,
                'product_id' => $movement->product_id,
                'product_name' => $movement->product?->name,
                'sku' => $movement->product?->sku,
                'unit' => $movement->product?->unit,
                'type' => $movement->type->value,
                'type_label' => $movement->type->label(),
                'increases_stock' => $movement->type->increasesStock(),
                'quantity' => $movement->quantity,
                'new_stock' => $movement->new_stock,
                'user_name' => $movement->createdBy?->name,
                'occurred_at' => $movement->occurred_at?->toIso8601String(),
            ])
            ->all();
    }

    /** Low-stock products for the dashboard's attention list. */
    public function lowStockProducts(int $limit = 6): array
    {
        return Product::query()
            ->with(['category:id,name'])
            ->where('is_active', true)
            ->whereColumn('current_stock', '<=', 'min_stock_level')
            ->orderBy(DB::raw('current_stock - min_stock_level'))
            ->orderBy('current_stock')
            ->limit($limit)
            ->get()
            ->map(fn (Product $product): array => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'unit' => $product->unit,
                'current_stock' => $product->current_stock,
                'min_stock_level' => $product->min_stock_level,
                'stock_status' => StockStatus::for($product),
            ])
            ->all();
    }
}
