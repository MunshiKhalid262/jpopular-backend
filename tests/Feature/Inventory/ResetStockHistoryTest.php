<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\Services\StockLedger;
use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Clearing the movement ledger, in both senses of "clear".
 *
 * Whichever mode runs, the invariant is the same: afterwards the cached
 * `products.current_stock` equals SUM(stock_movements.quantity). A reset that
 * left those two disagreeing would have traded one mess for a worse one.
 */
class ResetStockHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function stocked(string $quantity, bool $archived = false): Product
    {
        $product = Product::factory()->create(['current_stock' => '0.000']);

        app(StockLedger::class)->record([
            'product' => $product,
            'type' => StockMovementType::StockIn,
            'quantity' => $quantity,
            'reference_type' => 'Test',
            'reference_id' => (int) $product->getKey(),
            'user_id' => null,
            'note' => 'test stock in',
        ]);

        if ($archived) {
            $product->delete();
        }

        return $product->refresh();
    }

    private function assertLedgerMatchesCache(): void
    {
        foreach (Product::withTrashed()->get() as $product) {
            $ledger = (string) (StockMovement::where('product_id', $product->getKey())->sum('quantity') ?: '0');

            $this->assertSame(
                0,
                bccomp((string) $product->current_stock, $ledger, 3),
                "product #{$product->getKey()} cached {$product->current_stock} but ledger {$ledger}",
            );
        }
    }

    // ------------------------------------------------------------------

    #[Test]
    public function rebaseline_keeps_the_quantities_but_drops_the_history(): void
    {
        $product = $this->stocked('40.000');

        // Two movements in, one line of history out.
        app(StockLedger::class)->record([
            'product' => $product->fresh(),
            'type' => StockMovementType::AdjustmentIn,
            'quantity' => '10.000',
            'reference_type' => 'Test',
            'reference_id' => 999,
            'user_id' => null,
            'note' => 'found behind the counter',
        ]);

        $this->assertSame(2, StockMovement::count());

        $this->artisan('inventory:reset-history', ['--mode' => 'rebaseline', '--force' => true])
            ->assertExitCode(0);

        $this->assertSame('50.000', $product->fresh()->current_stock);
        $this->assertSame(1, StockMovement::count());
        $this->assertSame(
            StockMovementType::OpeningStock,
            StockMovement::firstOrFail()->type,
        );

        $this->assertLedgerMatchesCache();
    }

    #[Test]
    public function zero_clears_the_quantities_too(): void
    {
        $product = $this->stocked('40.000');

        $this->artisan('inventory:reset-history', ['--mode' => 'zero', '--force' => true])
            ->assertExitCode(0);

        $this->assertSame('0.000', $product->fresh()->current_stock);
        $this->assertSame(0, StockMovement::count());

        $this->assertLedgerMatchesCache();
    }

    #[Test]
    public function an_archived_product_is_reset_as_well(): void
    {
        $archived = $this->stocked('25.000', archived: true);

        /*
         * Archiving hides a product from the catalog; it does not mean its
         * stock row should be left holding a figure nothing explains. The
         * ledger used to skip these entirely, which is how drift got in.
         */
        $this->artisan('inventory:reset-history', ['--mode' => 'zero', '--force' => true])
            ->assertExitCode(0);

        $this->assertSame('0.000', Product::withTrashed()->findOrFail($archived->id)->current_stock);
        $this->assertLedgerMatchesCache();
    }

    #[Test]
    public function a_rebaseline_repairs_drift_rather_than_preserving_it(): void
    {
        $product = $this->stocked('40.000');

        // Drift of the kind a bad delete leaves: the row says one thing, the
        // ledger another.
        StockMovement::query()->delete();

        $this->assertSame('40.000', $product->fresh()->current_stock);
        $this->assertSame(0, StockMovement::count());

        $this->artisan('inventory:reset-history', ['--mode' => 'rebaseline', '--force' => true])
            ->assertExitCode(0);

        // The cached figure is taken as the truth and written into the ledger,
        // so the two agree afterwards whichever was wrong before.
        $this->assertSame('40.000', $product->fresh()->current_stock);
        $this->assertLedgerMatchesCache();
    }

    #[Test]
    public function a_dry_run_changes_nothing(): void
    {
        $product = $this->stocked('40.000');

        $this->artisan('inventory:reset-history', ['--mode' => 'zero', '--dry-run' => true])
            ->assertExitCode(0);

        $this->assertSame('40.000', $product->fresh()->current_stock);
        $this->assertSame(1, StockMovement::count());
    }

    #[Test]
    public function a_mode_is_required(): void
    {
        $this->stocked('40.000');

        // No default: the two modes differ by whether the shop still has its
        // stock afterwards, which is not a thing to guess at.
        $this->artisan('inventory:reset-history', ['--force' => true])
            ->assertExitCode(1);

        $this->assertSame(1, StockMovement::count());
    }
}
