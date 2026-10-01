<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Sales\Actions\FinalizeInvoice;
use App\Domain\Sales\Actions\ManageInvoice;
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
 * The invoice PDF has to be small enough to send.
 *
 * dompdf subsets fonts by default; laravel-dompdf turns it back off, and with
 * it off all four DejaVu Sans faces are embedded whole -- 2.7 MB of font data
 * for an invoice that uses about a hundred characters. That shipped: a two-page
 * invoice weighed 1.6 MB, of which ~97% was glyphs nobody asked for.
 *
 * It matters because the invoice is sent, not just filed. A file that size is
 * slow over mobile data, and messaging and mail clients routinely decline to
 * generate a preview thumbnail for an attachment that big.
 */
class InvoicePdfSizeTest extends ApiTestCase
{
    /**
     * Generous: the real figure is around 45 KB. This is a guard against the
     * whole-font regression, not a budget to tune against.
     */
    private const MAX_BYTES = 300_000;

    private function invoice(): Invoice
    {
        BusinessSettings::current()->forceFill([
            'business_name' => 'J POPULAR AUTO',
            'gstin' => '19AMYPI5698G2Z0',
            'state' => 'West Bengal',
            'state_code' => '19',
            'dealer_invoice_prefix' => 'JD',
            'prices_include_tax' => true,
            'enable_round_off' => true,
        ])->save();
        BusinessSettings::forgetCache();

        $actor = $this->admin();

        $product = Product::factory()->create([
            'name' => 'YAKUZA NEU 60V',
            'gst_rate' => '5.00',
            'hsn_code' => '87116020',
            'current_stock' => '100.000',
            'unit' => 'NOS',
        ]);

        $customer = Customer::factory()->create([
            'name' => 'KHALID MOTORS',
            'state' => 'West Bengal',
            'state_code' => '19',
        ]);

        $lines = [new InvoiceLineInput($product, '5', '48000.00')];

        $draft = app(ManageInvoice::class)->create(
            attributes: [
                'customer_id' => $customer->id,
                'invoice_type' => InvoiceType::Dealer->value,
                'tax_type' => TaxType::Gst->value,
                'invoice_date' => '2026-09-22',
                'eway_bill_no' => '881735544731',
            ],
            lines: $lines,
            actor: $actor,
            charges: [],
        );

        return app(FinalizeInvoice::class)->handle($draft, $lines, $actor, '0', []);
    }

    #[Test]
    public function a_dealer_invoice_pdf_is_small_enough_to_send(): void
    {
        Sanctum::actingAs($this->admin());

        $pdf = $this->get("/api/v1/invoices/{$this->invoice()->id}/pdf")->assertOk()->getContent();

        $this->assertLessThan(
            self::MAX_BYTES,
            strlen($pdf),
            'The invoice PDF has grown past the point where clients will preview it. '.
            'Check that font subsetting is still on in InvoicePdfRenderer.',
        );
    }

    #[Test]
    public function the_pdf_is_still_a_valid_two_page_document(): void
    {
        Sanctum::actingAs($this->admin());

        $pdf = $this->get("/api/v1/invoices/{$this->invoice()->id}/pdf")->assertOk()->getContent();

        // Shrinking it must not have produced something a reader will refuse.
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringContainsString('%%EOF', substr($pdf, -64));
        $this->assertStringNotContainsString('/Encrypt', $pdf);

        // A dealer invoice is the document plus its e-Way Bill page.
        $pages = substr_count($pdf, '/Type /Page') - substr_count($pdf, '/Type /Pages');
        $this->assertSame(2, $pages);

        // Subsetted, but still embedded -- an unembedded font would leave the
        // rupee sign to whatever the reader happens to have installed.
        $this->assertGreaterThan(0, substr_count($pdf, '/FontFile'));
    }
}
