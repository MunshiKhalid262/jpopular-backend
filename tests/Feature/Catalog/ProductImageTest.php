<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Services\ProductImageStore;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\ApiTestCase;

class ProductImageTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(ProductImageStore::DISK);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Demo Scooter',
            'sku' => 'IMG-SKU-1',
            'category_id' => Category::factory()->create()->id,
            'unit' => 'pcs',
            'gst_rate' => '18.00',
            'selling_price' => '1000.00',
        ], $overrides);
    }

    #[Test]
    public function a_valid_image_is_stored_and_exposed_as_a_url(): void
    {
        Sanctum::actingAs($this->admin());

        $response = $this->post('/api/v1/products', $this->payload([
            'image' => UploadedFile::fake()->image('scooter.jpg', 400, 300),
        ]))->assertStatus(201);

        $product = Product::firstOrFail();

        $this->assertNotNull($product->image_path);
        Storage::disk(ProductImageStore::DISK)->assertExists($product->image_path);

        // The response exposes a URL, never a filesystem path.
        $url = $response->json('data.image_url');
        $this->assertIsString($url);
        $this->assertStringNotContainsString('/home/', $url);
        $this->assertStringNotContainsString('storage/app', $url);
    }

    #[Test]
    public function the_stored_filename_is_generated_not_taken_from_the_client(): void
    {
        Sanctum::actingAs($this->admin());

        $this->post('/api/v1/products', $this->payload([
            'image' => UploadedFile::fake()->image('../../evil name.jpg'),
        ]))->assertStatus(201);

        $path = Product::firstOrFail()->image_path;

        $this->assertIsString($path);
        // No traversal, no spaces, no client-supplied name -- and always
        // confined to the managed directory.
        $this->assertStringStartsWith('products/', $path);
        $this->assertStringNotContainsString('..', $path);
        $this->assertStringNotContainsString(' ', $path);
        $this->assertStringNotContainsString('evil', $path);
    }

    #[Test]
    public function an_svg_upload_is_rejected(): void
    {
        Sanctum::actingAs($this->admin());

        // SVG can carry <script>, so it is excluded even though it is an image.
        $svg = UploadedFile::fake()->createWithContent(
            'logo.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
        );

        $this->post('/api/v1/products', $this->payload(['image' => $svg]))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['image']]);

        $this->assertSame(0, Product::count());
    }

    #[Test]
    public function a_non_image_file_is_rejected(): void
    {
        Sanctum::actingAs($this->admin());

        $php = UploadedFile::fake()->createWithContent('payload.php', '<?php echo "pwned";');

        $this->post('/api/v1/products', $this->payload(['image' => $php]))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['image']]);

        $this->assertSame(0, Product::count());
    }

    #[Test]
    public function an_oversized_image_is_rejected(): void
    {
        Sanctum::actingAs($this->admin());

        $tooBig = UploadedFile::fake()->image('huge.jpg')->size(4096); // 4 MB > 2 MB cap

        $this->post('/api/v1/products', $this->payload(['image' => $tooBig]))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['image']]);
    }

    #[Test]
    public function image_path_cannot_be_set_directly_through_the_api(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/v1/products', $this->payload([
            'image_path' => '../../../etc/passwd',
        ]))->assertStatus(201);

        // Ignored entirely: image_path is derived from a stored file only.
        $this->assertNull(Product::firstOrFail()->image_path);
    }

    #[Test]
    public function replacing_an_image_deletes_the_previous_file(): void
    {
        Sanctum::actingAs($this->admin());

        $this->post('/api/v1/products', $this->payload([
            'image' => UploadedFile::fake()->image('first.jpg'),
        ]))->assertStatus(201);

        $product = Product::firstOrFail();
        $firstPath = $product->image_path;
        Storage::disk(ProductImageStore::DISK)->assertExists($firstPath);

        $this->post("/api/v1/products/{$product->id}", [
            '_method' => 'PUT',
            'image' => UploadedFile::fake()->image('second.jpg'),
        ])->assertOk();

        $secondPath = $product->fresh()->image_path;

        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk(ProductImageStore::DISK)->assertExists($secondPath);
        // The orphan is cleaned up only after the row committed.
        Storage::disk(ProductImageStore::DISK)->assertMissing($firstPath);
    }

    #[Test]
    public function an_image_can_be_removed_explicitly(): void
    {
        Sanctum::actingAs($this->admin());

        $this->post('/api/v1/products', $this->payload([
            'image' => UploadedFile::fake()->image('first.jpg'),
        ]))->assertStatus(201);

        $product = Product::firstOrFail();
        $path = $product->image_path;

        $this->post("/api/v1/products/{$product->id}", [
            '_method' => 'PUT',
            'remove_image' => '1',
        ])->assertOk()->assertJsonPath('data.image_url', null);

        $this->assertNull($product->fresh()->image_path);
        Storage::disk(ProductImageStore::DISK)->assertMissing($path);
    }

    #[Test]
    public function a_product_without_an_image_reports_a_null_url(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/v1/products', $this->payload())
            ->assertStatus(201)
            ->assertJsonPath('data.image_url', null);
    }

    #[Test]
    public function archiving_a_product_keeps_its_image(): void
    {
        Sanctum::actingAs($this->admin());

        $this->post('/api/v1/products', $this->payload([
            'image' => UploadedFile::fake()->image('keep.jpg'),
        ]))->assertStatus(201);

        $product = Product::firstOrFail();
        $path = $product->image_path;

        $this->deleteJson("/api/v1/products/{$product->id}")->assertOk();

        // Archived, not destroyed: historical invoices may still show it.
        Storage::disk(ProductImageStore::DISK)->assertExists($path);
    }
}
