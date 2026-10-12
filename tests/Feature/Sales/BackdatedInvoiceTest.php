<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Models\BusinessSettings;
use App\Models\Customer;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\StockMovement;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\ApiTestCase;

/**
 * Invoices can be raised for a past date.
 *
 * The shop writes up paperwork after the fact, so a supply made last week has
 * to be billed with last week's date, not the date somebody got round to
 * keying it. The number has to fall in the financial year of the SUPPLY, and
 * the stock movement has to be dated to the supply too, or the stock ledger
 * and the sales report disagree about when goods left the building.
 */
class BackdatedInvoiceTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        BusinessSettings::current()->forceFill([
            'business_name' => 'J POPULAR AUTO',
            'gstin' => '19AMYPI5698G2Z0',
            'state' => 'West Bengal',
            'state_code' => '19',
            'invoice_prefix' => 'JP',
            'dealer_invoice_prefix' => 'JD',
            'financial_year_start_month' => 4,
        ])->save();
        BusinessSettings::forgetCache();
    }

    private function fixtures(): array
    {
        $product = Product::factory()->create([
            'gst_rate' => '5.00',
            'hsn_code' => '87116020',
            'current_stock' => '100.000',
            'selling_price' => '36000.00',
        ]);

        $customer = Customer::factory()->create([
            'state' => 'West Bengal',
            'state_code' => '19',
        ]);

        return [$product, $customer];
    }

    private function createDraft(string $date, Product $product, Customer $customer): int
    {
        return $this->postJson('/api/v1/invoices', [
            'customer_id' => $customer->id,
            'invoice_type' => 'customer',
            'tax_type' => 'gst',
            'invoice_date' => $date,
            'lines' => [[
                'product_id' => $product->id,
                'quantity' => '1',
                'unit_price' => '36000.00',
            ]],
        ])->assertStatus(201)->json('data.id');
    }

    #[Test]
    public function an_invoice_can_be_raised_for_a_past_date(): void
    {
        Sanctum::actingAs($this->admin());
        [$product, $customer] = $this->fixtures();

        $id = $this->createDraft('2026-05-14', $product, $customer);

        $this->getJson("/api/v1/invoices/{$id}")
            ->assertOk()
            ->assertJsonPath('data.invoice_date', '2026-05-14');
    }

    #[Test]
    public function a_backdated_invoice_is_numbered_in_the_year_of_the_supply(): void
    {
        Sanctum::actingAs($this->admin());
        [$product, $customer] = $this->fixtures();

        // 14 May 2025 falls in the 2025-26 financial year, not the current one.
        $id = $this->createDraft('2025-05-14', $product, $customer);

        $this->postJson("/api/v1/invoices/{$id}/finalize")
            ->assertOk()
            ->assertJsonPath('data.financial_year', '2025-26')
            ->assertJsonPath('data.invoice_number', 'JP/2025-26/00001');
    }

    #[Test]
    public function a_date_before_the_financial_year_boundary_belongs_to_the_earlier_year(): void
    {
        Sanctum::actingAs($this->admin());
        [$product, $customer] = $this->fixtures();

        // 31 March is the last day of the preceding financial year.
        $id = $this->createDraft('2026-03-31', $product, $customer);

        $this->postJson("/api/v1/invoices/{$id}/finalize")
            ->assertOk()
            ->assertJsonPath('data.financial_year', '2025-26');
    }

    #[Test]
    public function each_financial_year_keeps_its_own_counter(): void
    {
        Sanctum::actingAs($this->admin());
        [$product, $customer] = $this->fixtures();

        $old = $this->createDraft('2025-06-01', $product, $customer);
        $new = $this->createDraft('2026-06-01', $product, $customer);

        $oldNumber = $this->postJson("/api/v1/invoices/{$old}/finalize")->assertOk()->json('data.invoice_number');
        $newNumber = $this->postJson("/api/v1/invoices/{$new}/finalize")->assertOk()->json('data.invoice_number');

        // Backdating must not consume a number out of the current year's run.
        $this->assertSame('JP/2025-26/00001', $oldNumber);
        $this->assertSame('JP/2026-27/00001', $newNumber);
    }

    #[Test]
    public function the_stock_movement_is_dated_to_the_supply_not_to_today(): void
    {
        Sanctum::actingAs($this->admin());
        [$product, $customer] = $this->fixtures();

        $id = $this->createDraft('2025-06-01', $product, $customer);
        $this->postJson("/api/v1/invoices/{$id}/finalize")->assertOk();

        /*
         * Otherwise the stock ledger says the goods left today while the sales
         * report says they were sold last year, and the two never reconcile.
         *
         * The ledger references the invoice ITEM, not the invoice: one movement
         * per line, which is what lets a single line be traced back.
         */
        $movement = StockMovement::whereIn(
            'reference_id',
            InvoiceItem::where('invoice_id', $id)->pluck('id'),
        )->where('reference_type', 'InvoiceItem')->firstOrFail();

        $this->assertSame('2025-06-01', $movement->occurred_at->toDateString());
    }

    #[Test]
    public function finalizing_a_backdated_invoice_still_checks_stock_as_it_stands_today(): void
    {
        Sanctum::actingAs($this->admin());
        [$product, $customer] = $this->fixtures();

        $id = $this->createDraft('2025-06-01', $product, $customer);

        // Everything has since been sold.
        $product->forceFill(['current_stock' => '0.000'])->save();

        /*
         * Documents the limit of back-dating: the draft is accepted, but
         * finalizing is refused because the ledger only knows TODAY's stock,
         * not what was on the shelf last June. Raising paperwork late for
         * goods that have since gone out is therefore blocked.
         *
         * Deliberately not relaxed here: letting a finalize drive stock
         * negative would silently corrupt the figure every other report and
         * reorder decision is built on.
         */
        $this->postJson("/api/v1/invoices/{$id}/finalize")->assertStatus(409);
    }
}
