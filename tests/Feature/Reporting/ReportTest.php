<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Domain\Payments\Actions\RecordPayment;
use App\Domain\Payments\Actions\VoidPayment;
use App\Domain\Sales\Actions\CancelInvoice;
use App\Enums\PaymentMethod;
use App\Enums\TaxType;
use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use App\Support\BusinessPeriod;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\ApiTestCase;
use Tests\Concerns\MakesInvoices;

class ReportTest extends ApiTestCase
{
    use MakesInvoices;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureBusiness();
    }

    private function today(): string
    {
        return BusinessPeriod::now()->toDateString();
    }

    // -------------------------------------------------------- sales

    #[Test]
    public function the_sales_report_lists_finalized_invoices_with_totals(): void
    {
        Sanctum::actingAs($this->admin());

        $this->finalizedInvoice(['quantity' => '2']); // 2360
        $this->finalizedInvoice(['quantity' => '1', 'tax_type' => TaxType::NonGst]); // 1000

        $response = $this->getJson('/api/v1/reports/sales')->assertOk();

        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('meta.summary.invoice_count', 2);
        $response->assertJsonPath('meta.summary.grand_total', '3360.00');
    }

    #[Test]
    public function the_sales_report_excludes_drafts_and_cancelled_by_default(): void
    {
        $actor = $this->admin();
        Sanctum::actingAs($actor);

        $this->finalizedInvoice(['quantity' => '1', 'actor' => $actor]);
        $this->draftInvoice(['quantity' => '9']);
        $cancelled = $this->finalizedInvoice(['quantity' => '5', 'actor' => $actor]);
        app(CancelInvoice::class)->handle($cancelled, 'Error', $actor);

        $this->getJson('/api/v1/reports/sales')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.summary.grand_total', '1180.00');
    }

    #[Test]
    public function cancelled_invoices_can_be_inspected_deliberately(): void
    {
        $actor = $this->admin();
        Sanctum::actingAs($actor);

        $this->finalizedInvoice(['quantity' => '1', 'actor' => $actor]);
        $cancelled = $this->finalizedInvoice(['quantity' => '5', 'actor' => $actor]);
        app(CancelInvoice::class)->handle($cancelled, 'Error', $actor);

        // Excluded from the default view, but not erased from history.
        $this->getJson('/api/v1/reports/sales?status=cancelled')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'cancelled');
    }

    #[Test]
    public function the_sales_report_filters_by_date_range(): void
    {
        Sanctum::actingAs($this->admin());

        $today = BusinessPeriod::now();
        $old = $today->copy()->subMonths(2);

        $this->finalizedInvoice(['quantity' => '1']);
        $this->finalizedInvoice(['quantity' => '3', 'date' => $old->toDateString()]);

        // Default range is the current month.
        $this->getJson('/api/v1/reports/sales')->assertOk()->assertJsonCount(1, 'data');

        // An explicit range covering the old invoice finds exactly it.
        $this->getJson("/api/v1/reports/sales?date_from={$old->toDateString()}&date_to={$old->toDateString()}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.summary.grand_total', '3540.00');
    }

    #[Test]
    public function a_single_day_range_includes_the_whole_of_that_day(): void
    {
        // The boundary bug this guards: a date column stored as
        // "2026-09-15 00:00:00" compared against the bare "2026-09-15" upper
        // bound would exclude the entire day.
        Sanctum::actingAs($this->admin());

        $this->finalizedInvoice(['quantity' => '1']);

        $day = $this->today();

        $this->getJson("/api/v1/reports/sales?date_from={$day}&date_to={$day}")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    #[Test]
    public function the_sales_report_filters_by_tax_type_and_customer(): void
    {
        Sanctum::actingAs($this->admin());

        $customer = Customer::factory()->create(['name' => 'Target Customer']);

        $this->finalizedInvoice(['quantity' => '1', 'customer' => $customer]);
        $this->finalizedInvoice(['quantity' => '2', 'tax_type' => TaxType::NonGst]);

        $this->getJson('/api/v1/reports/sales?tax_type=gst')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.tax_type', 'gst');

        $this->getJson("/api/v1/reports/sales?customer_id={$customer->id}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.customer_name', 'Target Customer');
    }

    // ---------------------------------------------------------- GST

    #[Test]
    public function the_gst_report_covers_only_gst_invoices_and_sums_the_tax(): void
    {
        Sanctum::actingAs($this->admin());

        $this->finalizedInvoice(['quantity' => '1']); // 1000 taxable, 90 + 90
        $this->finalizedInvoice(['quantity' => '2', 'tax_type' => TaxType::NonGst]);

        $response = $this->getJson('/api/v1/reports/gst')->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('meta.summary.taxable', '1000.00');
        $response->assertJsonPath('meta.summary.cgst', '90.00');
        $response->assertJsonPath('meta.summary.sgst', '90.00');
        $response->assertJsonPath('meta.summary.igst', '0.00');
        $response->assertJsonPath('meta.summary.total_tax', '180.00');
    }

    #[Test]
    public function the_gst_report_can_filter_by_supply_type(): void
    {
        Sanctum::actingAs($this->admin());

        $this->finalizedInvoice(['quantity' => '1']); // intra-state
        $this->finalizedInvoice([
            'quantity' => '1',
            'customer' => Customer::factory()->interState()->create(),
        ]);

        $this->getJson('/api/v1/reports/gst?supply_type=inter_state')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.summary.igst', '180.00')
            ->assertJsonPath('meta.summary.cgst', '0.00');
    }

    #[Test]
    public function the_gst_report_exposes_the_customer_gstin(): void
    {
        Sanctum::actingAs($this->admin());

        $customer = Customer::factory()->registered()->create();
        $this->finalizedInvoice(['quantity' => '1', 'customer' => $customer]);

        $this->getJson('/api/v1/reports/gst')
            ->assertOk()
            ->assertJsonPath('data.0.customer_gstin', $customer->gstin);
    }

    // ------------------------------------------------------ non-GST

    #[Test]
    public function the_non_gst_report_shows_no_tax_columns(): void
    {
        Sanctum::actingAs($this->admin());

        $this->finalizedInvoice(['quantity' => '2', 'tax_type' => TaxType::NonGst]);
        $this->finalizedInvoice(['quantity' => '1']);

        $response = $this->getJson('/api/v1/reports/non-gst')->assertOk();

        $response->assertJsonCount(1, 'data');
        $row = $response->json('data.0');

        foreach (['cgst_amount', 'sgst_amount', 'igst_amount', 'taxable_amount'] as $absent) {
            $this->assertArrayNotHasKey($absent, $row, "non-GST rows must not carry {$absent}");
        }

        $response->assertJsonPath('meta.summary.grand_total', '2000.00');
    }

    // ----------------------------------------------------- payments

    #[Test]
    public function the_payment_report_totals_receipts_and_splits_by_method(): void
    {
        $actor = $this->admin();
        Sanctum::actingAs($actor);

        $invoice = $this->finalizedInvoice(['quantity' => '3', 'actor' => $actor]); // 3540

        app(RecordPayment::class)->handle($invoice, ['amount' => '1000.00', 'payment_method' => PaymentMethod::Cash], $actor);
        app(RecordPayment::class)->handle($invoice->fresh(), ['amount' => '500.00', 'payment_method' => PaymentMethod::Upi], $actor);

        $response = $this->getJson('/api/v1/reports/payments')->assertOk();

        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('meta.summary.total_received', '1500.00');

        $byMethod = collect($response->json('meta.summary.by_method'))->keyBy('payment_method');
        $this->assertSame('1000.00', $byMethod['cash']['total']);
        $this->assertSame('500.00', $byMethod['upi']['total']);
    }

    #[Test]
    public function the_payment_report_filters_by_method(): void
    {
        $actor = $this->admin();
        Sanctum::actingAs($actor);

        $invoice = $this->finalizedInvoice(['quantity' => '3', 'actor' => $actor]);

        app(RecordPayment::class)->handle($invoice, ['amount' => '100.00', 'payment_method' => PaymentMethod::Cash], $actor);
        app(RecordPayment::class)->handle($invoice->fresh(), ['amount' => '200.00', 'payment_method' => PaymentMethod::Card], $actor);

        $this->getJson('/api/v1/reports/payments?payment_method=card')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.summary.total_received', '200.00');
    }

    #[Test]
    public function a_voided_payment_is_excluded_from_the_payment_report(): void
    {
        $actor = $this->admin();
        Sanctum::actingAs($actor);

        $invoice = $this->finalizedInvoice(['quantity' => '3', 'actor' => $actor]);
        $payment = app(RecordPayment::class)->handle($invoice, ['amount' => '400.00', 'payment_method' => PaymentMethod::Cash], $actor);

        app(VoidPayment::class)->handle($payment, 'Bounced', $actor);

        $this->getJson('/api/v1/reports/payments')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.summary.total_received', '0.00');
    }

    // -------------------------------------------------- outstanding

    #[Test]
    public function the_outstanding_report_shows_only_unpaid_balances(): void
    {
        $actor = $this->admin();
        Sanctum::actingAs($actor);

        $partly = $this->finalizedInvoice(['quantity' => '2', 'actor' => $actor]); // 2360
        app(RecordPayment::class)->handle($partly, ['amount' => '360.00', 'payment_method' => PaymentMethod::Cash], $actor);

        $settled = $this->finalizedInvoice(['quantity' => '1', 'actor' => $actor]); // 1180
        app(RecordPayment::class)->handle($settled, ['amount' => '1180.00', 'payment_method' => PaymentMethod::Cash], $actor);

        $response = $this->getJson('/api/v1/reports/outstanding')->assertOk();

        // The fully settled invoice drops out entirely.
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.outstanding', '2000.00');
        $response->assertJsonPath('meta.summary.outstanding', '2000.00');
    }

    #[Test]
    public function the_outstanding_report_ignores_cancelled_invoices(): void
    {
        $actor = $this->admin();
        Sanctum::actingAs($actor);

        $cancelled = $this->finalizedInvoice(['quantity' => '4', 'actor' => $actor]);
        app(CancelInvoice::class)->handle($cancelled, 'Returned', $actor);

        $this->getJson('/api/v1/reports/outstanding')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.summary.outstanding', '0.00');
    }

    // ---------------------------------------------------- inventory

    #[Test]
    public function the_inventory_report_filters_by_stock_status(): void
    {
        Sanctum::actingAs($this->admin());

        Product::factory()->create(['sku' => 'OUT-1', 'current_stock' => '0.000', 'min_stock_level' => '5.000']);
        Product::factory()->create(['sku' => 'LOW-1', 'current_stock' => '2.000', 'min_stock_level' => '5.000']);
        Product::factory()->create(['sku' => 'OK-1', 'current_stock' => '80.000', 'min_stock_level' => '5.000']);

        $this->getJson('/api/v1/reports/inventory?stock_status=low_stock')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.sku', 'LOW-1')
            ->assertJsonPath('data.0.stock_status', 'low_stock');

        $this->getJson('/api/v1/reports/inventory')
            ->assertOk()
            ->assertJsonPath('meta.summary.out_of_stock_count', 1)
            ->assertJsonPath('meta.summary.low_stock_count', 1);
    }

    // ----------------------------------------------- stock movements

    #[Test]
    public function the_stock_movement_report_reads_the_existing_ledger(): void
    {
        Sanctum::actingAs($this->admin());

        $this->finalizedInvoice(['quantity' => '2']);

        $response = $this->getJson('/api/v1/reports/stock-movements')->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.type', 'invoice_sale');
        $response->assertJsonPath('data.0.quantity', '-2.000');
        $response->assertJsonPath('data.0.previous_stock', '1000.000');
        $response->assertJsonPath('data.0.new_stock', '998.000');
    }

    #[Test]
    public function the_stock_movement_report_filters_by_type(): void
    {
        Sanctum::actingAs($this->admin());

        $this->finalizedInvoice(['quantity' => '1']);

        $this->getJson('/api/v1/reports/stock-movements?type=stock_in')
            ->assertOk()->assertJsonCount(0, 'data');

        $this->getJson('/api/v1/reports/stock-movements?type=invoice_sale')
            ->assertOk()->assertJsonCount(1, 'data');
    }

    // --------------------------------------------------- permissions

    #[Test]
    public function reports_require_authentication(): void
    {
        foreach (['sales', 'gst', 'payments', 'outstanding', 'inventory', 'stock-movements'] as $report) {
            $this->getJson("/api/v1/reports/{$report}")->assertStatus(401);
        }
    }

    #[Test]
    public function a_user_without_report_permissions_is_refused(): void
    {
        Sanctum::actingAs(User::factory()->create());

        foreach (['sales', 'gst', 'payments', 'outstanding', 'inventory', 'stock-movements'] as $report) {
            $this->getJson("/api/v1/reports/{$report}")->assertStatus(403);
        }
    }

    #[Test]
    public function a_manager_gets_operational_reports_but_not_financial_ones(): void
    {
        Sanctum::actingAs($this->manager());

        // Operational: the manager runs the shop floor.
        $this->getJson('/api/v1/reports/sales')->assertOk();
        $this->getJson('/api/v1/reports/non-gst')->assertOk();
        $this->getJson('/api/v1/reports/inventory')->assertOk();
        $this->getJson('/api/v1/reports/stock-movements')->assertOk();

        // Financial: the tax position and cash collected stay with the Admin,
        // so inventory access never becomes financial access.
        $this->getJson('/api/v1/reports/gst')->assertStatus(403);
        $this->getJson('/api/v1/reports/payments')->assertStatus(403);
        $this->getJson('/api/v1/reports/outstanding')->assertStatus(403);
    }
}
