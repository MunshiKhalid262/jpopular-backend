<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\Exceptions\DuplicateMovementException;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Services\StockLedger;
use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The ledger is the only writer of stock_movements and products.current_stock,
 * and every other part of Inventory is built on it, so its invariants are
 * tested directly rather than only through the API.
 */
class StockLedgerTest extends TestCase
{
    use RefreshDatabase;

    private StockLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ledger = app(StockLedger::class);
    }

    private function product(string $stock = '0.000'): Product
    {
        return Product::factory()->create([
            'current_stock' => $stock,
            'min_stock_level' => '5.000',
        ]);
    }

    // ------------------------------------------------------- direction

    #[Test]
    public function stock_in_increases_stock(): void
    {
        $product = $this->product('10.000');

        $this->ledger->record([
            'product' => $product,
            'type' => StockMovementType::StockIn,
            'quantity' => '4.500',
        ]);

        $this->assertSame('14.500', $product->fresh()->current_stock);
    }

    #[Test]
    public function stock_out_decreases_stock(): void
    {
        $product = $this->product('10.000');

        $this->ledger->record([
            'product' => $product,
            'type' => StockMovementType::StockOut,
            'quantity' => '2.250',
        ]);

        $this->assertSame('7.750', $product->fresh()->current_stock);
    }

    #[Test]
    public function adjustment_in_increases_stock(): void
    {
        $product = $this->product('3.000');

        $this->ledger->record([
            'product' => $product,
            'type' => StockMovementType::AdjustmentIn,
            'quantity' => '1.500',
        ]);

        $this->assertSame('4.500', $product->fresh()->current_stock);
    }

    #[Test]
    public function adjustment_out_decreases_stock(): void
    {
        $product = $this->product('3.000');

        $this->ledger->record([
            'product' => $product,
            'type' => StockMovementType::AdjustmentOut,
            'quantity' => '1.500',
        ]);

        $this->assertSame('1.500', $product->fresh()->current_stock);
    }

    #[Test]
    public function opening_stock_sets_the_initial_balance_from_zero(): void
    {
        $product = $this->product('0.000');

        $movement = $this->ledger->record([
            'product' => $product,
            'type' => StockMovementType::OpeningStock,
            'quantity' => '25.000',
        ]);

        $this->assertSame('25.000', $product->fresh()->current_stock);
        $this->assertSame('0.000', $movement->previous_stock);
        $this->assertSame('25.000', $movement->new_stock);
    }

    #[Test]
    public function opening_stock_is_additive_not_absolute(): void
    {
        // The ledger has no concept of "set to": every type is a delta. A
        // second opening stock therefore ADDS. Recorded so the behaviour is a
        // decision rather than a surprise.
        $product = $this->product('10.000');

        $this->ledger->record([
            'product' => $product,
            'type' => StockMovementType::OpeningStock,
            'quantity' => '5.000',
        ]);

        $this->assertSame('15.000', $product->fresh()->current_stock);
    }

    // ------------------------------------------------- recorded values

    #[Test]
    public function previous_and_new_stock_are_recorded_on_each_movement(): void
    {
        $product = $this->product('8.000');

        $first = $this->ledger->record([
            'product' => $product,
            'type' => StockMovementType::StockIn,
            'quantity' => '2.000',
        ]);

        $second = $this->ledger->record([
            'product' => $product,
            'type' => StockMovementType::StockOut,
            'quantity' => '3.000',
        ]);

        $this->assertSame('8.000', $first->previous_stock);
        $this->assertSame('10.000', $first->new_stock);

        // The second movement reads the value the first committed, not the
        // stale copy the caller is still holding.
        $this->assertSame('10.000', $second->previous_stock);
        $this->assertSame('7.000', $second->new_stock);
    }

    #[Test]
    public function quantity_is_stored_signed_so_the_sum_reconciles(): void
    {
        $product = $this->product('20.000');

        $in = $this->ledger->record([
            'product' => $product,
            'type' => StockMovementType::StockIn,
            'quantity' => '5.000',
        ]);
        $out = $this->ledger->record([
            'product' => $product,
            'type' => StockMovementType::StockOut,
            'quantity' => '3.000',
        ]);

        $this->assertSame('5.000', $in->quantity);
        $this->assertSame('-3.000', $out->quantity);

        // SUM(quantity) is only meaningful because out is negative.
        $sum = (string) StockMovement::query()->where('product_id', $product->id)->sum('quantity');
        $this->assertSame(0, bccomp($sum, '2.000', 3));
    }

    #[Test]
    public function a_movement_row_is_recorded_with_its_metadata(): void
    {
        $product = $this->product('1.000');
        $actor = User::factory()->create();

        $movement = $this->ledger->record([
            'product' => $product,
            'type' => StockMovementType::StockIn,
            'quantity' => '2.000',
            'note' => 'Received from supplier',
            'unit_cost' => '150.00',
            'user_id' => $actor->id,
        ]);

        $this->assertDatabaseHas('stock_movements', [
            'id' => $movement->id,
            'product_id' => $product->id,
            'type' => StockMovementType::StockIn->value,
            'note' => 'Received from supplier',
            'created_by' => $actor->id,
        ]);
        $this->assertSame('150.00', $movement->unit_cost);
    }

    // ------------------------------------------------ negative stock

    #[Test]
    public function stock_may_not_go_negative(): void
    {
        $product = $this->product('2.000');

        $this->expectException(InsufficientStockException::class);

        $this->ledger->record([
            'product' => $product,
            'type' => StockMovementType::StockOut,
            'quantity' => '2.001',
        ]);
    }

    #[Test]
    public function a_refused_movement_leaves_stock_and_ledger_untouched(): void
    {
        $product = $this->product('2.000');

        try {
            $this->ledger->record([
                'product' => $product,
                'type' => StockMovementType::StockOut,
                'quantity' => '10.000',
            ]);
        } catch (InsufficientStockException) {
            // expected
        }

        $this->assertSame('2.000', $product->fresh()->current_stock);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    #[Test]
    public function stock_may_be_taken_down_to_exactly_zero(): void
    {
        $product = $this->product('2.000');

        $this->ledger->record([
            'product' => $product,
            'type' => StockMovementType::StockOut,
            'quantity' => '2.000',
        ]);

        $this->assertSame('0.000', $product->fresh()->current_stock);
    }

    #[Test]
    public function a_non_positive_quantity_is_refused(): void
    {
        $product = $this->product('5.000');

        $this->expectException(RuntimeException::class);

        $this->ledger->record([
            'product' => $product,
            'type' => StockMovementType::StockIn,
            'quantity' => '0',
        ]);
    }

    // -------------------------------------------------- idempotency

    #[Test]
    public function the_same_referenced_movement_cannot_be_recorded_twice(): void
    {
        $product = $this->product('50.000');

        $reference = [
            'reference_type' => 'InvoiceItem',
            'reference_id' => 99,
        ];

        $this->ledger->record([
            'product' => $product,
            'type' => StockMovementType::InvoiceSale,
            'quantity' => '3.000',
            ...$reference,
        ]);

        $this->expectException(DuplicateMovementException::class);

        $this->ledger->record([
            'product' => $product,
            'type' => StockMovementType::InvoiceSale,
            'quantity' => '3.000',
            ...$reference,
        ]);
    }

    #[Test]
    public function a_duplicate_referenced_movement_does_not_deduct_twice(): void
    {
        $product = $this->product('50.000');

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $this->ledger->record([
                    'product' => $product,
                    'type' => StockMovementType::InvoiceSale,
                    'quantity' => '4.000',
                    'reference_type' => 'InvoiceItem',
                    'reference_id' => 7,
                ]);
            } catch (DuplicateMovementException) {
                // expected on the second attempt
            }
        }

        $this->assertSame('46.000', $product->fresh()->current_stock);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    #[Test]
    public function manual_movements_are_not_blocked_by_the_idempotency_index(): void
    {
        // Manual movements carry no reference, and a NULL reference must not
        // collide -- an operator can legitimately record stock_in twice.
        $product = $this->product('0.000');

        foreach (['1.000', '2.000', '3.000'] as $quantity) {
            $this->ledger->record([
                'product' => $product,
                'type' => StockMovementType::StockIn,
                'quantity' => $quantity,
            ]);
        }

        $this->assertSame('6.000', $product->fresh()->current_stock);
        $this->assertDatabaseCount('stock_movements', 3);
    }

    // ---------------------------------------------- drift / recalculate

    #[Test]
    public function recalculate_reports_drift_without_fixing_it_by_default(): void
    {
        $product = $this->product('0.000');

        $this->ledger->record([
            'product' => $product,
            'type' => StockMovementType::StockIn,
            'quantity' => '10.000',
        ]);

        // Corrupt the cache behind the ledger's back.
        $product->forceFill(['current_stock' => '999.000'])->save();

        $result = $this->ledger->recalculate($product->fresh());

        $this->assertTrue($result['drifted']);
        $this->assertSame('999.000', $result['previous']);
        $this->assertSame('10.000', $result['recalculated']);
        $this->assertSame('999.000', $product->fresh()->current_stock);
    }

    #[Test]
    public function recalculate_repairs_drift_when_asked(): void
    {
        $product = $this->product('0.000');

        $this->ledger->record([
            'product' => $product,
            'type' => StockMovementType::StockIn,
            'quantity' => '10.000',
        ]);

        $product->forceFill(['current_stock' => '999.000'])->save();

        $result = $this->ledger->recalculate($product->fresh(), fix: true);

        $this->assertTrue($result['drifted']);
        $this->assertSame('10.000', $product->fresh()->current_stock);
    }

    #[Test]
    public function recalculate_reports_no_drift_on_a_healthy_product(): void
    {
        $product = $this->product('0.000');

        $this->ledger->record([
            'product' => $product,
            'type' => StockMovementType::StockIn,
            'quantity' => '7.500',
        ]);

        $result = $this->ledger->recalculate($product->fresh());

        $this->assertFalse($result['drifted']);
        $this->assertSame('7.500', $result['recalculated']);
    }

    // ------------------------------------------------------- locking

    #[Test]
    public function lock_products_returns_rows_keyed_by_id_in_deterministic_order(): void
    {
        // Ordering is what prevents two invoices touching the same products in
        // opposite orders from deadlocking. Asserted on the returned order,
        // which is the observable part of that contract.
        $a = $this->product();
        $b = $this->product();
        $c = $this->product();

        $locked = $this->ledger->lockProducts([$c->id, $a->id, $b->id, $a->id]);

        $this->assertSame([$a->id, $b->id, $c->id], array_keys($locked));
        $this->assertCount(3, $locked, 'duplicate ids must collapse');
    }

    #[Test]
    public function the_ledger_recomputes_from_the_locked_row_not_the_callers_copy(): void
    {
        $product = $this->product('10.000');

        // A stale in-memory copy, as a caller holding a model across a change
        // would have.
        $stale = Product::find($product->id);
        $product->forceFill(['current_stock' => '4.000'])->save();

        $movement = $this->ledger->record([
            'product' => $stale,
            'type' => StockMovementType::StockIn,
            'quantity' => '1.000',
        ]);

        // 4 (committed) + 1, not 10 (stale) + 1.
        $this->assertSame('4.000', $movement->previous_stock);
        $this->assertSame('5.000', $movement->new_stock);
    }
}
