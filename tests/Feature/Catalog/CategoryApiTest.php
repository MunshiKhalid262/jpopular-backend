<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\Product;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\ApiTestCase;

class CategoryApiTest extends ApiTestCase
{
    #[Test]
    public function unauthenticated_requests_are_rejected(): void
    {
        $category = Category::factory()->create();

        $this->getJson('/api/v1/categories')->assertStatus(401);
        $this->postJson('/api/v1/categories', [])->assertStatus(401);
        $this->getJson("/api/v1/categories/{$category->id}")->assertStatus(401);
        $this->putJson("/api/v1/categories/{$category->id}", [])->assertStatus(401);
        $this->deleteJson("/api/v1/categories/{$category->id}")->assertStatus(401);
    }

    #[Test]
    public function an_admin_can_create_a_category(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/v1/categories', [
            'name' => 'Electric Scooters',
            'description' => 'Two-wheelers.',
        ])
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Electric Scooters')
            ->assertJsonPath('data.slug', 'electric-scooters')
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('categories', ['slug' => 'electric-scooters']);
    }

    #[Test]
    public function the_slug_is_derived_from_the_name_and_de_duplicated(): void
    {
        Sanctum::actingAs($this->admin());

        $first = $this->postJson('/api/v1/categories', ['name' => 'Batteries'])->assertStatus(201);
        $second = $this->postJson('/api/v1/categories', ['name' => 'Batteries'])->assertStatus(201);

        $this->assertSame('batteries', $first->json('data.slug'));
        // A slug is a lookup convenience, not a business identifier, so a
        // collision is suffixed rather than rejected.
        $this->assertSame('batteries-2', $second->json('data.slug'));
    }

    #[Test]
    public function a_slug_collides_with_an_archived_category_too(): void
    {
        Sanctum::actingAs($this->admin());

        $archived = Category::factory()->create(['slug' => 'accessories']);
        $archived->delete();

        $response = $this->postJson('/api/v1/categories', ['name' => 'Accessories'])->assertStatus(201);

        // The unique index spans soft-deleted rows, so reuse must be avoided.
        $this->assertNotSame('accessories', $response->json('data.slug'));
    }

    #[Test]
    public function a_manager_can_view_but_not_manage_categories(): void
    {
        $category = Category::factory()->create();
        Sanctum::actingAs($this->manager());

        // categories.view is a Manager default.
        $this->getJson('/api/v1/categories')->assertOk();
        $this->getJson("/api/v1/categories/{$category->id}")->assertOk();

        // categories.manage is not.
        $this->postJson('/api/v1/categories', ['name' => 'Nope'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'FORBIDDEN');
        $this->putJson("/api/v1/categories/{$category->id}", ['name' => 'Nope'])->assertStatus(403);
        $this->deleteJson("/api/v1/categories/{$category->id}")->assertStatus(403);

        $this->assertDatabaseMissing('categories', ['name' => 'Nope']);
    }

    #[Test]
    public function creating_a_category_validates_input(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/v1/categories', [])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['errors' => ['name']]);

        $this->postJson('/api/v1/categories', ['name' => str_repeat('a', 121)])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['name']]);
    }

    #[Test]
    public function an_admin_can_update_and_deactivate_a_category(): void
    {
        Sanctum::actingAs($this->admin());
        $category = Category::factory()->create(['name' => 'Old', 'is_active' => true]);

        $this->putJson("/api/v1/categories/{$category->id}", [
            'name' => 'New Name',
            'is_active' => false,
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.is_active', false);

        $this->assertFalse($category->fresh()->is_active);
    }

    #[Test]
    public function an_unused_category_can_be_archived(): void
    {
        Sanctum::actingAs($this->admin());
        $category = Category::factory()->create();

        $this->deleteJson("/api/v1/categories/{$category->id}")->assertOk();

        $this->assertSoftDeleted('categories', ['id' => $category->id]);
    }

    #[Test]
    public function a_category_in_use_by_a_product_cannot_be_archived(): void
    {
        Sanctum::actingAs($this->admin());
        $category = Category::factory()->create();
        Product::factory()->create(['category_id' => $category->id]);

        $this->deleteJson("/api/v1/categories/{$category->id}")
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'CATEGORY_IN_USE');

        $this->assertNotSoftDeleted('categories', ['id' => $category->id]);
    }

    #[Test]
    public function an_archived_product_still_pins_its_category(): void
    {
        Sanctum::actingAs($this->admin());
        $category = Category::factory()->create();
        $product = Product::factory()->create(['category_id' => $category->id]);
        $product->delete();

        // The FK is RESTRICT, so even a soft-deleted product blocks removal.
        $this->deleteJson("/api/v1/categories/{$category->id}")
            ->assertStatus(409)
            ->assertJsonPath('code', 'CATEGORY_IN_USE');
    }

    #[Test]
    public function a_category_cannot_become_its_own_parent(): void
    {
        Sanctum::actingAs($this->admin());
        $category = Category::factory()->create();

        $this->putJson("/api/v1/categories/{$category->id}", ['parent_id' => $category->id])
            ->assertStatus(409)
            ->assertJsonPath('code', 'CATEGORY_SELF_PARENT');
    }

    #[Test]
    public function a_circular_parent_chain_is_rejected(): void
    {
        Sanctum::actingAs($this->admin());

        $root = Category::factory()->create();
        $child = Category::factory()->create(['parent_id' => $root->id]);

        // root -> child would close the loop child -> root -> child.
        $this->putJson("/api/v1/categories/{$root->id}", ['parent_id' => $child->id])
            ->assertStatus(409)
            ->assertJsonPath('code', 'CATEGORY_CIRCULAR_PARENT');
    }

    #[Test]
    public function the_list_supports_search_active_filter_and_pagination(): void
    {
        Sanctum::actingAs($this->admin());

        Category::factory()->create(['name' => 'Findable Batteries']);
        Category::factory()->count(2)->inactive()->create();

        $this->getJson('/api/v1/categories?search=Findable')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Findable Batteries');

        $this->getJson('/api/v1/categories?is_active=0')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/v1/categories?per_page=2')
            ->assertOk()
            ->assertJsonPath('meta.pagination.per_page', 2)
            ->assertJsonPath('meta.pagination.total', 3);
    }

    #[Test]
    public function the_list_reports_how_many_products_use_each_category(): void
    {
        Sanctum::actingAs($this->admin());
        $category = Category::factory()->create();
        Product::factory()->count(2)->create(['category_id' => $category->id]);

        $this->getJson('/api/v1/categories')
            ->assertOk()
            ->assertJsonPath('data.0.products_count', 2);
    }
}
