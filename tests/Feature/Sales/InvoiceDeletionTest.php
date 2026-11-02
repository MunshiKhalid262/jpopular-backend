<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Inventory\Services\StockLedger;
use App\Domain\Sales\Actions\CancelInvoice;
use App\Domain\Sales\Actions\FinalizeInvoice;
use App\Domain\Sales\Actions\ManageInvoice;
use App\Domain\Sales\Data\InvoiceLineInput;
use App\Enums\StockMovementType;
use App\Models\BusinessSettings;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\ApiTestCase;

/**
 * Deleting an invoice of any status, and the stock that goes with it.
 *
 * `products.current_stock` is a cache of SUM(stock_movements.quantity), so the
 * question each test really asks is: after the invoice and its ledger rows are
 * gone, does the cached figure still match the ledger?
 */
class InvoiceDeletionTest extends ApiTestCase
{
    private const OPENING = '10.000';

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

    /**
     * A product with real opening stock, booked through the ledger.
     *
     * Setting `current_stock` on the row directly would be drift: the column
     * is a cache of SUM(stock_movements.quantity), and the app only ever moves
     * it through the ledger. A fixture that cheats would make these tests pass
     * against behaviour production never has.
     */
    private function product(): Product
    {
        $product = Product::factory()->create([
            'name' => 'YAKUZA NEU 60V',
            'gst_rate' => '5.00',
            'hsn_code' => '87116020',
        ]);

        app(StockLedger::class)->record([
            'product' => $product,
            'type' => StockMovementType::OpeningStock,
            'quantity' => self::OPENING,
            'reference_type' => 'Test',
            'reference_id' => (int) $product->getKey(),
            'user_id' => null,
            'note' => 'Opening stock for the test',
        ]);

        return $product->refresh();
    }

    private function draft(Product $product, User $actor): Invoice
    {
        $customer = Customer::factory()->create([
            'state' => 'West Bengal',
            'state_code' => '19',
        ]);

        return app(ManageInvoice::class)->create(
            attributes: [
                'customer_id' => $customer->id,
                'invoice_type' => 'customer',
                'tax_type' => 'gst',
                'invoice_date' => '2026-09-22',
            ],
            lines: [new InvoiceLineInput($product, '3', '36000.00')],
            actor: $actor,
            charges: [],
        );
    }

    private function finalized(Product $product, User $actor): Invoice
    {
        $draft = $this->draft($product, $actor);

        return app(FinalizeInvoice::class)->handle(
            $draft,
            [new InvoiceLineInput($product, '3', '36000.00')],
            $actor,
            '0',
            [],
        );
    }

    /** The cached balance, and the ledger it is meant to mirror. */
    private function stock(Product $product): array
    {
        return [
            'cached' => $product->fresh()->current_stock,
            'ledger' => (string) (StockMovement::where('product_id', $product->id)->sum('quantity') ?: '0'),
        ];
    }

    // ------------------------------------------------------------------

    #[Test]
    public function deleting_a_draft_leaves_stock_untouched(): void
    {
        $actor = $this->admin();
        Sanctum::actingAs($actor);

        $product = $this->product();
        $invoice = $this->draft($product, $actor);

        // A draft never moved stock, so there is nothing to give back.
        $this->deleteJson("/api/v1/invoices/{$invoice->id}")->assertOk();

        $this->assertSame('10.000', $product->fresh()->current_stock);
        $this->assertDatabaseMissing('invoices', ['id' => $invoice->id]);
    }

    #[Test]
    public function deleting_a_finalized_invoice_returns_its_stock(): void
    {
        $actor = $this->admin();
        Sanctum::actingAs($actor);

        $product = $this->product();
        $invoice = $this->finalized($product, $actor);

        // Three went out on the invoice.
        $this->assertSame('7.000', $product->fresh()->current_stock);

        $this->deleteJson("/api/v1/invoices/{$invoice->id}")->assertOk();

        // ...and come back when it is deleted.
        ['cached' => $cached, 'ledger' => $ledger] = $this->stock($product);

        $this->assertSame('10.000', $cached);
        $this->assertSame(0, bccomp($cached, $ledger, 3), 'cached stock must still equal the ledger');
    }

    #[Test]
    public function deleting_a_cancelled_invoice_does_not_return_the_stock_twice(): void
    {
        $actor = $this->admin();
        Sanctum::actingAs($actor);

        $product = $this->product();
        $invoice = $this->finalized($product, $actor);

        app(CancelInvoice::class)->handle($invoice, 'Keyed in error', $actor);

        // Cancelling already gave the three back.
        $this->assertSame('10.000', $product->fresh()->current_stock);

        $this->deleteJson("/api/v1/invoices/{$invoice->id}")->assertOk();

        /*
         * The sale and its reversal net to zero, so removing BOTH must leave
         * stock where it was. Adding the quantity back per deleted sale row
         * would have produced 13 here -- stock the shop never had.
         */
        ['cached' => $cached, 'ledger' => $ledger] = $this->stock($product);

        $this->assertSame('10.000', $cached);
        $this->assertSame(0, bccomp($cached, $ledger, 3), 'cached stock must still equal the ledger');
    }

    #[Test]
    public function deleting_an_invoice_removes_its_movement_history(): void
    {
        $actor = $this->admin();
        Sanctum::actingAs($actor);

        $product = $this->product();
        $invoice = $this->finalized($product, $actor);

        $itemIds = InvoiceItem::where('invoice_id', $invoice->id)->pluck('id');
        $this->assertGreaterThan(0, StockMovement::whereIn('reference_id', $itemIds)
            ->where('reference_type', 'InvoiceItem')->count());

        $this->deleteJson("/api/v1/invoices/{$invoice->id}")->assertOk();

        $this->assertSame(0, StockMovement::whereIn('reference_id', $itemIds)
            ->where('reference_type', 'InvoiceItem')->count());
    }

    #[Test]
    public function deleting_an_invoice_takes_its_lines_charges_and_payments(): void
    {
        $actor = $this->admin();
        Sanctum::actingAs($actor);

        $product = $this->product();
        $invoice = $this->finalized($product, $actor);

        $this->postJson("/api/v1/invoices/{$invoice->id}/payments", [
            'amount' => '1000.00',
            'payment_method' => 'cash',
        ])->assertStatus(201);

        $this->assertSame(1, Payment::where('invoice_id', $invoice->id)->count());

        // payments.invoice_id is RESTRICT, so a payment left behind would
        // make the delete fail outright rather than orphan anything.
        $this->deleteJson("/api/v1/invoices/{$invoice->id}")->assertOk();

        $this->assertDatabaseMissing('invoices', ['id' => $invoice->id]);
        $this->assertSame(0, Payment::where('invoice_id', $invoice->id)->count());
        $this->assertSame(0, InvoiceItem::where('invoice_id', $invoice->id)->count());
    }

    #[Test]
    public function the_number_is_not_handed_out_again(): void
    {
        $actor = $this->admin();
        Sanctum::actingAs($actor);

        $product = $this->product();
        $deleted = $this->finalized($product, $actor);
        $number = $deleted->invoice_number;

        $this->deleteJson("/api/v1/invoices/{$deleted->id}")->assertOk();

        $next = $this->finalized($product, $actor);

        /*
         * The counter is not rewound. Reissuing a number that has already been
         * on a document would be worse than the gap it leaves -- two invoices
         * could end up sharing one.
         */
        $this->assertNotSame($number, $next->invoice_number);
        $this->assertSame('JP/2026-27/00001', $number);
        $this->assertSame('JP/2026-27/00002', $next->invoice_number);
    }

    #[Test]
    public function an_archived_product_still_gets_its_stock_back(): void
    {
        $actor = $this->admin();
        Sanctum::actingAs($actor);

        $product = $this->product();
        $invoice = $this->finalized($product, $actor);

        // Archived AFTER it sold, which is the normal way round -- a line is
        // discontinued once the last of it has gone.
        $product->delete();

        $this->deleteJson("/api/v1/invoices/{$invoice->id}")->assertOk();

        /*
         * Product::query() hides archived rows, so reloading them that way
         * left their movements deleted and their cached stock untouched. The
         * drift was invisible until someone compared the two.
         */
        $fresh = Product::withTrashed()->findOrFail($product->id);
        $ledger = (string) (StockMovement::where('product_id', $product->id)->sum('quantity') ?: '0');

        $this->assertSame('10.000', $fresh->current_stock);
        $this->assertSame(0, bccomp((string) $fresh->current_stock, $ledger, 3));
    }

    #[Test]
    public function deleting_still_needs_the_delete_permission(): void
    {
        $actor = $this->admin();
        $product = $this->product();
        $invoice = $this->finalized($product, $actor);

        $manager = User::factory()->create();
        $manager->assignRole('manager');
        Sanctum::actingAs($manager);

        $this->deleteJson("/api/v1/invoices/{$invoice->id}")->assertStatus(403);

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id]);
        $this->assertSame('7.000', $product->fresh()->current_stock);
    }
}
