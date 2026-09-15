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

class DashboardTest extends ApiTestCase
{
    use MakesInvoices;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureBusiness();
    }

    private function dashboard(): array
    {
        return $this->getJson('/api/v1/dashboard')->assertOk()->json('data');
    }

    // ------------------------------------------------------------ sales

    #[Test]
    public function todays_sales_count_finalized_invoices_raised_today(): void
    {
        Sanctum::actingAs($this->admin());

        $this->finalizedInvoice(['quantity' => '2']); // 2360
        $this->finalizedInvoice(['quantity' => '1']); // 1180

        $data = $this->dashboard();

        $this->assertSame('3540.00', $data['sales']['today_total']);
        $this->assertSame(2, $data['sales']['today_count']);
    }

    #[Test]
    public function monthly_sales_include_earlier_days_of_the_month(): void
    {
        Sanctum::actingAs($this->admin());

        $today = BusinessPeriod::now();

        $this->finalizedInvoice(['quantity' => '1']);
        // An earlier day in the same month, unless today IS the 1st.
        $earlier = $today->copy()->startOfMonth();
        $this->finalizedInvoice(['quantity' => '1', 'date' => $earlier->toDateString()]);

        $data = $this->dashboard();

        $this->assertSame(2, $data['sales']['month_count']);
        $this->assertSame('2360.00', $data['sales']['month_total']);
    }

    #[Test]
    public function a_draft_invoice_is_not_a_sale(): void
    {
        Sanctum::actingAs($this->admin());

        $this->draftInvoice(['quantity' => '5']);

        $data = $this->dashboard();

        $this->assertSame('0.00', $data['sales']['today_total']);
        $this->assertSame(0, $data['sales']['today_count']);
    }

    #[Test]
    public function a_cancelled_invoice_is_excluded_from_sales_totals(): void
    {
        $actor = $this->admin();
        Sanctum::actingAs($actor);

        $kept = $this->finalizedInvoice(['quantity' => '1', 'actor' => $actor]);
        $voided = $this->finalizedInvoice(['quantity' => '3', 'actor' => $actor]);

        app(CancelInvoice::class)->handle($voided, 'Raised in error', $actor);

        $data = $this->dashboard();

        // Only the surviving invoice counts.
        $this->assertSame('1180.00', $data['sales']['today_total']);
        $this->assertSame(1, $data['sales']['today_count']);
        $this->assertNotNull($kept->invoice_number);
    }

    // --------------------------------------------------------- payments

    #[Test]
    public function sales_and_payments_received_are_different_numbers(): void
    {
        $actor = $this->admin();
        Sanctum::actingAs($actor);

        // Invoice 2360, collect 1000.
        $invoice = $this->finalizedInvoice(['quantity' => '2', 'actor' => $actor]);

        app(RecordPayment::class)->handle($invoice, [
            'amount' => '1000.00',
            'payment_method' => PaymentMethod::Cash,
        ], $actor);

        $data = $this->dashboard();

        $this->assertSame('2360.00', $data['sales']['today_total'], 'sales is what was invoiced');
        $this->assertSame('1000.00', $data['payments']['today_received'], 'payments is what was collected');
        $this->assertSame('1360.00', $data['payments']['outstanding_total'], 'outstanding is the difference');
    }

    #[Test]
    public function outstanding_excludes_cancelled_invoices(): void
    {
        $actor = $this->admin();
        Sanctum::actingAs($actor);

        $this->finalizedInvoice(['quantity' => '1', 'actor' => $actor]); // 1180 owed
        $cancelled = $this->finalizedInvoice(['quantity' => '5', 'actor' => $actor]);

        app(CancelInvoice::class)->handle($cancelled, 'Returned', $actor);

        $this->assertSame('1180.00', $this->dashboard()['payments']['outstanding_total']);
    }

    #[Test]
    public function a_voided_payment_stops_counting_as_received(): void
    {
        $actor = $this->admin();
        Sanctum::actingAs($actor);

        $invoice = $this->finalizedInvoice(['quantity' => '2', 'actor' => $actor]);

        $payment = app(RecordPayment::class)->handle($invoice, [
            'amount' => '2360.00',
            'payment_method' => PaymentMethod::Cash,
        ], $actor);

        $this->assertSame('2360.00', $this->dashboard()['payments']['today_received']);

        app(VoidPayment::class)->handle($payment, 'Bounced', $actor);

        $data = $this->dashboard();

        $this->assertSame('0.00', $data['payments']['today_received']);
        $this->assertSame('2360.00', $data['payments']['outstanding_total']);
    }

    // -------------------------------------------------------- inventory

    #[Test]
    public function inventory_counts_reflect_stock_levels(): void
    {
        Sanctum::actingAs($this->admin());

        Product::factory()->create(['current_stock' => '0.000', 'min_stock_level' => '5.000']);
        Product::factory()->create(['current_stock' => '3.000', 'min_stock_level' => '5.000']);
        Product::factory()->create(['current_stock' => '50.000', 'min_stock_level' => '5.000']);
        Product::factory()->inactive()->create(['current_stock' => '99.000', 'min_stock_level' => '1.000']);

        $data = $this->dashboard()['inventory'];

        $this->assertSame(4, $data['total_products']);
        $this->assertSame(3, $data['active_products']);
        $this->assertSame(1, $data['out_of_stock_count']);
        // At or below the threshold but not exhausted.
        $this->assertSame(1, $data['low_stock_count']);
    }

    #[Test]
    public function customer_counts_are_returned(): void
    {
        Sanctum::actingAs($this->admin());

        Customer::factory()->count(2)->create();
        Customer::factory()->create(['is_active' => false]);

        $data = $this->dashboard()['customers'];

        $this->assertSame(2, $data['active_customers']);
        $this->assertSame(3, $data['total_customers']);
    }

    // --------------------------------------------------------- tax split

    #[Test]
    public function the_month_splits_gst_and_non_gst_sales(): void
    {
        Sanctum::actingAs($this->admin());

        $this->finalizedInvoice(['quantity' => '1']); // GST: 1180
        $this->finalizedInvoice(['quantity' => '2', 'tax_type' => TaxType::NonGst]); // 2000

        $data = $this->dashboard()['tax_split'];

        $this->assertSame('1180.00', $data['gst_total']);
        $this->assertSame(1, $data['gst_count']);
        $this->assertSame('2000.00', $data['non_gst_total']);
        $this->assertSame(1, $data['non_gst_count']);
    }

    // ----------------------------------------------------- recent + trend

    #[Test]
    public function the_sales_trend_covers_seven_days_including_empty_ones(): void
    {
        Sanctum::actingAs($this->admin());

        $this->finalizedInvoice(['quantity' => '1']);

        $trend = $this->dashboard()['sales_trend'];

        $this->assertCount(7, $trend);
        // Days with no sales are present as zero, so the chart axis is even.
        $this->assertSame('0.00', $trend[0]['total']);
        $this->assertSame('1180.00', $trend[6]['total']);
        $this->assertSame(BusinessPeriod::now()->toDateString(), $trend[6]['date']);
    }

    #[Test]
    public function recent_activity_is_returned_and_bounded(): void
    {
        Sanctum::actingAs($this->admin());

        $this->finalizedInvoice(['quantity' => '1']);

        $data = $this->dashboard();

        $this->assertNotEmpty($data['recent_invoices']);
        $this->assertLessThanOrEqual(8, count($data['recent_invoices']));
        // Finalizing deducted stock, so a movement exists.
        $this->assertNotEmpty($data['recent_movements']);
        $this->assertSame('invoice_sale', $data['recent_movements'][0]['type']);
    }

    #[Test]
    public function low_stock_products_are_listed_for_the_attention_panel(): void
    {
        Sanctum::actingAs($this->admin());

        Product::factory()->create(['name' => 'Nearly out', 'current_stock' => '1.000', 'min_stock_level' => '5.000']);
        Product::factory()->create(['name' => 'Plenty', 'current_stock' => '99.000', 'min_stock_level' => '5.000']);

        $names = collect($this->dashboard()['low_stock_products'])->pluck('name');

        $this->assertContains('Nearly out', $names);
        $this->assertNotContains('Plenty', $names);
    }

    // ------------------------------------------------------- permissions

    #[Test]
    public function the_dashboard_requires_authentication_and_permission(): void
    {
        $this->getJson('/api/v1/dashboard')->assertStatus(401);

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/dashboard')->assertStatus(403);
    }

    #[Test]
    public function a_manager_can_see_the_dashboard(): void
    {
        Sanctum::actingAs($this->manager());

        $this->getJson('/api/v1/dashboard')->assertOk();
    }
}
