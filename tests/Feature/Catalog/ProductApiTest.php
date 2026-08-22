<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\ApiTestCase;

class ProductApiTest extends ApiTestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Demo Electric Scooter S1',
            'sku' => 'DEMO-SCT-S1',
            'category_id' => Category::factory()->create()->id,
            'brand_id' => Brand::factory()->create()->id,
            'model' => 'S1-2026',
            'description' => 'A demo scooter.',
            'unit' => 'pcs',
            'hsn_code' => '87116020',
            'gst_rate' => '5.00',
            'purchase_price' => '68000.00',
            'selling_price' => '84999.00',
            'min_stock_level' => '2',
        ], $overrides);
    }

    #[Test]
    public function unauthenticated_requests_are_rejected(): void
    {
        $product = Product::factory()->create();

        $this->getJson('/api/v1/products')->assertStatus(401);
        $this->postJson('/api/v1/products', [])->assertStatus(401);
        $this->getJson("/api/v1/products/{$product->id}")->assertStatus(401);
        $this->putJson("/api/v1/products/{$product->id}", [])->assertStatus(401);
        $this->putJson("/api/v1/products/{$product->id}/status", [])->assertStatus(401);
        $this->deleteJson("/api/v1/products/{$product->id}")->assertStatus(401);
    }

    #[Test]
    public function an_admin_can_create_a_product(): void
    {
        Sanctum::actingAs($this->admin());

        $response = $this->postJson('/api/v1/products', $this->validPayload())
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.sku', 'DEMO-SCT-S1')
            ->assertJsonPath('data.gst_rate', '5.00')
            ->assertJsonPath('data.selling_price', '84999.00')
            ->assertJsonPath('data.is_active', true);

        // Stock always starts at zero -- it belongs to the Inventory ledger.
        $this->assertSame('0.000', $response->json('data.current_stock'));
        $this->assertDatabaseHas('products', ['sku' => 'DEMO-SCT-S1', 'current_stock' => 0]);
    }

    #[Test]
    public function money_is_returned_as_a_decimal_string_not_a_float(): void
    {
        Sanctum::actingAs($this->admin());

        $response = $this->postJson('/api/v1/products', $this->validPayload([
            'selling_price' => '1250.50',
            'purchase_price' => '999.99',
        ]))->assertStatus(201);

        // Strings, so no precision is lost crossing JSON into JavaScript.
        $this->assertIsString($response->json('data.selling_price'));
        $this->assertSame('1250.50', $response->json('data.selling_price'));
        $this->assertSame('999.99', $response->json('data.purchase_price'));
    }

    // ---------------------------------------------------------------- SKU

    #[Test]
    public function a_duplicate_sku_is_rejected(): void
    {
        Sanctum::actingAs($this->admin());
        Product::factory()->create(['sku' => 'TAKEN-SKU']);

        $this->postJson('/api/v1/products', $this->validPayload(['sku' => 'TAKEN-SKU']))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['sku']]);
    }

    #[Test]
    public function a_sku_cannot_be_reused_from_an_archived_product(): void
    {
        Sanctum::actingAs($this->admin());

        $archived = Product::factory()->create(['sku' => 'HISTORIC-SKU']);
        $archived->delete();

        // Strict policy: SKUs appear on historical invoices, so reuse would
        // make an old document ambiguous.
        $this->postJson('/api/v1/products', $this->validPayload(['sku' => 'HISTORIC-SKU']))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['sku']]);
    }

    #[Test]
    public function sku_uniqueness_is_case_insensitive(): void
    {
        Sanctum::actingAs($this->admin());
        Product::factory()->create(['sku' => 'Demo-Sku-1']);

        $this->postJson('/api/v1/products', $this->validPayload(['sku' => 'demo-sku-1']))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['sku']]);
    }

    #[Test]
    public function a_sku_is_trimmed_but_its_case_is_preserved(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/v1/products', $this->validPayload(['sku' => '  MiXeD-Case-1  ']))
            ->assertStatus(201)
            // Trimmed, but not silently upper-cased: it is an operator-entered
            // business identifier.
            ->assertJsonPath('data.sku', 'MiXeD-Case-1');
    }

    #[Test]
    public function a_product_can_keep_its_own_sku_when_updated(): void
    {
        Sanctum::actingAs($this->admin());
        $product = Product::factory()->create(['sku' => 'MINE-1']);

        $this->putJson("/api/v1/products/{$product->id}", ['sku' => 'MINE-1', 'name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed');
    }

    // --------------------------------------------------------- validation

    #[Test]
    public function creating_a_product_requires_the_core_fields(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/v1/products', [])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonStructure([
                'errors' => ['name', 'sku', 'category_id', 'unit', 'gst_rate', 'selling_price'],
            ]);
    }

    #[Test]
    public function a_missing_or_unknown_category_is_rejected(): void
    {
        Sanctum::actingAs($this->admin());

        $payload = $this->validPayload();
        unset($payload['category_id']);

        $this->postJson('/api/v1/products', $payload)
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['category_id']]);

        $this->postJson('/api/v1/products', $this->validPayload(['category_id' => 999999]))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['category_id']]);
    }

    #[Test]
    public function an_archived_category_cannot_be_assigned(): void
    {
        Sanctum::actingAs($this->admin());
        $category = Category::factory()->create();
        $category->delete();

        $this->postJson('/api/v1/products', $this->validPayload(['category_id' => $category->id]))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['category_id']]);
    }

    #[Test]
    public function the_brand_is_optional_but_must_exist_when_supplied(): void
    {
        Sanctum::actingAs($this->admin());

        $payload = $this->validPayload(['brand_id' => null, 'sku' => 'NO-BRAND-1']);
        $this->postJson('/api/v1/products', $payload)
            ->assertStatus(201)
            ->assertJsonPath('data.brand_id', null);

        $this->postJson('/api/v1/products', $this->validPayload(['brand_id' => 999999, 'sku' => 'BAD-BRAND-1']))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['brand_id']]);
    }

    #[Test]
    public function negative_prices_are_rejected(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/v1/products', $this->validPayload(['selling_price' => '-1.00']))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['selling_price']]);

        $this->postJson('/api/v1/products', $this->validPayload(['purchase_price' => '-0.01']))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['purchase_price']]);
    }

    #[Test]
    public function prices_with_too_many_decimals_or_scientific_notation_are_rejected(): void
    {
        Sanctum::actingAs($this->admin());

        // DECIMAL(14,2) would silently round a third decimal place.
        $this->postJson('/api/v1/products', $this->validPayload(['selling_price' => '10.123']))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['selling_price']]);

        $this->postJson('/api/v1/products', $this->validPayload(['selling_price' => '1e5']))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['selling_price']]);
    }

    #[Test]
    public function a_gst_rate_outside_zero_to_one_hundred_is_rejected(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/v1/products', $this->validPayload(['gst_rate' => '101.00']))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['gst_rate']]);

        $this->postJson('/api/v1/products', $this->validPayload(['gst_rate' => '-5.00']))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['gst_rate']]);
    }

    #[Test]
    public function different_products_may_carry_different_gst_rates(): void
    {
        Sanctum::actingAs($this->admin());
        $category = Category::factory()->create()->id;

        // A scooter and a battery are taxed differently -- the rate must be
        // per product, never a global constant.
        $scooter = $this->postJson('/api/v1/products', $this->validPayload([
            'sku' => 'GST-5', 'gst_rate' => '5.00', 'category_id' => $category,
        ]))->assertStatus(201);

        $battery = $this->postJson('/api/v1/products', $this->validPayload([
            'sku' => 'GST-18', 'gst_rate' => '18.00', 'category_id' => $category,
        ]))->assertStatus(201);

        $this->assertSame('5.00', $scooter->json('data.gst_rate'));
        $this->assertSame('18.00', $battery->json('data.gst_rate'));
    }

    #[Test]
    public function a_negative_minimum_stock_level_is_rejected(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/v1/products', $this->validPayload(['min_stock_level' => '-1']))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['min_stock_level']]);
    }

    #[Test]
    public function an_unknown_unit_is_rejected(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/v1/products', $this->validPayload(['unit' => 'furlong']))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['unit']]);
    }

    #[Test]
    public function a_malformed_hsn_code_is_rejected(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/v1/products', $this->validPayload(['hsn_code' => 'ABCD']))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['hsn_code']]);
    }

    // ------------------------------------------------- current_stock guard

    #[Test]
    public function current_stock_cannot_be_set_when_creating_a_product(): void
    {
        Sanctum::actingAs($this->admin());

        $response = $this->postJson('/api/v1/products', $this->validPayload([
            'current_stock' => '500',
        ]))->assertStatus(201);

        // Silently ignored, never honoured: stock only moves through the ledger.
        $this->assertSame('0.000', $response->json('data.current_stock'));
        $this->assertDatabaseHas('products', ['sku' => 'DEMO-SCT-S1', 'current_stock' => 0]);
    }

    #[Test]
    public function current_stock_cannot_be_changed_through_a_product_update(): void
    {
        Sanctum::actingAs($this->admin());

        // Seed a balance the way the Inventory domain eventually will.
        $product = Product::factory()->create(['sku' => 'STOCK-1']);
        $product->forceFill(['current_stock' => '25.000'])->save();

        $this->putJson("/api/v1/products/{$product->id}", [
            'name' => 'Renamed Product',
            'current_stock' => '9999',
        ])->assertOk();

        // The rename applied; the stock balance did not move.
        $this->assertSame('Renamed Product', $product->fresh()->name);
        $this->assertSame('25.000', $product->fresh()->current_stock);
    }

    #[Test]
    public function current_stock_is_absent_from_the_product_fillable_allow_list(): void
    {
        // Guards against a future refactor quietly re-adding it.
        $this->assertNotContains('current_stock', (new Product)->getFillable());
        $this->assertNotContains('image_path', (new Product)->getFillable());
    }

    // ---------------------------------------------- purchase price privacy

    #[Test]
    public function purchase_price_is_returned_to_a_user_with_the_permission(): void
    {
        Sanctum::actingAs($this->admin());
        Product::factory()->create(['purchase_price' => '1000.00']);

        $response = $this->getJson('/api/v1/products')->assertOk();

        $this->assertArrayHasKey('purchase_price', $response->json('data.0'));
        $this->assertSame('1000.00', $response->json('data.0.purchase_price'));
    }

    #[Test]
    public function purchase_price_is_withheld_from_a_manager_without_the_permission(): void
    {
        // products.view_purchase_price is grantable, NOT a Manager default.
        Sanctum::actingAs($this->manager());
        $product = Product::factory()->create(['purchase_price' => '68000.00']);

        $list = $this->getJson('/api/v1/products')->assertOk();
        $show = $this->getJson("/api/v1/products/{$product->id}")->assertOk();

        // The key is absent entirely, not merely null -- the number never
        // leaves the server.
        $this->assertArrayNotHasKey('purchase_price', $list->json('data.0'));
        $this->assertArrayNotHasKey('purchase_price', $show->json('data'));
        $this->assertStringNotContainsString('68000.00', (string) $list->getContent());
        $this->assertStringNotContainsString('68000.00', (string) $show->getContent());
    }

    #[Test]
    public function a_manager_granted_the_permission_individually_does_see_purchase_price(): void
    {
        $manager = $this->manager();
        $manager->givePermissionTo('products.view_purchase_price');
        Sanctum::actingAs($manager->fresh());

        Product::factory()->create(['purchase_price' => '4321.00']);

        $response = $this->getJson('/api/v1/products')->assertOk();

        $this->assertSame('4321.00', $response->json('data.0.purchase_price'));
    }

    // ------------------------------------------------------- permissions

    #[Test]
    public function a_manager_can_list_and_view_products_by_default(): void
    {
        Sanctum::actingAs($this->manager());
        $product = Product::factory()->create();

        $this->getJson('/api/v1/products')->assertOk();
        $this->getJson("/api/v1/products/{$product->id}")->assertOk();
    }

    #[Test]
    public function a_manager_cannot_create_update_or_delete_products_by_default(): void
    {
        Sanctum::actingAs($this->manager());
        $product = Product::factory()->create();

        // products.create / update are grantable, not Manager defaults;
        // products.delete is denied outright.
        $this->postJson('/api/v1/products', $this->validPayload())->assertStatus(403);
        $this->putJson("/api/v1/products/{$product->id}", ['name' => 'Nope'])->assertStatus(403);
        $this->putJson("/api/v1/products/{$product->id}/status", ['is_active' => false])->assertStatus(403);
        $this->deleteJson("/api/v1/products/{$product->id}")->assertStatus(403);

        $this->assertNotSame('Nope', $product->fresh()->name);
    }

    #[Test]
    public function a_manager_granted_products_create_can_create(): void
    {
        $manager = $this->manager();
        $manager->givePermissionTo('products.create');
        Sanctum::actingAs($manager->fresh());

        $this->postJson('/api/v1/products', $this->validPayload())->assertStatus(201);
    }

    #[Test]
    public function a_manager_granted_products_update_still_cannot_delete(): void
    {
        $manager = $this->manager();
        $manager->givePermissionTo('products.update');
        Sanctum::actingAs($manager->fresh());

        $product = Product::factory()->create();

        $this->putJson("/api/v1/products/{$product->id}", ['name' => 'Allowed'])->assertOk();
        $this->deleteJson("/api/v1/products/{$product->id}")->assertStatus(403);
    }

    // ---------------------------------------------------- archive + status

    #[Test]
    public function an_admin_can_archive_a_product(): void
    {
        Sanctum::actingAs($this->admin());
        $product = Product::factory()->create();

        $this->deleteJson("/api/v1/products/{$product->id}")->assertOk();

        $this->assertSoftDeleted('products', ['id' => $product->id]);
        // Never hard-deleted: the row backs historical invoices.
        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    #[Test]
    public function an_archived_product_is_excluded_from_the_list_and_returns_404(): void
    {
        Sanctum::actingAs($this->admin());
        $product = Product::factory()->create();
        $product->delete();

        $this->getJson('/api/v1/products')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/products/{$product->id}")
            ->assertStatus(404)
            ->assertJsonPath('code', 'NOT_FOUND');
    }

    #[Test]
    public function an_admin_can_toggle_product_status(): void
    {
        Sanctum::actingAs($this->admin());
        $product = Product::factory()->create(['is_active' => true]);

        $this->putJson("/api/v1/products/{$product->id}/status", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertFalse($product->fresh()->is_active);
    }

    // ------------------------------------------------------------ listing

    #[Test]
    public function the_list_can_be_searched_by_name_sku_and_model(): void
    {
        Sanctum::actingAs($this->admin());

        Product::factory()->create(['name' => 'Findable Scooter', 'sku' => 'AAA-1', 'model' => 'MOD-A']);
        Product::factory()->create(['name' => 'Other Thing', 'sku' => 'BBB-2', 'model' => 'MOD-B']);

        $this->getJson('/api/v1/products?search=Findable')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/products?search=BBB-2')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/products?search=MOD-A')->assertOk()->assertJsonCount(1, 'data');
    }

    #[Test]
    public function the_list_can_be_filtered_by_category_brand_and_status(): void
    {
        Sanctum::actingAs($this->admin());

        $category = Category::factory()->create();
        $brand = Brand::factory()->create();

        Product::factory()->create(['category_id' => $category->id, 'brand_id' => $brand->id]);
        Product::factory()->count(2)->create();
        Product::factory()->inactive()->create();

        $this->getJson("/api/v1/products?category_id={$category->id}")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/products?brand_id={$brand->id}")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/products?is_active=0')->assertOk()->assertJsonCount(1, 'data');
    }

    #[Test]
    public function the_list_is_paginated_with_envelope_metadata(): void
    {
        Sanctum::actingAs($this->admin());
        Product::factory()->count(5)->create();

        $this->getJson('/api/v1/products?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.pagination.per_page', 2)
            ->assertJsonPath('meta.pagination.total', 5)
            ->assertJsonPath('meta.pagination.last_page', 3);
    }

    #[Test]
    public function the_list_embeds_the_category_and_brand(): void
    {
        Sanctum::actingAs($this->admin());

        $category = Category::factory()->create(['name' => 'Batteries']);
        $brand = Brand::factory()->create(['name' => 'PowerCell Demo']);
        Product::factory()->create(['category_id' => $category->id, 'brand_id' => $brand->id]);

        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonPath('data.0.category.name', 'Batteries')
            ->assertJsonPath('data.0.brand.name', 'PowerCell Demo');
    }
}
