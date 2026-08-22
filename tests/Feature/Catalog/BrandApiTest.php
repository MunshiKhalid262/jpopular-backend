<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Models\Brand;
use App\Models\Product;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\ApiTestCase;

class BrandApiTest extends ApiTestCase
{
    #[Test]
    public function unauthenticated_requests_are_rejected(): void
    {
        $brand = Brand::factory()->create();

        $this->getJson('/api/v1/brands')->assertStatus(401);
        $this->postJson('/api/v1/brands', [])->assertStatus(401);
        $this->getJson("/api/v1/brands/{$brand->id}")->assertStatus(401);
        $this->putJson("/api/v1/brands/{$brand->id}", [])->assertStatus(401);
        $this->deleteJson("/api/v1/brands/{$brand->id}")->assertStatus(401);
    }

    #[Test]
    public function an_admin_can_create_a_brand(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/v1/brands', ['name' => 'VoltRide Demo'])
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'VoltRide Demo')
            ->assertJsonPath('data.slug', 'voltride-demo')
            ->assertJsonPath('data.is_active', true);
    }

    #[Test]
    public function a_manager_can_view_but_not_manage_brands(): void
    {
        $brand = Brand::factory()->create();
        Sanctum::actingAs($this->manager());

        $this->getJson('/api/v1/brands')->assertOk();
        $this->getJson("/api/v1/brands/{$brand->id}")->assertOk();

        $this->postJson('/api/v1/brands', ['name' => 'Nope'])->assertStatus(403);
        $this->putJson("/api/v1/brands/{$brand->id}", ['name' => 'Nope'])->assertStatus(403);
        $this->deleteJson("/api/v1/brands/{$brand->id}")->assertStatus(403);
    }

    #[Test]
    public function creating_a_brand_validates_input(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/v1/brands', [])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['name']]);

        $this->postJson('/api/v1/brands', ['name' => str_repeat('b', 121)])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['name']]);
    }

    #[Test]
    public function an_admin_can_update_and_deactivate_a_brand(): void
    {
        Sanctum::actingAs($this->admin());
        $brand = Brand::factory()->create(['name' => 'Old Brand']);

        $this->putJson("/api/v1/brands/{$brand->id}", ['name' => 'New Brand', 'is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Brand')
            ->assertJsonPath('data.is_active', false);
    }

    #[Test]
    public function an_unused_brand_can_be_archived(): void
    {
        Sanctum::actingAs($this->admin());
        $brand = Brand::factory()->create();

        $this->deleteJson("/api/v1/brands/{$brand->id}")->assertOk();

        $this->assertSoftDeleted('brands', ['id' => $brand->id]);
    }

    #[Test]
    public function a_brand_in_use_cannot_be_archived(): void
    {
        Sanctum::actingAs($this->admin());
        $brand = Brand::factory()->create();
        Product::factory()->create(['brand_id' => $brand->id]);

        $this->deleteJson("/api/v1/brands/{$brand->id}")
            ->assertStatus(409)
            ->assertJsonPath('code', 'BRAND_IN_USE');

        $this->assertNotSoftDeleted('brands', ['id' => $brand->id]);
    }

    #[Test]
    public function the_list_supports_search_and_active_filtering(): void
    {
        Sanctum::actingAs($this->admin());

        Brand::factory()->create(['name' => 'PowerCell Findable']);
        Brand::factory()->count(2)->inactive()->create();

        $this->getJson('/api/v1/brands?search=Findable')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/brands?is_active=0')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    #[Test]
    public function a_brand_is_reusable_across_many_products(): void
    {
        Sanctum::actingAs($this->admin());
        $brand = Brand::factory()->create();
        Product::factory()->count(3)->create(['brand_id' => $brand->id]);

        $this->getJson("/api/v1/brands/{$brand->id}")
            ->assertOk()
            ->assertJsonPath('data.products_count', 3);
    }
}
