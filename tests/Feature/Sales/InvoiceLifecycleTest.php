<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Sales\Actions\CancelInvoice;
use App\Domain\Sales\Actions\FinalizeInvoice;
use App\Domain\Sales\Actions\ManageInvoice;
use App\Domain\Sales\Data\InvoiceLineInput;
use App\Domain\Sales\Exceptions\InvoiceStateException;
use App\Enums\InvoiceStatus;
use App\Enums\StockMovementType;
use App\Enums\SupplyType;
use App\Enums\TaxType;
use App\Models\BusinessSettings;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InvoiceLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actor = User::factory()->create();

        // The seller must have a state code before any GST invoice can be
        // raised; SupplyTypeResolver refuses otherwise.
        BusinessSettings::current()->forceFill([
            'business_name' => 'JPopular Test',
            'state_code' => '32',
            'invoice_prefix' => 'JP',
            'financial_year_start_month' => 4,
            'enable_round_off' => false,
        ])->save();
        BusinessSettings::forgetCache();
    }

    private function product(string $stock = '100.000', string $price = '1000.00', string $gst = '18.00'): Product
    {
        return Product::factory()->create([
            'current_stock' => $stock,
            'selling_price' => $price,
            'gst_rate' => $gst,
            'hsn_code' => '87116020',
        ]);
    }

    /** @param array<int, array{0: Product, 1: string}> $spec */
    private function lines(array $spec): array
    {
        $lines = [];

        foreach ($spec as $i => [$product, $quantity]) {
            $lines[] = new InvoiceLineInput(
                product: $product,
                quantity: $quantity,
                unitPrice: (string) $product->selling_price,
                sortOrder: $i,
            );
        }

        return $lines;
    }

    private function draft(array $lines, array $attributes = []): Invoice
    {
        $customer = $attributes['customer'] ?? Customer::factory()->create();

        return app(ManageInvoice::class)->create(
            attributes: [
                'customer_id' => $customer?->getKey(),
                'tax_type' => $attributes['tax_type'] ?? TaxType::Gst,
                'invoice_date' => '2026-09-14',
            ],
            lines: $lines,
            actor: $this->actor,
            invoiceDiscount: $attributes['discount'] ?? '0',
        );
    }

    private function finalize(Invoice $invoice, array $lines, string $discount = '0'): Invoice
    {
        return app(FinalizeInvoice::class)->handle($invoice, $lines, $this->actor, $discount);
    }

    // ------------------------------------------------------------- drafts

    #[Test]
    public function a_draft_computes_totals_without_touching_stock_or_a_number(): void
    {
        $product = $this->product();
        $lines = $this->lines([[$product, '2']]);

        $invoice = $this->draft($lines);

        $this->assertSame(InvoiceStatus::Draft, $invoice->status);
        $this->assertNull($invoice->invoice_number);

        // 2 x 1000 = 2000, +18% GST = 2360
        $this->assertSame('2000.00', $invoice->subtotal);
        $this->assertSame('2360.00', $invoice->grand_total);

        // Nothing has left the shelf yet.
        $this->assertSame('100.000', $product->fresh()->current_stock);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    #[Test]
    public function editing_a_draft_replaces_its_lines(): void
    {
        $product = $this->product();
        $invoice = $this->draft($this->lines([[$product, '2']]));

        app(ManageInvoice::class)->update(
            $invoice,
            ['invoice_date' => '2026-09-14'],
            $this->lines([[$product, '5']]),
        );

        $this->assertCount(1, $invoice->fresh()->items);
        $this->assertSame('5.000', $invoice->fresh()->items->first()->quantity);
        $this->assertSame('5900.00', $invoice->fresh()->grand_total);
    }

    // ---------------------------------------------------------- finalizing

    #[Test]
    public function finalizing_allocates_a_number_and_deducts_stock(): void
    {
        $product = $this->product('10.000');
        $lines = $this->lines([[$product, '3']]);

        $invoice = $this->finalize($this->draft($lines), $lines);

        $this->assertSame(InvoiceStatus::Finalized, $invoice->status);
        $this->assertSame('JP/2026-27/00001', $invoice->invoice_number);
        $this->assertSame('2026-27', $invoice->financial_year);
        $this->assertNotNull($invoice->finalized_at);

        $this->assertSame('7.000', $product->fresh()->current_stock);

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'type' => StockMovementType::InvoiceSale->value,
            'reference_type' => 'InvoiceItem',
            'quantity' => -3,
        ]);
    }

    #[Test]
    public function invoice_numbers_are_sequential(): void
    {
        $product = $this->product('100.000');

        $numbers = [];

        for ($i = 0; $i < 3; $i++) {
            $lines = $this->lines([[$product, '1']]);
            $numbers[] = $this->finalize($this->draft($lines), $lines)->invoice_number;
        }

        $this->assertSame(
            ['JP/2026-27/00001', 'JP/2026-27/00002', 'JP/2026-27/00003'],
            $numbers,
        );
    }

    #[Test]
    public function finalizing_is_refused_when_stock_is_insufficient(): void
    {
        $product = $this->product('2.000');
        $lines = $this->lines([[$product, '5']]);
        $invoice = $this->draft($lines);

        $this->expectException(InsufficientStockException::class);

        try {
            $this->finalize($invoice, $lines);
        } finally {
            // The whole finalization rolls back: no number is consumed and the
            // invoice stays a draft.
            $this->assertSame('2.000', $product->fresh()->current_stock);
            $this->assertSame(InvoiceStatus::Draft, $invoice->fresh()->status);
            $this->assertNull($invoice->fresh()->invoice_number);
            $this->assertDatabaseCount('stock_movements', 0);
        }
    }

    #[Test]
    public function a_finalized_invoice_cannot_be_finalized_again(): void
    {
        $product = $this->product();
        $lines = $this->lines([[$product, '1']]);
        $invoice = $this->finalize($this->draft($lines), $lines);

        $this->expectException(InvoiceStateException::class);

        $this->finalize($invoice, $lines);
    }

    #[Test]
    public function a_finalized_invoice_cannot_be_edited_or_deleted(): void
    {
        $product = $this->product();
        $lines = $this->lines([[$product, '1']]);
        $invoice = $this->finalize($this->draft($lines), $lines);

        try {
            app(ManageInvoice::class)->update($invoice, [], $lines);
            $this->fail('editing a finalized invoice should be refused');
        } catch (InvoiceStateException $e) {
            $this->assertSame('INVOICE_NOT_DRAFT', $e->errorCode);
        }

        try {
            app(ManageInvoice::class)->delete($invoice);
            $this->fail('deleting a finalized invoice should be refused');
        } catch (InvoiceStateException $e) {
            $this->assertSame('INVOICE_CANNOT_BE_DELETED', $e->errorCode);
        }
    }

    #[Test]
    public function the_same_product_may_appear_on_two_lines(): void
    {
        // Movements reference the invoice ITEM, not the invoice, so two lines
        // for one product do not collide on the idempotency index.
        $product = $this->product('10.000');
        $lines = $this->lines([[$product, '2'], [$product, '3']]);

        $invoice = $this->finalize($this->draft($lines), $lines);

        $this->assertCount(2, $invoice->items);
        $this->assertSame('5.000', $product->fresh()->current_stock);
        $this->assertDatabaseCount('stock_movements', 2);
    }

    // ------------------------------------------------------------- GST

    #[Test]
    public function an_intra_state_sale_splits_into_cgst_and_sgst(): void
    {
        $product = $this->product(price: '1000.00', gst: '18.00');
        $lines = $this->lines([[$product, '1']]);

        // Customer state 32 matches the seller's 32.
        $invoice = $this->finalize($this->draft($lines), $lines);

        $this->assertSame(SupplyType::IntraState, $invoice->supply_type);
        $this->assertSame('90.00', $invoice->cgst_amount);
        $this->assertSame('90.00', $invoice->sgst_amount);
        $this->assertSame('0.00', $invoice->igst_amount);
        $this->assertSame('1180.00', $invoice->grand_total);
    }

    #[Test]
    public function an_inter_state_sale_charges_igst(): void
    {
        $product = $this->product(price: '1000.00', gst: '18.00');
        $customer = Customer::factory()->interState()->create();
        $lines = $this->lines([[$product, '1']]);

        $invoice = $this->finalize(
            $this->draft($lines, ['customer' => $customer]),
            $lines,
        );

        $this->assertSame(SupplyType::InterState, $invoice->supply_type);
        $this->assertSame('180.00', $invoice->igst_amount);
        $this->assertSame('0.00', $invoice->cgst_amount);
        $this->assertSame('1180.00', $invoice->grand_total);
    }

    #[Test]
    public function a_non_gst_invoice_charges_no_tax_at_all(): void
    {
        $product = $this->product(price: '1000.00', gst: '18.00');
        $lines = $this->lines([[$product, '2']]);

        $invoice = $this->finalize(
            $this->draft($lines, ['tax_type' => TaxType::NonGst]),
            $lines,
        );

        $this->assertSame(TaxType::NonGst, $invoice->tax_type);
        $this->assertNull($invoice->supply_type);
        $this->assertSame('0.00', $invoice->total_tax);
        $this->assertSame('2000.00', $invoice->grand_total);

        // The product's rate is still snapshotted, recording what it WAS,
        // but it reached no total.
        $this->assertSame('18.00', $invoice->items->first()->gst_rate);
        $this->assertSame('0.00', $invoice->items->first()->tax_amount);
    }

    // --------------------------------------------------------- cancelling

    #[Test]
    public function cancelling_restores_stock_and_keeps_the_number(): void
    {
        $product = $this->product('10.000');
        $lines = $this->lines([[$product, '4']]);
        $invoice = $this->finalize($this->draft($lines), $lines);

        $this->assertSame('6.000', $product->fresh()->current_stock);

        $cancelled = app(CancelInvoice::class)->handle($invoice, 'Customer returned goods', $this->actor);

        $this->assertSame(InvoiceStatus::Cancelled, $cancelled->status);
        $this->assertSame('Customer returned goods', $cancelled->cancellation_reason);
        $this->assertNotNull($cancelled->cancelled_at);

        // The number survives: a gap in the sequence is itself an audit
        // question.
        $this->assertSame('JP/2026-27/00001', $cancelled->invoice_number);

        $this->assertSame('10.000', $product->fresh()->current_stock);
        $this->assertDatabaseHas('stock_movements', [
            'type' => StockMovementType::InvoiceCancel->value,
            'quantity' => 4,
        ]);
    }

    #[Test]
    public function an_invoice_cannot_be_cancelled_twice(): void
    {
        $product = $this->product('10.000');
        $lines = $this->lines([[$product, '2']]);
        $invoice = $this->finalize($this->draft($lines), $lines);

        app(CancelInvoice::class)->handle($invoice, 'First', $this->actor);

        $this->expectException(InvoiceStateException::class);

        app(CancelInvoice::class)->handle($invoice->fresh(), 'Second', $this->actor);
    }

    #[Test]
    public function a_draft_cannot_be_cancelled(): void
    {
        $lines = $this->lines([[$this->product(), '1']]);

        $this->expectException(InvoiceStateException::class);

        app(CancelInvoice::class)->handle($this->draft($lines), 'nope', $this->actor);
    }

    // ------------------------------------------------- historical accuracy

    #[Test]
    public function changing_a_product_never_changes_a_finalized_invoice(): void
    {
        $product = $this->product(price: '1000.00', gst: '18.00');
        $product->forceFill(['name' => 'Original Name', 'sku' => 'ORIG-1', 'hsn_code' => '87116020'])->save();

        $lines = $this->lines([[$product->fresh(), '1']]);
        $invoice = $this->finalize($this->draft($lines), $lines);

        // The catalog moves on: renamed, repriced, re-rated, re-coded.
        $product->forceFill([
            'name' => 'Renamed Later',
            'sku' => 'NEW-99',
            'selling_price' => '5000.00',
            'gst_rate' => '28.00',
            'hsn_code' => '99999999',
        ])->save();

        $item = $invoice->fresh()->items->first();

        $this->assertSame('Original Name', $item->product_name);
        $this->assertSame('ORIG-1', $item->sku);
        $this->assertSame('1000.00', $item->unit_price);
        $this->assertSame('18.00', $item->gst_rate);
        $this->assertSame('87116020', $item->hsn_code);
        $this->assertSame('1180.00', $invoice->fresh()->grand_total);
    }

    #[Test]
    public function an_archived_product_still_appears_on_its_historical_invoice(): void
    {
        $product = $this->product('10.000');
        $lines = $this->lines([[$product, '1']]);
        $invoice = $this->finalize($this->draft($lines), $lines);

        $name = $product->name;
        $product->delete();

        $item = $invoice->fresh()->items->first();

        $this->assertSame($name, $item->product_name);
        $this->assertSoftDeleted('products', ['id' => $product->id]);
    }
}
