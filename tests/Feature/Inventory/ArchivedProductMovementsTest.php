<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\Services\StockLedger;
use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\StockMovement;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\ApiTestCase;

/**
 * Archiving a product takes its movements out of the history with it.
 *
 * The stock list already hides archived products, so leaving their movements
 * on show meant rows for something the rest of the app says does not exist --
 * and with a dash where the product name belongs, because the relation
 * resolves to null under the soft-delete scope.
 *
 * The rows are NOT deleted. They still sum into the product's own balance, so
 * restoring the product brings its history back intact.
 */
class ArchivedProductMovementsTest extends ApiTestCase
{
    private function stocked(string $name): Product
    {
        $product = Product::factory()->create(['name' => $name, 'current_stock' => '0.000']);

        app(StockLedger::class)->record([
            'product' => $product,
            'type' => StockMovementType::StockIn,
            'quantity' => '10.000',
            'reference_type' => 'Test',
            'reference_id' => (int) $product->getKey(),
            'user_id' => null,
            'note' => 'delivery',
        ]);

        return $product->refresh();
    }

    #[Test]
    public function an_archived_products_movements_leave_the_history(): void
    {
        Sanctum::actingAs($this->admin());

        $kept = $this->stocked('Still Stocked');
        $archived = $this->stocked('Discontinued');

        $this->getJson('/api/v1/inventory/movements')->assertOk()->assertJsonCount(2, 'data');

        $archived->delete();

        $names = collect($this->getJson('/api/v1/inventory/movements')->assertOk()->json('data'))
            ->pluck('product.name');

        $this->assertCount(1, $names);
        $this->assertTrue($names->contains($kept->name));
        $this->assertFalse($names->contains('Discontinued'));
    }

    #[Test]
    public function the_rows_are_hidden_not_deleted(): void
    {
        Sanctum::actingAs($this->admin());

        $archived = $this->stocked('Discontinued');
        $archived->delete();

        $this->getJson('/api/v1/inventory/movements')->assertOk()->assertJsonCount(0, 'data');

        /*
         * Still on the ledger. They sum into the product's balance, so
         * restoring the product has to bring its history back with it --
         * deleting them would leave the restored row holding stock nothing
         * explains.
         */
        $this->assertSame(1, StockMovement::where('product_id', $archived->id)->count());

        $archived->restore();

        $this->getJson('/api/v1/inventory/movements')->assertOk()->assertJsonCount(1, 'data');
    }

    #[Test]
    public function the_stock_movement_report_hides_them_too(): void
    {
        Sanctum::actingAs($this->admin());

        $archived = $this->stocked('Discontinued');
        $this->stocked('Still Stocked');

        $archived->delete();

        // Otherwise the report would not reconcile with the stock list, which
        // has never shown archived products.
        $rows = $this->getJson('/api/v1/reports/stock-movements?'.http_build_query([
            'date_from' => now()->subMonth()->toDateString(),
            'date_to' => now()->addDay()->toDateString(),
        ]))->assertOk()->json('data.movements');

        $names = collect($rows ?? [])->pluck('product.name');

        $this->assertFalse($names->contains('Discontinued'));
    }

    #[Test]
    public function a_product_page_is_unaffected_for_a_live_product(): void
    {
        Sanctum::actingAs($this->admin());

        $product = $this->stocked('Still Stocked');

        // The filter must not catch anything it should not: a live product
        // keeps every one of its movements.
        $this->getJson('/api/v1/inventory/movements?product_id='.$product->id)
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }
}
