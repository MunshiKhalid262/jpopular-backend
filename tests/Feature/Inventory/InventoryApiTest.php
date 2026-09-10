<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\Services\StockLedger;
use App\Enums\StockMovementType;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Support\StockStatus;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\ApiTestCase;

class InventoryApiTest extends ApiTestCase
{
    private function product(array $attributes = []): Product
    {
        return Product::factory()->create($attributes + [
            'current_stock' => '10.000',
            'min_stock_level' => '5.000',
        ]);
    }

    private function move(Product $product, StockMovementType $type, string $qty, array $extra = []): void
    {
        app(StockLedger::class)->record([
            'product' => $product,
            'type' => $type,
            'quantity' => $qty,
        ] + $extra);
    }

    // ------------------------------------------------------ stock summary

    #[Test]
    public function unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/inventory/stock')->assertStatus(401);
        $this->getJson('/api/v1/inventory/movements')->assertStatus(401);
    }

    #[Test]
    public function an_admin_can_list_current_stock(): void
    {
        Sanctum::actingAs($this->admin());

        $this->product(['name' => 'Scooter Battery', 'sku' => 'BAT-1']);

        $response = $this->getJson('/api/v1/inventory/stock')->assertOk();

        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.0.sku', 'BAT-1');
        $response->assertJsonPath('data.0.current_stock', '10.000');
        $response->assertJsonPath('data.0.min_stock_level', '5.000');
        $response->assertJsonPath('data.0.stock_status', StockStatus::IN_STOCK);
        $response->assertJsonStructure([
            'data' => [['id', 'name', 'sku', 'unit', 'current_stock', 'min_stock_level', 'stock_status', 'category', 'brand']],
            'meta' => ['pagination' => ['current_page', 'per_page', 'total', 'last_page']],
        ]);
    }

    #[Test]
    public function the_stock_summary_never_exposes_purchase_price(): void
    {
        Sanctum::actingAs($this->admin());

        $this->product();

        $response = $this->getJson('/api/v1/inventory/stock')->assertOk();

        // Not merely hidden for some roles: the stock view has no pricing at
        // all, so margin cannot leak through this endpoint even for an admin.
        $this->assertArrayNotHasKey('purchase_price', $response->json('data.0'));
        $this->assertArrayNotHasKey('selling_price', $response->json('data.0'));
    }

    #[Test]
    public function stock_status_is_derived_from_current_and_minimum_stock(): void
    {
        Sanctum::actingAs($this->admin());

        $this->product(['sku' => 'OUT-1', 'current_stock' => '0.000', 'min_stock_level' => '5.000']);
        $this->product(['sku' => 'LOW-1', 'current_stock' => '5.000', 'min_stock_level' => '5.000']);
        $this->product(['sku' => 'OK-1', 'current_stock' => '50.000', 'min_stock_level' => '5.000']);

        $byStatus = collect($this->getJson('/api/v1/inventory/stock')->assertOk()->json('data'))
            ->pluck('stock_status', 'sku');

        $this->assertSame(StockStatus::OUT_OF_STOCK, $byStatus['OUT-1']);
        // At the threshold counts as low: it is time to reorder.
        $this->assertSame(StockStatus::LOW_STOCK, $byStatus['LOW-1']);
        $this->assertSame(StockStatus::IN_STOCK, $byStatus['OK-1']);
    }

    #[Test]
    public function zero_stock_reports_out_of_stock_even_when_the_threshold_is_zero(): void
    {
        Sanctum::actingAs($this->admin());

        $this->product(['sku' => 'ZERO-1', 'current_stock' => '0.000', 'min_stock_level' => '0.000']);

        $this->getJson('/api/v1/inventory/stock')
            ->assertOk()
            ->assertJsonPath('data.0.stock_status', StockStatus::OUT_OF_STOCK);
    }

    #[Test]
    public function the_low_stock_filter_returns_only_low_stock_rows(): void
    {
        Sanctum::actingAs($this->admin());

        $this->product(['sku' => 'OUT-1', 'current_stock' => '0.000', 'min_stock_level' => '5.000']);
        $this->product(['sku' => 'LOW-1', 'current_stock' => '3.000', 'min_stock_level' => '5.000']);
        $this->product(['sku' => 'OK-1', 'current_stock' => '50.000', 'min_stock_level' => '5.000']);

        $skus = collect($this->getJson('/api/v1/inventory/stock?low_stock=1')->assertOk()->json('data'))
            ->pluck('sku');

        // Out of stock is deliberately NOT low stock: it is a separate status.
        $this->assertSame(['LOW-1'], $skus->all());
    }

    #[Test]
    public function the_stock_status_filter_matches_what_the_rows_serialise(): void
    {
        Sanctum::actingAs($this->admin());

        $this->product(['sku' => 'OUT-1', 'current_stock' => '0.000', 'min_stock_level' => '5.000']);
        $this->product(['sku' => 'LOW-1', 'current_stock' => '3.000', 'min_stock_level' => '5.000']);
        $this->product(['sku' => 'OK-1', 'current_stock' => '50.000', 'min_stock_level' => '5.000']);

        foreach ([StockStatus::OUT_OF_STOCK => 'OUT-1', StockStatus::LOW_STOCK => 'LOW-1', StockStatus::IN_STOCK => 'OK-1'] as $status => $expected) {
            $rows = $this->getJson("/api/v1/inventory/stock?stock_status={$status}")->assertOk()->json('data');

            $this->assertCount(1, $rows, "expected exactly one {$status} row");
            $this->assertSame($expected, $rows[0]['sku']);
            $this->assertSame($status, $rows[0]['stock_status']);
        }
    }

    #[Test]
    public function the_stock_list_can_be_searched_and_filtered(): void
    {
        Sanctum::actingAs($this->admin());

        $category = Category::factory()->create();
        $brand = Brand::factory()->create();

        $this->product(['name' => 'Lithium Battery', 'sku' => 'BAT-9', 'category_id' => $category->id, 'brand_id' => $brand->id]);
        $this->product(['name' => 'Helmet', 'sku' => 'HEL-1']);

        $this->getJson('/api/v1/inventory/stock?search=Lithium')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.sku', 'BAT-9');

        $this->getJson('/api/v1/inventory/stock?search=BAT-9')
            ->assertOk()->assertJsonCount(1, 'data');

        $this->getJson("/api/v1/inventory/stock?category_id={$category->id}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.sku', 'BAT-9');

        $this->getJson("/api/v1/inventory/stock?brand_id={$brand->id}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.sku', 'BAT-9');
    }

    #[Test]
    public function the_stock_list_paginates_and_caps_per_page(): void
    {
        Sanctum::actingAs($this->admin());

        Product::factory()->count(8)->create(['current_stock' => '1.000']);

        $this->getJson('/api/v1/inventory/stock?per_page=5')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.pagination.total', 8)
            ->assertJsonPath('meta.pagination.last_page', 2);

        // An unbounded per_page would let one request read the whole table.
        $this->getJson('/api/v1/inventory/stock?per_page=5000')
            ->assertOk()
            ->assertJsonPath('meta.pagination.per_page', 100);
    }

    // ---------------------------------------------------- movement history

    #[Test]
    public function an_admin_can_list_movement_history(): void
    {
        Sanctum::actingAs($this->admin());

        $product = $this->product(['sku' => 'BAT-1']);
        $this->move($product, StockMovementType::StockIn, '5.000');

        $response = $this->getJson('/api/v1/inventory/movements')->assertOk();

        $response->assertJsonPath('data.0.type', 'stock_in');
        $response->assertJsonPath('data.0.type_label', 'Stock in');
        $response->assertJsonPath('data.0.quantity', '5.000');
        $response->assertJsonPath('data.0.previous_stock', '10.000');
        $response->assertJsonPath('data.0.new_stock', '15.000');
        $response->assertJsonPath('data.0.product.sku', 'BAT-1');
        $response->assertJsonStructure([
            'data' => [[
                'id', 'type', 'type_label', 'quantity', 'previous_stock', 'new_stock',
                'note', 'reference_type', 'reference_id', 'product', 'created_by', 'occurred_at',
            ]],
        ]);
    }

    #[Test]
    public function movement_history_can_be_filtered(): void
    {
        Sanctum::actingAs($this->admin());

        $battery = $this->product(['name' => 'Battery', 'sku' => 'BAT-1']);
        $helmet = $this->product(['name' => 'Helmet', 'sku' => 'HEL-1']);

        $this->move($battery, StockMovementType::StockIn, '5.000');
        $this->move($helmet, StockMovementType::StockOut, '2.000');

        $this->getJson("/api/v1/inventory/movements?product_id={$battery->id}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.product.sku', 'BAT-1');

        $this->getJson('/api/v1/inventory/movements?type=stock_out')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.product.sku', 'HEL-1');

        $this->getJson('/api/v1/inventory/movements?search=Helmet')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.product.sku', 'HEL-1');

        $this->getJson('/api/v1/inventory/movements?search=BAT-1')
            ->assertOk()->assertJsonCount(1, 'data');
    }

    #[Test]
    public function movement_history_can_be_filtered_by_date_range(): void
    {
        Sanctum::actingAs($this->admin());

        $product = $this->product();

        $this->move($product, StockMovementType::StockIn, '1.000', ['occurred_at' => '2026-01-10 10:00:00']);
        $this->move($product, StockMovementType::StockIn, '2.000', ['occurred_at' => '2026-03-20 10:00:00']);

        $this->getJson('/api/v1/inventory/movements?date_from=2026-03-01')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.quantity', '2.000');

        $this->getJson('/api/v1/inventory/movements?date_to=2026-01-31')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.quantity', '1.000');

        // The boundary day itself is included, or an operator filtering to
        // "today" would see nothing.
        $this->getJson('/api/v1/inventory/movements?date_from=2026-01-10&date_to=2026-01-10')
            ->assertOk()->assertJsonCount(1, 'data');
    }

    #[Test]
    public function movement_history_is_newest_first(): void
    {
        Sanctum::actingAs($this->admin());

        $product = $this->product();

        $this->move($product, StockMovementType::StockIn, '1.000', ['occurred_at' => '2026-01-01 10:00:00']);
        $this->move($product, StockMovementType::StockIn, '2.000', ['occurred_at' => '2026-06-01 10:00:00']);

        $this->getJson('/api/v1/inventory/movements')
            ->assertOk()
            ->assertJsonPath('data.0.quantity', '2.000')
            ->assertJsonPath('data.1.quantity', '1.000');
    }

    // ------------------------------------------------------ manual movement

    #[Test]
    public function an_admin_can_record_stock_in(): void
    {
        Sanctum::actingAs($admin = $this->admin());

        $product = $this->product();

        $response = $this->postJson("/api/v1/products/{$product->id}/movements", [
            'type' => 'stock_in',
            'quantity' => '4.500',
            'note' => 'Supplier delivery',
        ])->assertStatus(201);

        $response->assertJsonPath('data.movement.type', 'stock_in');
        $response->assertJsonPath('data.movement.quantity', '4.500');
        $response->assertJsonPath('data.movement.previous_stock', '10.000');
        $response->assertJsonPath('data.movement.new_stock', '14.500');
        $response->assertJsonPath('data.movement.created_by.id', $admin->id);

        // The updated product comes back so the UI can refresh the row.
        $response->assertJsonPath('data.product.current_stock', '14.500');
        $this->assertSame('14.500', $product->fresh()->current_stock);
    }

    #[Test]
    public function an_admin_can_record_stock_out(): void
    {
        Sanctum::actingAs($this->admin());

        $product = $this->product();

        $this->postJson("/api/v1/products/{$product->id}/movements", [
            'type' => 'stock_out',
            'quantity' => '3.000',
            'note' => 'Damaged in transit',
        ])->assertStatus(201)->assertJsonPath('data.product.current_stock', '7.000');
    }

    #[Test]
    public function an_admin_can_record_an_adjustment(): void
    {
        Sanctum::actingAs($this->admin());

        $product = $this->product();

        $this->postJson("/api/v1/products/{$product->id}/movements", [
            'type' => 'adjustment_in',
            'quantity' => '2.000',
            'note' => 'Stock count correction',
        ])->assertStatus(201)->assertJsonPath('data.product.current_stock', '12.000');

        $this->postJson("/api/v1/products/{$product->id}/movements", [
            'type' => 'adjustment_out',
            'quantity' => '1.000',
            'note' => 'Stock count correction',
        ])->assertStatus(201)->assertJsonPath('data.product.current_stock', '11.000');
    }

    #[Test]
    public function opening_stock_can_be_recorded_without_a_note(): void
    {
        Sanctum::actingAs($this->admin());

        $product = $this->product(['current_stock' => '0.000']);

        $this->postJson("/api/v1/products/{$product->id}/movements", [
            'type' => 'opening_stock',
            'quantity' => '30.000',
        ])->assertStatus(201)->assertJsonPath('data.product.current_stock', '30.000');
    }

    #[Test]
    public function a_note_is_required_where_the_enum_demands_one(): void
    {
        Sanctum::actingAs($this->admin());

        $product = $this->product();

        foreach (['stock_out', 'adjustment_in', 'adjustment_out'] as $type) {
            $this->postJson("/api/v1/products/{$product->id}/movements", [
                'type' => $type,
                'quantity' => '1.000',
            ])->assertStatus(422)->assertJsonValidationErrors('note');
        }
    }

    #[Test]
    public function invoice_movement_types_cannot_be_recorded_through_the_api(): void
    {
        Sanctum::actingAs($this->admin());

        $product = $this->product();

        // Stock must never move behind an invoice's back.
        foreach (['invoice_sale', 'invoice_cancel', 'nonsense'] as $type) {
            $this->postJson("/api/v1/products/{$product->id}/movements", [
                'type' => $type,
                'quantity' => '1.000',
                'note' => 'trying it on',
            ])->assertStatus(422)->assertJsonValidationErrors('type');
        }

        $this->assertSame('10.000', $product->fresh()->current_stock);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    #[Test]
    public function a_movement_that_would_go_negative_is_refused_with_a_conflict(): void
    {
        Sanctum::actingAs($this->admin());

        $product = $this->product(['current_stock' => '2.000']);

        $this->postJson("/api/v1/products/{$product->id}/movements", [
            'type' => 'stock_out',
            'quantity' => '5.000',
            'note' => 'Oversell attempt',
        ])
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'INSUFFICIENT_STOCK');

        $this->assertSame('2.000', $product->fresh()->current_stock);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    #[Test]
    public function quantity_must_be_a_positive_decimal_within_scale(): void
    {
        Sanctum::actingAs($this->admin());

        $product = $this->product();

        foreach (['0', '-1', '1.2345', 'abc', '1e5', ''] as $quantity) {
            $this->postJson("/api/v1/products/{$product->id}/movements", [
                'type' => 'stock_in',
                'quantity' => $quantity,
            ])->assertStatus(422)->assertJsonValidationErrors('quantity');
        }
    }

    #[Test]
    public function a_future_movement_date_is_refused(): void
    {
        Sanctum::actingAs($this->admin());

        $product = $this->product();

        $this->postJson("/api/v1/products/{$product->id}/movements", [
            'type' => 'stock_in',
            'quantity' => '1.000',
            'occurred_at' => now()->addWeek()->toIso8601String(),
        ])->assertStatus(422)->assertJsonValidationErrors('occurred_at');
    }

    // ---------------------------------------------------------- permissions

    #[Test]
    public function a_manager_can_view_inventory_but_not_adjust_it(): void
    {
        // The default matrix grants a manager inventory.view but withholds
        // inventory.adjust.
        Sanctum::actingAs($this->manager());

        $product = $this->product();

        $this->getJson('/api/v1/inventory/stock')->assertOk();
        $this->getJson('/api/v1/inventory/movements')->assertOk();

        $this->postJson("/api/v1/products/{$product->id}/movements", [
            'type' => 'stock_in',
            'quantity' => '1.000',
        ])->assertStatus(403);

        $this->assertDatabaseCount('stock_movements', 0);
    }

    #[Test]
    public function a_user_without_inventory_permissions_is_refused_everywhere(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $product = $this->product();

        $this->getJson('/api/v1/inventory/stock')->assertStatus(403);
        $this->getJson('/api/v1/inventory/movements')->assertStatus(403);
        $this->postJson("/api/v1/products/{$product->id}/movements", [
            'type' => 'stock_in',
            'quantity' => '1.000',
        ])->assertStatus(403);
    }
}
