<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ProductUnit;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category_id' => Category::factory(),
            'brand_id' => Brand::factory(),
            'name' => ucwords(fake()->words(3, true)),
            'sku' => 'SKU-'.Str::upper(Str::random(8)),
            'model' => Str::upper(Str::random(4)).'-'.fake()->numberBetween(100, 999),
            'description' => fake()->optional()->sentence(),
            'unit' => fake()->randomElement(ProductUnit::values()),
            'hsn_code' => (string) fake()->numberBetween(10000000, 99999999),
            // Prices as strings: never let a float near money.
            'gst_rate' => fake()->randomElement(['5.00', '12.00', '18.00', '28.00']),
            'purchase_price' => (string) fake()->numberBetween(500, 80000).'.00',
            'selling_price' => (string) fake()->numberBetween(800, 150000).'.00',
            'current_stock' => '0.000',
            'min_stock_level' => (string) fake()->numberBetween(0, 10).'.000',
            'is_active' => true,
            'image_path' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function withoutBrand(): static
    {
        return $this->state(fn (): array => ['brand_id' => null]);
    }
}
