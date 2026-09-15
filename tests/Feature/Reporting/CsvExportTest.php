<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Domain\Payments\Actions\RecordPayment;
use App\Enums\PaymentMethod;
use App\Enums\TaxType;
use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use App\Support\BusinessPeriod;
use App\Support\CsvExport;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\ApiTestCase;
use Tests\Concerns\MakesInvoices;

class CsvExportTest extends ApiTestCase
{
    use MakesInvoices;

    private const EXPORTS = [
        'sales', 'gst', 'non-gst', 'payments', 'outstanding', 'inventory', 'stock-movements',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureBusiness();
    }

    private function csv(string $path): string
    {
        $response = $this->get($path);
        $response->assertOk();

        return $response->streamedContent();
    }

    /** Every place a stored export could plausibly land. */
    private function csvFilesOnDisk(): array
    {
        $found = [];

        foreach ([storage_path('app'), storage_path('app/public'), public_path()] as $root) {
            if (! File::isDirectory($root)) {
                continue;
            }

            foreach (File::allFiles($root) as $file) {
                if (strtolower($file->getExtension()) === 'csv') {
                    $found[] = $file->getPathname();
                }
            }
        }

        return $found;
    }

    // ---------------------------------------------------- the basics

    #[Test]
    public function a_sales_export_returns_csv_with_a_dated_filename(): void
    {
        Sanctum::actingAs($this->admin());
        $this->finalizedInvoice(['quantity' => '2']);

        $period = BusinessPeriod::thisMonth();
        $from = $period->startDate();
        $to = $period->endDate();

        $response = $this->get("/api/v1/reports/sales/export?date_from={$from}&date_to={$to}");

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString(
            "sales-report-{$from}-to-{$to}.csv",
            $response->headers->get('content-disposition'),
        );
    }

    #[Test]
    public function the_export_opens_cleanly_in_a_spreadsheet(): void
    {
        Sanctum::actingAs($this->admin());
        $this->finalizedInvoice(['quantity' => '1']);

        $csv = $this->csv('/api/v1/reports/sales/export');

        // UTF-8 BOM, without which Excel mis-decodes non-ASCII names.
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('Invoice No', $csv);
        $this->assertStringContainsString('Grand Total', $csv);
    }

    #[Test]
    public function every_report_exports(): void
    {
        Sanctum::actingAs($this->admin());
        $this->finalizedInvoice(['quantity' => '1']);
        Product::factory()->create();

        foreach (self::EXPORTS as $report) {
            $this->get("/api/v1/reports/{$report}/export")
                ->assertOk()
                ->assertHeader('content-type', 'text/csv; charset=UTF-8');
        }
    }

    // ------------------------------------------- filters are respected

    #[Test]
    public function the_export_contains_exactly_the_filtered_rows(): void
    {
        Sanctum::actingAs($this->admin());

        $today = BusinessPeriod::now();
        $old = $today->copy()->subMonths(3);

        $inRange = $this->finalizedInvoice(['quantity' => '1']);
        $outOfRange = $this->finalizedInvoice(['quantity' => '2', 'date' => $old->toDateString()]);

        $day = $today->toDateString();
        $csv = $this->csv("/api/v1/reports/sales/export?date_from={$day}&date_to={$day}");

        $this->assertStringContainsString($inRange->invoice_number, $csv);
        $this->assertStringNotContainsString($outOfRange->invoice_number, $csv);
    }

    #[Test]
    public function a_gst_export_excludes_non_gst_invoices(): void
    {
        Sanctum::actingAs($this->admin());

        $gst = $this->finalizedInvoice(['quantity' => '1']);
        $nonGst = $this->finalizedInvoice(['quantity' => '1', 'tax_type' => TaxType::NonGst]);

        $csv = $this->csv('/api/v1/reports/gst/export');

        $this->assertStringContainsString($gst->invoice_number, $csv);
        $this->assertStringNotContainsString($nonGst->invoice_number, $csv);
        $this->assertStringContainsString('CGST', $csv);
    }

    #[Test]
    public function a_payment_export_respects_the_method_filter(): void
    {
        $actor = $this->admin();
        Sanctum::actingAs($actor);

        $invoice = $this->finalizedInvoice(['quantity' => '3', 'actor' => $actor]);
        app(RecordPayment::class)->handle($invoice, ['amount' => '100.00', 'payment_method' => PaymentMethod::Cash, 'reference' => 'CASH-REF'], $actor);
        app(RecordPayment::class)->handle($invoice->fresh(), ['amount' => '200.00', 'payment_method' => PaymentMethod::Card, 'reference' => 'CARD-REF'], $actor);

        $csv = $this->csv('/api/v1/reports/payments/export?payment_method=card');

        $this->assertStringContainsString('CARD-REF', $csv);
        $this->assertStringNotContainsString('CASH-REF', $csv);
    }

    #[Test]
    public function an_inventory_export_respects_the_status_filter(): void
    {
        Sanctum::actingAs($this->admin());

        Product::factory()->create(['sku' => 'LOWSKU', 'current_stock' => '1.000', 'min_stock_level' => '5.000']);
        Product::factory()->create(['sku' => 'FINESKU', 'current_stock' => '90.000', 'min_stock_level' => '5.000']);

        $csv = $this->csv('/api/v1/reports/inventory/export?stock_status=low_stock');

        $this->assertStringContainsString('LOWSKU', $csv);
        $this->assertStringNotContainsString('FINESKU', $csv);
    }

    // ------------------------------------------------ formula injection

    #[Test]
    public function the_escaper_neutralises_formula_characters(): void
    {
        foreach (['=', '+', '-', '@'] as $prefix) {
            $this->assertSame(
                "'{$prefix}danger",
                CsvExport::escape("{$prefix}danger"),
                "a value starting with {$prefix} must not stay executable",
            );
        }

        // Ordinary text is untouched.
        $this->assertSame('Ravi Kumar', CsvExport::escape('Ravi Kumar'));
        $this->assertSame('', CsvExport::escape(null));
        $this->assertSame('1180.00', CsvExport::escape('1180.00'));
    }

    #[Test]
    public function a_malicious_customer_name_is_not_executable_in_the_export(): void
    {
        Sanctum::actingAs($this->admin());

        $attack = '=cmd|\' /C calc\'!A0';
        $customer = Customer::factory()->create(['name' => $attack]);
        $this->finalizedInvoice(['quantity' => '1', 'customer' => $customer]);

        $csv = $this->csv('/api/v1/reports/sales/export');

        // The name is present, but quoted so a spreadsheet treats it as text.
        $this->assertStringContainsString("'=cmd", $csv);
        // It must never appear as a bare formula at the start of a field.
        $this->assertStringNotContainsString(',=cmd', $csv);
    }

    #[Test]
    public function the_download_filename_cannot_carry_a_path(): void
    {
        $this->assertSame('sales-report.csv', CsvExport::filename('sales-report'));
        $this->assertSame('etc-passwd.csv', CsvExport::filename('../../etc/passwd'));
        $this->assertSame('a-b.csv', CsvExport::filename('a/../b'));
        $this->assertSame('export.csv', CsvExport::filename('///'));
    }

    // ------------------------------------------------------ no storage

    #[Test]
    public function exporting_never_writes_a_file(): void
    {
        Sanctum::actingAs($this->admin());
        $this->finalizedInvoice(['quantity' => '1']);
        Product::factory()->create();

        $before = $this->csvFilesOnDisk();

        // Every export, twice, in case a naive implementation only wrote once.
        foreach (self::EXPORTS as $report) {
            $this->csv("/api/v1/reports/{$report}/export");
            $this->csv("/api/v1/reports/{$report}/export");
        }

        $this->assertSame(
            $before,
            $this->csvFilesOnDisk(),
            'no CSV may be written to storage or public',
        );
    }

    // ---------------------------------------------------- permissions

    #[Test]
    public function an_unauthenticated_export_is_rejected(): void
    {
        foreach (self::EXPORTS as $report) {
            $this->getJson("/api/v1/reports/{$report}/export")->assertStatus(401);
        }
    }

    #[Test]
    public function an_export_url_cannot_bypass_the_reports_permission(): void
    {
        Sanctum::actingAs(User::factory()->create());

        foreach (self::EXPORTS as $report) {
            $this->getJson("/api/v1/reports/{$report}/export")->assertStatus(403);
        }
    }

    #[Test]
    public function a_manager_cannot_export_even_reports_they_may_read(): void
    {
        // The manager holds reports.sales but not reports.export, so reading
        // on screen is allowed while bulk extraction is not.
        Sanctum::actingAs($this->manager());

        $this->getJson('/api/v1/reports/sales')->assertOk();
        $this->getJson('/api/v1/reports/sales/export')->assertStatus(403);
        $this->getJson('/api/v1/reports/inventory/export')->assertStatus(403);

        // And a financial export stays refused on both counts.
        $this->getJson('/api/v1/reports/gst/export')->assertStatus(403);
    }
}
