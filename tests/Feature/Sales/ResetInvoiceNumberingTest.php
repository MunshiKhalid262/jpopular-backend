<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Inventory\Services\StockLedger;
use App\Domain\Sales\Actions\DeleteInvoice;
use App\Domain\Sales\Actions\FinalizeInvoice;
use App\Domain\Sales\Actions\ManageInvoice;
use App\Domain\Sales\Data\InvoiceLineInput;
use App\Enums\StockMovementType;
use App\Models\BusinessSettings;
use App\Models\Customer;
use App\Models\DocumentSequence;
use App\Models\Invoice;
use App\Models\Product;
use PHPUnit\Framework\Attributes\Test;
use Tests\ApiTestCase;

/**
 * Rewinding the invoice counters, and the one case where that is unsafe.
 */
class ResetInvoiceNumberingTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        BusinessSettings::current()->forceFill([
            'state_code' => '19',
            'invoice_prefix' => 'JP',
            'dealer_invoice_prefix' => 'JD',
        ])->save();
        BusinessSettings::forgetCache();
    }

    private function finalizedInvoice(): Invoice
    {
        $actor = $this->admin();

        $product = Product::factory()->create([
            'gst_rate' => '5.00',
            'hsn_code' => '87116020',
            'current_stock' => '0.000',
        ]);

        app(StockLedger::class)->record([
            'product' => $product,
            'type' => StockMovementType::OpeningStock,
            'quantity' => '10.000',
            'reference_type' => 'Test',
            'reference_id' => (int) $product->getKey(),
            'user_id' => null,
            'note' => 'opening',
        ]);

        $customer = Customer::factory()->create(['state_code' => '19']);
        $lines = [new InvoiceLineInput($product->refresh(), '1', '36000.00')];

        $draft = app(ManageInvoice::class)->create(
            attributes: [
                'customer_id' => $customer->id,
                'invoice_type' => 'customer',
                'tax_type' => 'gst',
                'invoice_date' => '2026-09-22',
            ],
            lines: $lines,
            actor: $actor,
            charges: [],
        );

        return app(FinalizeInvoice::class)->handle($draft, $lines, $actor, '0', []);
    }

    #[Test]
    public function it_refuses_while_a_numbered_invoice_still_exists(): void
    {
        $this->finalizedInvoice();

        /*
         * The whole hazard: rewinding past a number still on a row would hand
         * it out twice, and invoice_number is unique -- so the failure would
         * surface later as a constraint error mid-finalize.
         */
        $this->artisan('invoices:reset-numbering', ['--force' => true])
            ->assertExitCode(1);

        $this->assertSame(1, DocumentSequence::count());
    }

    #[Test]
    public function it_clears_the_counters_once_nothing_holds_a_number(): void
    {
        $invoice = $this->finalizedInvoice();
        $this->assertSame('JP/2026-27/00001', $invoice->invoice_number);

        app(DeleteInvoice::class)->handle($invoice, $this->admin());

        $this->artisan('invoices:reset-numbering', ['--force' => true])
            ->assertExitCode(0);

        $this->assertSame(0, DocumentSequence::count());

        // And the series starts again from one.
        $this->assertSame('JP/2026-27/00001', $this->finalizedInvoice()->invoice_number);
    }

    #[Test]
    public function a_soft_deleted_numbered_invoice_still_blocks_it(): void
    {
        $invoice = $this->finalizedInvoice();

        // Soft delete leaves the row -- and its number -- in place.
        $invoice->delete();

        $this->artisan('invoices:reset-numbering', ['--force' => true])
            ->assertExitCode(1);
    }
}
