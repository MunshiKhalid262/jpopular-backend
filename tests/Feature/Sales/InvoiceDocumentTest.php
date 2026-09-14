<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Sales\Actions\CancelInvoice;
use App\Domain\Sales\Actions\FinalizeInvoice;
use App\Domain\Sales\Actions\ManageInvoice;
use App\Domain\Sales\Data\InvoiceLineInput;
use App\Enums\TaxType;
use App\Models\BusinessSettings;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\ApiTestCase;

/**
 * Preview, download and print.
 *
 * The central guarantee under test is that NOTHING IS STORED: the PDF is a
 * projection of the invoice rows, generated per request and discarded.
 */
class InvoiceDocumentTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        BusinessSettings::current()->forceFill([
            'business_name' => 'JPopular Motors',
            'gstin' => '32ABCDE1234F1Z5',
            'state' => 'Kerala',
            'state_code' => '32',
            'invoice_prefix' => 'JP',
            'financial_year_start_month' => 4,
            'enable_round_off' => false,
            'address_line1' => '12 Market Road',
            'city' => 'Kochi',
        ])->save();
        BusinessSettings::forgetCache();
    }

    private function product(array $attributes = []): Product
    {
        return Product::factory()->create($attributes + [
            'current_stock' => '100.000',
            'selling_price' => '1000.00',
            'gst_rate' => '18.00',
            'hsn_code' => '87116020',
        ]);
    }

    /** @return array{0: Invoice, 1: Product} */
    private function finalizedInvoice(array $options = []): array
    {
        $actor = $options['actor'] ?? User::factory()->create();
        $product = $options['product'] ?? $this->product();
        $customer = array_key_exists('customer', $options)
            ? $options['customer']
            : Customer::factory()->registered()->create();

        $lines = [new InvoiceLineInput(
            product: $product,
            quantity: $options['quantity'] ?? '2',
            unitPrice: (string) $product->selling_price,
        )];

        $draft = app(ManageInvoice::class)->create(
            attributes: [
                'customer_id' => $customer?->getKey(),
                'tax_type' => $options['tax_type'] ?? TaxType::Gst,
                'invoice_date' => '2026-09-14',
            ],
            lines: $lines,
            actor: $actor,
        );

        $invoice = app(FinalizeInvoice::class)->handle($draft, $lines, $actor);

        return [$invoice, $product];
    }

    /** Every place a stored PDF could plausibly land. */
    private function pdfFilesOnDisk(): array
    {
        $found = [];

        foreach ([storage_path('app'), storage_path('app/public'), public_path()] as $root) {
            if (! File::isDirectory($root)) {
                continue;
            }

            foreach (File::allFiles($root) as $file) {
                if (strtolower($file->getExtension()) === 'pdf') {
                    $found[] = $file->getPathname();
                }
            }
        }

        return $found;
    }

    // ----------------------------------------------------------- download

    #[Test]
    public function a_gst_invoice_downloads_as_a_pdf(): void
    {
        Sanctum::actingAs($this->admin());
        [$invoice] = $this->finalizedInvoice();

        $response = $this->get("/api/v1/invoices/{$invoice->id}/pdf");

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');

        // Deliberately NOT a streamed response: the PDF is built in memory and
        // handed over whole, so there is no file or stream behind it.
        $body = $response->getContent();
        $this->assertStringStartsWith('%PDF-', $body, 'the body must be a real PDF');
        $this->assertGreaterThan(1000, strlen($body));
    }

    #[Test]
    public function a_non_gst_invoice_downloads_as_a_pdf(): void
    {
        Sanctum::actingAs($this->admin());
        [$invoice] = $this->finalizedInvoice(['tax_type' => TaxType::NonGst]);

        $response = $this->get("/api/v1/invoices/{$invoice->id}/pdf");

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    #[Test]
    public function the_download_filename_is_derived_from_the_invoice_number(): void
    {
        Sanctum::actingAs($this->admin());
        [$invoice] = $this->finalizedInvoice();

        $this->assertSame('JP/2026-27/00001', $invoice->invoice_number);

        $response = $this->get("/api/v1/invoices/{$invoice->id}/pdf");

        // The slashes in the number would read as path separators, so they are
        // replaced rather than escaped.
        $response->assertHeader(
            'content-disposition',
            'attachment; filename="invoice-JP-2026-27-00001.pdf"',
        );
    }

    #[Test]
    public function the_inline_pdf_is_served_for_viewing_rather_than_download(): void
    {
        Sanctum::actingAs($this->admin());
        [$invoice] = $this->finalizedInvoice();

        $this->get("/api/v1/invoices/{$invoice->id}/pdf/inline")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('content-disposition', 'inline; filename="invoice-JP-2026-27-00001.pdf"');
    }

    // ------------------------------------------------------------ preview

    #[Test]
    public function a_gst_invoice_preview_shows_the_gst_presentation(): void
    {
        Sanctum::actingAs($this->admin());
        [$invoice] = $this->finalizedInvoice();

        $response = $this->get("/api/v1/invoices/{$invoice->id}/preview")->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('Tax Invoice', $html);
        $this->assertStringContainsString('JP/2026-27/00001', $html);
        $this->assertStringContainsString('HSN', $html);
        $this->assertStringContainsString('CGST', $html);
        $this->assertStringContainsString('SGST', $html);
        $this->assertStringContainsString('Taxable', $html);
        // Intra-state, so IGST must not appear as a column or total.
        $this->assertStringNotContainsString('IGST', $html);
    }

    #[Test]
    public function an_inter_state_preview_shows_igst_instead_of_cgst_and_sgst(): void
    {
        Sanctum::actingAs($this->admin());
        [$invoice] = $this->finalizedInvoice([
            'customer' => Customer::factory()->interState()->registered()->create(),
        ]);

        $html = $this->get("/api/v1/invoices/{$invoice->id}/preview")->assertOk()->getContent();

        $this->assertStringContainsString('IGST', $html);
        $this->assertStringNotContainsString('CGST', $html);
        $this->assertStringNotContainsString('SGST', $html);
    }

    #[Test]
    public function a_non_gst_preview_shows_no_gst_columns_or_totals(): void
    {
        Sanctum::actingAs($this->admin());
        [$invoice] = $this->finalizedInvoice(['tax_type' => TaxType::NonGst]);

        $html = $this->get("/api/v1/invoices/{$invoice->id}/preview")->assertOk()->getContent();

        // "Tax Invoice" is a GST term of art and must not head a non-GST bill.
        $this->assertStringNotContainsString('Tax Invoice', $html);
        $this->assertStringContainsString('Invoice', $html);

        $this->assertStringNotContainsString('CGST', $html);
        $this->assertStringNotContainsString('SGST', $html);
        $this->assertStringNotContainsString('IGST', $html);
        $this->assertStringNotContainsString('Taxable value', $html);

        // What a non-GST bill must still show.
        $this->assertStringContainsString('Subtotal', $html);
        $this->assertStringContainsString('Grand total', $html);
        $this->assertStringContainsString('Paid', $html);
        $this->assertStringContainsString('Balance due', $html);
    }

    #[Test]
    public function the_print_view_renders_the_same_document(): void
    {
        Sanctum::actingAs($this->admin());
        [$invoice] = $this->finalizedInvoice();

        $html = $this->get("/api/v1/invoices/{$invoice->id}/preview?media=print")
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('JP/2026-27/00001', $html);
        // The print rule set that hides host-page chrome.
        $this->assertStringContainsString('@media print', $html);
        $this->assertStringContainsString('.no-print', $html);
    }

    // ---------------------------------------------------------- cancelled

    #[Test]
    public function a_cancelled_invoice_is_still_available_and_marked_cancelled(): void
    {
        $actor = $this->admin();
        Sanctum::actingAs($actor);

        [$invoice] = $this->finalizedInvoice(['actor' => $actor]);
        app(CancelInvoice::class)->handle($invoice, 'Duplicate billing', $actor);

        $html = $this->get("/api/v1/invoices/{$invoice->id}/preview")->assertOk()->getContent();

        $this->assertStringContainsString('CANCELLED', $html);
        $this->assertStringContainsString('Duplicate billing', $html);
        // The number survives cancellation.
        $this->assertStringContainsString('JP/2026-27/00001', $html);

        $this->get("/api/v1/invoices/{$invoice->id}/pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    // ------------------------------------------------- historical accuracy

    #[Test]
    public function changing_a_product_does_not_change_a_historical_document(): void
    {
        Sanctum::actingAs($this->admin());

        $product = $this->product(['name' => 'Original Scooter', 'sku' => 'ORIG-1']);
        [$invoice] = $this->finalizedInvoice(['product' => $product]);

        $before = $this->get("/api/v1/invoices/{$invoice->id}/preview")->getContent();
        $this->assertStringContainsString('Original Scooter', $before);
        $this->assertStringContainsString('ORIG-1', $before);

        // The catalog moves on.
        $product->forceFill([
            'name' => 'Renamed Scooter',
            'sku' => 'NEW-9',
            'selling_price' => '9999.00',
            'gst_rate' => '28.00',
            'hsn_code' => '11111111',
        ])->save();

        $after = $this->get("/api/v1/invoices/{$invoice->id}/preview")->getContent();

        $this->assertStringContainsString('Original Scooter', $after);
        $this->assertStringContainsString('ORIG-1', $after);
        $this->assertStringNotContainsString('Renamed Scooter', $after);
        $this->assertStringNotContainsString('NEW-9', $after);
        $this->assertStringNotContainsString('9,999.00', $after);
    }

    // --------------------------------------------------------- no storage

    #[Test]
    public function generating_a_pdf_never_writes_a_file(): void
    {
        Sanctum::actingAs($this->admin());
        [$invoice] = $this->finalizedInvoice();

        $before = $this->pdfFilesOnDisk();

        // Several requests, in case a naive implementation only wrote once.
        $this->get("/api/v1/invoices/{$invoice->id}/pdf")->assertOk();
        $this->get("/api/v1/invoices/{$invoice->id}/pdf")->assertOk();
        $this->get("/api/v1/invoices/{$invoice->id}/pdf/inline")->assertOk();
        $this->get("/api/v1/invoices/{$invoice->id}/preview")->assertOk();

        $this->assertSame(
            $before,
            $this->pdfFilesOnDisk(),
            'no PDF file may be written to storage or public',
        );
    }

    #[Test]
    public function no_pdf_path_is_persisted_on_the_invoice(): void
    {
        Sanctum::actingAs($this->admin());
        [$invoice] = $this->finalizedInvoice();

        $this->get("/api/v1/invoices/{$invoice->id}/pdf")->assertOk();

        // Nothing on the row may point at a generated document.
        $columns = array_keys($invoice->fresh()->getAttributes());

        foreach ($columns as $column) {
            $this->assertStringNotContainsStringIgnoringCase('pdf', $column);
        }
    }

    // --------------------------------------------------------- permissions

    #[Test]
    public function an_unauthenticated_request_is_rejected(): void
    {
        [$invoice] = $this->finalizedInvoice();

        $this->getJson("/api/v1/invoices/{$invoice->id}/pdf")->assertStatus(401);
        $this->getJson("/api/v1/invoices/{$invoice->id}/preview")->assertStatus(401);
    }

    #[Test]
    public function a_user_without_print_permission_is_refused(): void
    {
        [$invoice] = $this->finalizedInvoice();

        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/v1/invoices/{$invoice->id}/pdf")->assertStatus(403);
        $this->getJson("/api/v1/invoices/{$invoice->id}/pdf/inline")->assertStatus(403);
        $this->getJson("/api/v1/invoices/{$invoice->id}/preview")->assertStatus(403);
    }

    #[Test]
    public function a_missing_invoice_returns_not_found_rather_than_leaking(): void
    {
        Sanctum::actingAs($this->admin());

        $response = $this->getJson('/api/v1/invoices/999999/pdf')->assertStatus(404);

        // No filesystem path or internal detail in the body.
        $this->assertStringNotContainsString('/var/www', $response->getContent());
        $this->assertStringNotContainsString('storage/app', $response->getContent());
    }
}
