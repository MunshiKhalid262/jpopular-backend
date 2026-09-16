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
use App\Support\AmountInWords;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\ApiTestCase;

class DealerInvoiceTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        BusinessSettings::current()->forceFill([
            'business_name' => 'J POPULAR AUTO',
            'gstin' => '19AMYPI5698G2Z0',
            'state' => 'West Bengal',
            'state_code' => '19',
            'city' => 'Purba Bardhaman',
            'address_line1' => 'MOUJA MAGRA, DAG NO 420, JL NO 173',
            'invoice_prefix' => 'JP',
            'dealer_invoice_prefix' => 'JD',
            'prices_include_tax' => true,
            'enable_round_off' => true,
        ])->save();
        BusinessSettings::forgetCache();
    }

    private function scooter(): Product
    {
        return Product::factory()->create([
            'name' => 'YAKUZA RUBIE 60V',
            'sku' => 'YAK-RUBIE-60',
            'gst_rate' => '5.00',
            'hsn_code' => '87116020',
            'current_stock' => '100.000',
            'unit' => 'pcs',
        ]);
    }

    private function dealerInvoice(array $overrides = []): Invoice
    {
        $actor = $overrides['actor'] ?? $this->admin();
        $product = $overrides['product'] ?? $this->scooter();

        $customer = Customer::factory()->create([
            'name' => 'ACME MOTORS',
            'state' => 'West Bengal',
            'state_code' => '19',
        ]);

        $lines = [new InvoiceLineInput($product, '6', '36000.00')];

        $draft = app(ManageInvoice::class)->create(
            attributes: array_merge([
                'customer_id' => $customer->id,
                'invoice_type' => InvoiceType::Dealer->value,
                'tax_type' => TaxType::Gst->value,
                'invoice_date' => '2026-09-02',
                'eway_bill_no' => '881735544731',
                'vehicle_no' => 'WB41T3727',
                'dispatched_through' => 'BY ROAD',
                'destination' => 'SANKARPUR',
                'consignee_name' => 'ACME MOTORS GODOWN',
                'consignee_address' => 'SANKARPUR, West Bengal',
                'consignee_gstin' => '19AMYPI5698G2Z0',
            ], $overrides['attributes'] ?? []),
            lines: $lines,
            actor: $actor,
            charges: $overrides['charges'] ?? [],
        );

        return app(FinalizeInvoice::class)->handle(
            $draft,
            $lines,
            $actor,
            '0',
            $overrides['charges'] ?? [],
        );
    }

    // ------------------------------------------------------ numbering

    #[Test]
    public function dealer_and_customer_invoices_run_in_separate_series(): void
    {
        $actor = $this->admin();
        $product = $this->scooter();

        $dealer = $this->dealerInvoice(['actor' => $actor, 'product' => $product]);

        $customerLines = [new InvoiceLineInput($product, '1', '36000.00')];
        $customerDraft = app(ManageInvoice::class)->create(
            attributes: [
                'customer_id' => Customer::factory()->create(['state_code' => '19'])->id,
                'invoice_type' => InvoiceType::Customer->value,
                'tax_type' => TaxType::Gst->value,
                'invoice_date' => '2026-09-02',
            ],
            lines: $customerLines,
            actor: $actor,
        );
        $customer = app(FinalizeInvoice::class)->handle($customerDraft, $customerLines, $actor);

        // Separate prefixes AND separate counters, so neither series leaves a
        // gap in the other.
        $this->assertSame('JD/2026-27/00001', $dealer->invoice_number);
        $this->assertSame('JP/2026-27/00001', $customer->invoice_number);
    }

    // ------------------------------------------------------- document

    #[Test]
    public function a_dealer_invoice_prints_the_transport_block_and_eway_page(): void
    {
        Sanctum::actingAs($this->admin());
        $invoice = $this->dealerInvoice();

        $html = $this->get("/api/v1/invoices/{$invoice->id}/preview")->assertOk()->getContent();

        $this->assertStringContainsString('e-Way Bill', $html);
        $this->assertStringContainsString('881735544731', $html);
        $this->assertStringContainsString('WB41T3727', $html);
        $this->assertStringContainsString('SANKARPUR', $html);
        $this->assertStringContainsString('Dispatched through', $html);
        $this->assertStringContainsString('Consignee (Ship to)', $html);
        $this->assertStringContainsString('ACME MOTORS GODOWN', $html);
    }

    #[Test]
    public function a_customer_invoice_carries_no_eway_bill_or_transport_block(): void
    {
        Sanctum::actingAs($this->admin());

        $product = $this->scooter();
        $actor = $this->admin();
        $lines = [new InvoiceLineInput($product, '2', '36000.00')];

        $draft = app(ManageInvoice::class)->create(
            attributes: [
                'customer_id' => Customer::factory()->create(['state_code' => '19'])->id,
                'invoice_type' => InvoiceType::Customer->value,
                'tax_type' => TaxType::Gst->value,
                'invoice_date' => '2026-09-02',
            ],
            lines: $lines,
            actor: $actor,
        );
        $invoice = app(FinalizeInvoice::class)->handle($draft, $lines, $actor);

        $html = $this->get("/api/v1/invoices/{$invoice->id}/preview")->assertOk()->getContent();

        // The whole dealer apparatus must be absent from a retail bill.
        $this->assertStringNotContainsString('e-Way Bill', $html);
        $this->assertStringNotContainsString('Dispatched through', $html);
        $this->assertStringNotContainsString('Consignee (Ship to)', $html);
        $this->assertStringNotContainsString('Motor Vehicle No.', $html);

        // But it is still a proper tax invoice.
        $this->assertStringContainsString('Tax Invoice', $html);
        $this->assertStringContainsString('J POPULAR AUTO', $html);
    }

    #[Test]
    public function the_document_shows_the_inclusive_and_taxable_rate_columns(): void
    {
        Sanctum::actingAs($this->admin());
        $invoice = $this->dealerInvoice();

        $html = $this->get("/api/v1/invoices/{$invoice->id}/preview")->assertOk()->getContent();

        $this->assertStringContainsString('Rate', $html);
        $this->assertStringContainsString('(Incl. of Tax)', $html);
        // The entered MRP and the taxable rate backed out of it.
        $this->assertStringContainsString('36,000.00', $html);
        $this->assertStringContainsString('34,285.71', $html);
    }

    #[Test]
    public function the_document_carries_an_hsn_summary_and_amount_in_words(): void
    {
        Sanctum::actingAs($this->admin());

        $invoice = $this->dealerInvoice([
            'charges' => [new InvoiceChargeInput('Insurance Charges on Sales (18%)', '207.86', '18.00', '997135')],
        ]);

        $html = $this->get("/api/v1/invoices/{$invoice->id}/preview")->assertOk()->getContent();

        $this->assertStringContainsString('HSN/SAC', $html);
        $this->assertStringContainsString('87116020', $html);
        // The charge appears on its own summary row at its own rate.
        $this->assertStringContainsString('997135', $html);
        $this->assertStringContainsString('Amount Chargeable (in words)', $html);
        $this->assertStringContainsString('Tax Amount (in words)', $html);
        $this->assertStringContainsString('Authorised Signatory', $html);
        $this->assertStringContainsString('Declaration', $html);
    }

    #[Test]
    public function the_seller_block_comes_from_business_settings(): void
    {
        Sanctum::actingAs($this->admin());
        $invoice = $this->dealerInvoice();

        $html = $this->get("/api/v1/invoices/{$invoice->id}/preview")->assertOk()->getContent();

        $this->assertStringContainsString('J POPULAR AUTO', $html);
        $this->assertStringContainsString('19AMYPI5698G2Z0', $html);
        // The former partner must never appear on a JPopular invoice.
        $this->assertStringNotContainsString('Maa-Luxmi', $html);
        $this->assertStringNotContainsString('19AANCM0950F1ZD', $html);
    }

    #[Test]
    public function a_dealer_invoice_downloads_as_a_pdf(): void
    {
        Sanctum::actingAs($this->admin());
        $invoice = $this->dealerInvoice();

        $response = $this->get("/api/v1/invoices/{$invoice->id}/pdf")->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertStringContainsString('invoice-JD-2026-27-00001.pdf', $response->headers->get('content-disposition'));
    }

    // -------------------------------------------------- historical safety

    #[Test]
    public function an_invoice_keeps_the_pricing_mode_it_was_raised_under(): void
    {
        $actor = $this->admin();
        $product = $this->scooter();

        // Raised while the shop prices inclusive of tax.
        $inclusive = $this->dealerInvoice(['actor' => $actor, 'product' => $product]);
        $this->assertTrue((bool) $inclusive->prices_include_tax);
        $this->assertSame('205714.26', $inclusive->subtotal);

        // The shop switches to tax-exclusive pricing.
        BusinessSettings::current()->forceFill(['prices_include_tax' => false])->save();
        BusinessSettings::forgetCache();

        // The issued invoice is untouched.
        $this->assertTrue((bool) $inclusive->fresh()->prices_include_tax);
        $this->assertSame('205714.26', $inclusive->fresh()->subtotal);

        // And a new invoice uses the new mode.
        $lines = [new InvoiceLineInput($product, '1', '1000.00')];
        $draft = app(ManageInvoice::class)->create(
            attributes: [
                'customer_id' => Customer::factory()->create(['state_code' => '19'])->id,
                'invoice_type' => InvoiceType::Customer->value,
                'tax_type' => TaxType::Gst->value,
                'invoice_date' => '2026-09-02',
            ],
            lines: $lines,
            actor: $actor,
        );

        $this->assertFalse((bool) $draft->prices_include_tax);
        $this->assertSame('1000.00', $draft->subtotal);
    }

    // ----------------------------------------------------------- words

    #[Test]
    public function amounts_are_spelled_out_in_the_indian_system(): void
    {
        // 2,91,245 is Two Lakh Ninety One Thousand, never Two Hundred Ninety
        // One Thousand.
        $this->assertSame(
            'INR Two Lakh Ninety One Thousand Two Hundred Forty Five Only',
            AmountInWords::rupees('291245.00'),
        );

        $this->assertSame(
            'INR Thirteen Thousand Eight Hundred Ninety Four and Fifty Six paise Only',
            AmountInWords::rupees('13894.56'),
        );

        $this->assertSame('INR One Crore Only', AmountInWords::rupees('10000000.00'));
        $this->assertSame('INR Zero Only', AmountInWords::rupees('0.00'));
    }

    // ------------------------------------------------------------- API

    #[Test]
    public function the_api_accepts_a_dealer_invoice_with_transport_and_charges(): void
    {
        Sanctum::actingAs($this->admin());

        $product = $this->scooter();
        $customer = Customer::factory()->create(['state_code' => '19']);

        $response = $this->postJson('/api/v1/invoices', [
            'customer_id' => $customer->id,
            'invoice_type' => 'dealer',
            'tax_type' => 'gst',
            'invoice_date' => '2026-09-02',
            'eway_bill_no' => '881735544731',
            'vehicle_no' => 'WB41T3727',
            'dispatched_through' => 'BY ROAD',
            'destination' => 'SANKARPUR',
            'lines' => [['product_id' => $product->id, 'quantity' => '6', 'unit_price' => '36000.00']],
            'charges' => [[
                'description' => 'Insurance Charges on Sales (18%)',
                'amount' => '207.86',
                'gst_rate' => '18.00',
                'hsn_code' => '997135',
            ]],
        ])->assertStatus(201);

        $response->assertJsonPath('data.invoice_type', 'dealer');
        $response->assertJsonPath('data.eway_bill_no', '881735544731');
        $response->assertJsonCount(1, 'data.charges');
        $response->assertJsonPath('data.charges.0.hsn_code', '997135');

        /*
         * The reference invoice's first line only (6 at 36,000 inclusive) plus
         * its insurance charge: 2,05,714.26 taxable + 207.86, tax 10,323.14,
         * rounded down by 0.26. The full two-line document is reproduced in
         * TaxInclusivePricingTest.
         */
        $response->assertJsonPath('data.taxable_amount', '205922.12');
        $response->assertJsonPath('data.grand_total', '216245.00');
    }

    #[Test]
    public function finalizing_over_http_does_not_re_apply_the_inclusive_division(): void
    {
        /*
         * The draft's composition already backed the GST out of the entered
         * price, so finalization must re-read the price AS ENTERED. Rebuilding
         * from the net price instead divides by (1 + rate) a second time and
         * silently under-bills -- 36,000 became 32,653.06 before this was
         * fixed. Exercised over HTTP because that is the path where the lines
         * are rebuilt from the stored items.
         */
        Sanctum::actingAs($this->admin());

        $product = $this->scooter();
        $customer = Customer::factory()->create(['state_code' => '19']);

        $draft = $this->postJson('/api/v1/invoices', [
            'customer_id' => $customer->id,
            'tax_type' => 'gst',
            'invoice_date' => '2026-09-02',
            'lines' => [['product_id' => $product->id, 'quantity' => '1', 'unit_price' => '36000.00']],
        ])->assertStatus(201)->json('data');

        $this->assertSame('34285.71', $draft['taxable_amount']);

        $finalized = $this->postJson("/api/v1/invoices/{$draft['id']}/finalize")
            ->assertOk()
            ->json('data');

        // Unchanged by finalization, and the entered MRP is what is charged.
        $this->assertSame('34285.71', $finalized['taxable_amount']);
        $this->assertSame('36000.00', $finalized['grand_total']);
        $this->assertSame('36000.00', $finalized['items'][0]['unit_price_gross']);
        $this->assertSame('34285.71', $finalized['items'][0]['unit_price']);
    }

    #[Test]
    public function an_unknown_invoice_type_is_rejected(): void
    {
        Sanctum::actingAs($this->admin());

        $product = $this->scooter();

        $this->postJson('/api/v1/invoices', [
            'customer_id' => Customer::factory()->create(['state_code' => '19'])->id,
            'invoice_type' => 'wholesaler',
            'tax_type' => 'gst',
            'invoice_date' => '2026-09-02',
            'lines' => [['product_id' => $product->id, 'quantity' => '1']],
        ])->assertStatus(422)->assertJsonValidationErrors('invoice_type');
    }

    #[Test]
    public function an_invoice_defaults_to_the_customer_type(): void
    {
        Sanctum::actingAs($this->admin());

        $product = $this->scooter();

        $this->postJson('/api/v1/invoices', [
            'customer_id' => Customer::factory()->create(['state_code' => '19'])->id,
            'tax_type' => 'gst',
            'invoice_date' => '2026-09-02',
            'lines' => [['product_id' => $product->id, 'quantity' => '1']],
        ])->assertStatus(201)->assertJsonPath('data.invoice_type', 'customer');
    }
}
