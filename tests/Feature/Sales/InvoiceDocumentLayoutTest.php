<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Sales\Actions\FinalizeInvoice;
use App\Domain\Sales\Actions\ManageInvoice;
use App\Domain\Sales\Data\InvoiceChargeInput;
use App\Domain\Sales\Data\InvoiceLineInput;
use App\Enums\InvoiceType;
use App\Enums\TaxType;
use App\Models\BusinessSettings;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\ApiTestCase;

/**
 * The item table, checked against the printed invoice the trade issues.
 *
 * The Amount column and the subtotal beneath it have to be the same quantity:
 * an invoice whose own column does not add up to its own subtotal is one the
 * dealer will query, whatever the grand total says.
 */
class InvoiceDocumentLayoutTest extends ApiTestCase
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
            'enable_round_off' => true,
        ])->save();
        BusinessSettings::forgetCache();
    }

    /**
     * The invoice from the screenshot: 5 scooters at 48,000 exclusive of 5%
     * GST, a 3,000 discount and a 1,000 transport charge.
     */
    private function invoice(bool $pricesIncludeTax = false): Invoice
    {
        BusinessSettings::current()->forceFill(['prices_include_tax' => $pricesIncludeTax])->save();
        BusinessSettings::forgetCache();

        $actor = $this->admin();

        $product = Product::factory()->create([
            'name' => 'YAKUZA NEU 60V',
            'sku' => 'YAKUZA-NEU',
            'gst_rate' => '5.00',
            'hsn_code' => '87116020',
            'current_stock' => '100.000',
            'unit' => 'pcs',
        ]);

        $customer = Customer::factory()->create([
            'name' => 'KHALID MOTORS',
            'state' => 'West Bengal',
            'state_code' => '19',
        ]);

        $lines = [new InvoiceLineInput($product, '5', '48000.00')];
        $charges = [new InvoiceChargeInput('Transport', '1000.00', '0.00', null)];

        $draft = app(ManageInvoice::class)->create(
            attributes: [
                'customer_id' => $customer->id,
                'invoice_type' => InvoiceType::Dealer->value,
                'tax_type' => TaxType::Gst->value,
                'invoice_date' => '2026-09-22',
            ],
            lines: $lines,
            actor: $actor,
            charges: $charges,
        );

        return app(FinalizeInvoice::class)->handle($draft, $lines, $actor, '3000.00', $charges);
    }

    private function render(Invoice $invoice): string
    {
        Sanctum::actingAs($this->admin());

        return $this->get("/api/v1/invoices/{$invoice->id}/preview")->assertOk()->getContent();
    }

    /** The visible text of the items table, one cell per entry. */
    private function itemCells(string $html): array
    {
        $table = preg_split('/<table class="items"/', $html)[1] ?? '';
        $table = preg_split('/<\/table>/', $table)[0] ?? '';

        preg_match_all('/<t[dh][^>]*>(.*?)<\/t[dh]>/s', $table, $matches);

        return array_values(array_filter(array_map(
            // <br> and <div> are layout, not word boundaries the reader sees,
            // so they collapse to a single space rather than vanishing.
            fn (string $cell): string => trim((string) preg_replace(
                '/\s+/',
                ' ',
                html_entity_decode(strip_tags(preg_replace('/<(br|\/div|div)[^>]*>/i', ' ', $cell) ?? '')),
            )),
            $matches[1],
        ), fn (string $cell): bool => $cell !== ''));
    }

    // ------------------------------------------------------------------

    #[Test]
    public function the_amount_column_adds_up_to_the_subtotal_beneath_it(): void
    {
        $invoice = $this->invoice();
        $cells = $this->itemCells($this->render($invoice));

        /*
         * 5 x 48,000 = 2,40,000. The printed Amount is the line value BEFORE
         * the discount, because the discount has its own "Less : Discount" row
         * further down -- subtracting it twice is what a reader would do if the
         * Amount column quietly carried it already.
         */
        $this->assertContains('2,40,000.00', $cells);
        $this->assertSame('240000.00', $invoice->subtotal);

        // The tax-inclusive line total must NOT appear in the Amount column:
        // 2,37,000 taxable + 11,850 tax = 2,48,850 is not a column that sums.
        $this->assertNotContains('2,48,850.00', $cells);
    }

    #[Test]
    public function the_discount_column_shows_a_percentage_not_an_amount(): void
    {
        $cells = $this->itemCells($this->render($this->invoice()));

        // The column is headed "Disc. %", so 3,000.00 in it reads as 3000%.
        $this->assertNotContains('3,000.00', array_slice($cells, 0, 20));

        // 3,000 off 2,40,000 is 1.25%.
        $this->assertContains('1.25 %', $cells);
    }

    #[Test]
    public function every_line_shows_the_gst_rate_it_was_taxed_at(): void
    {
        $cells = $this->itemCells($this->render($this->invoice()));

        $this->assertContains('GST %', $cells);
        $this->assertContains('5 %', $cells);
    }

    #[Test]
    public function a_charge_carries_its_gst_rate_in_the_description(): void
    {
        $actor = $this->admin();

        $product = Product::factory()->create([
            'gst_rate' => '5.00',
            'hsn_code' => '87116020',
            'current_stock' => '100.000',
        ]);

        $customer = Customer::factory()->create(['state_code' => '19']);
        $lines = [new InvoiceLineInput($product, '1', '36000.00')];

        // Exactly as the reference invoice prints it.
        $charges = [new InvoiceChargeInput('Insurance Charges on Sales', '207.86', '18.00', '997135')];

        $draft = app(ManageInvoice::class)->create(
            attributes: [
                'customer_id' => $customer->id,
                'invoice_type' => InvoiceType::Dealer->value,
                'tax_type' => TaxType::Gst->value,
                'invoice_date' => '2026-09-22',
            ],
            lines: $lines,
            actor: $actor,
            charges: $charges,
        );

        $invoice = app(FinalizeInvoice::class)->handle($draft, $lines, $actor, '0', $charges);
        $html = $this->render($invoice);

        $this->assertStringContainsString('Insurance Charges on Sales (18%)', $html);
    }

    #[Test]
    public function the_inclusive_rate_column_prints_on_every_gst_invoice(): void
    {
        /*
         * The reference invoice shows Rate (Incl. of Tax) beside Rate. It used
         * to appear only under tax-inclusive pricing, so the same supply
         * printed with a different column layout depending on a setting the
         * dealer receiving it cannot see.
         */
        $cells = $this->itemCells($this->render($this->invoice(pricesIncludeTax: false)));

        $this->assertContains('Rate (Incl. of Tax)', $cells);

        // 48,000 taxable at 5% prints as 50,400 inclusive.
        $this->assertContains('50,400.00', $cells);
        $this->assertContains('48,000.00', $cells);
    }

    #[Test]
    public function the_inclusive_column_still_shows_the_price_as_entered(): void
    {
        // Under inclusive pricing the entered price IS the inclusive one, and
        // the taxable rate is derived. 48,000 incl. of 5% -> 45,714.29.
        $cells = $this->itemCells($this->render($this->invoice(pricesIncludeTax: true)));

        $this->assertContains('48,000.00', $cells);
        $this->assertContains('45,714.29', $cells);
    }
}
