<?php

declare(strict_types=1);

namespace Database\Seeders\Development;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Database\Seeders\DevelopmentSeeder;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Obviously fake catalog data for local development.
 *
 * Every name carries "Demo" so it can never be mistaken for real business
 * data. GST rates vary deliberately (5% / 18% / 28%) so intra/inter-state tax
 * behaviour has something meaningful to exercise in the invoicing slice.
 *
 * Idempotent: keyed on slug and SKU, so re-running updates rather than
 * duplicating. `current_stock` stays 0 -- stock arrives via the Inventory
 * ledger, never here.
 */
class DemoCatalogSeeder extends Seeder
{
    /**
     * @var list<array{name: string, slug: string, description: string}>
     */
    private const CATEGORIES = [
        ['name' => 'Electric Scooters', 'slug' => 'electric-scooters', 'description' => 'Demo category for electric two-wheelers.'],
        ['name' => 'Batteries', 'slug' => 'batteries', 'description' => 'Demo category for lithium-ion battery packs.'],
        ['name' => 'Accessories', 'slug' => 'accessories', 'description' => 'Demo category for chargers, helmets and spares.'],
    ];

    /**
     * @var list<array{name: string, slug: string}>
     */
    private const BRANDS = [
        ['name' => 'JPopular Demo', 'slug' => 'jpopular-demo'],
        ['name' => 'VoltRide Demo', 'slug' => 'voltride-demo'],
        ['name' => 'PowerCell Demo', 'slug' => 'powercell-demo'],
    ];

    /**
     * @var list<array<string, mixed>>
     */
    private const PRODUCTS = [
        [
            'sku' => 'DEMO-SCT-S1',
            'name' => 'Demo Electric Scooter S1',
            'category' => 'electric-scooters',
            'brand' => 'voltride-demo',
            'model' => 'S1-2026',
            'unit' => 'pcs',
            'hsn_code' => '87116020',
            'gst_rate' => '5.00',
            'purchase_price' => '68000.00',
            'selling_price' => '84999.00',
            'min_stock_level' => '2.000',
        ],
        [
            'sku' => 'DEMO-SCT-S2',
            'name' => 'Demo Electric Scooter S2 Pro',
            'category' => 'electric-scooters',
            'brand' => 'voltride-demo',
            'model' => 'S2-PRO-2026',
            'unit' => 'pcs',
            'hsn_code' => '87116020',
            'gst_rate' => '5.00',
            'purchase_price' => '92000.00',
            'selling_price' => '119999.00',
            'min_stock_level' => '2.000',
        ],
        [
            'sku' => 'DEMO-BAT-48V',
            'name' => 'Demo Lithium Battery 48V 30Ah',
            'category' => 'batteries',
            'brand' => 'powercell-demo',
            'model' => 'PC-48-30',
            'unit' => 'pcs',
            'hsn_code' => '85076000',
            // Batteries sold separately attract a different rate to the
            // vehicle -- exactly why gst_rate is per product.
            'gst_rate' => '18.00',
            'purchase_price' => '18500.00',
            'selling_price' => '24999.00',
            'min_stock_level' => '4.000',
        ],
        [
            'sku' => 'DEMO-BAT-60V',
            'name' => 'Demo Lithium Battery 60V 40Ah',
            'category' => 'batteries',
            'brand' => 'powercell-demo',
            'model' => 'PC-60-40',
            'unit' => 'pcs',
            'hsn_code' => '85076000',
            'gst_rate' => '18.00',
            'purchase_price' => '26500.00',
            'selling_price' => '34999.00',
            'min_stock_level' => '3.000',
        ],
        [
            'sku' => 'DEMO-ACC-CHG',
            'name' => 'Demo Fast Charger 48V',
            'category' => 'accessories',
            'brand' => 'powercell-demo',
            'model' => 'CHG-48-FAST',
            'unit' => 'pcs',
            'hsn_code' => '85044030',
            'gst_rate' => '18.00',
            'purchase_price' => '2200.00',
            'selling_price' => '3499.00',
            'min_stock_level' => '6.000',
        ],
        [
            'sku' => 'DEMO-ACC-HLM',
            'name' => 'Demo Riding Helmet',
            'category' => 'accessories',
            'brand' => 'jpopular-demo',
            'model' => 'HLM-STD',
            'unit' => 'pcs',
            'hsn_code' => '65061010',
            'gst_rate' => '18.00',
            'purchase_price' => '750.00',
            'selling_price' => '1299.00',
            'min_stock_level' => '10.000',
        ],
        [
            'sku' => 'DEMO-ACC-MIR',
            'name' => 'Demo Mirror Set',
            'category' => 'accessories',
            'brand' => 'jpopular-demo',
            'model' => 'MIR-PAIR',
            'unit' => 'set',
            'hsn_code' => '87141090',
            'gst_rate' => '28.00',
            'purchase_price' => '320.00',
            'selling_price' => '649.00',
            'min_stock_level' => '8.000',
        ],
        [
            'sku' => 'DEMO-ACC-WIRE',
            'name' => 'Demo Wiring Harness Cable',
            'category' => 'accessories',
            'brand' => null,
            'model' => null,
            'unit' => 'metre',
            'hsn_code' => '85443000',
            'gst_rate' => '18.00',
            'purchase_price' => '45.00',
            'selling_price' => '89.00',
            'min_stock_level' => '25.000',
        ],
    ];

    public function run(): void
    {
        if (! DevelopmentSeeder::isSafeEnvironment()) {
            throw new RuntimeException(
                'DemoCatalogSeeder refused to run outside a development environment.'
            );
        }

        $categories = $this->seedCategories();
        $brands = $this->seedBrands();
        $this->seedProducts($categories, $brands);
    }

    /**
     * @return array<string, int>
     */
    private function seedCategories(): array
    {
        $map = [];

        foreach (self::CATEGORIES as $data) {
            $category = Category::withTrashed()->firstOrNew(['slug' => $data['slug']]);

            if ($category->trashed()) {
                $category->restore();
            }

            $category->name = $data['name'];
            $category->description = $data['description'];
            $category->is_active = true;
            $category->save();

            $map[$data['slug']] = (int) $category->getKey();
        }

        $this->command?->info('  demo categories: '.count($map));

        return $map;
    }

    /**
     * @return array<string, int>
     */
    private function seedBrands(): array
    {
        $map = [];

        foreach (self::BRANDS as $data) {
            $brand = Brand::withTrashed()->firstOrNew(['slug' => $data['slug']]);

            if ($brand->trashed()) {
                $brand->restore();
            }

            $brand->name = $data['name'];
            $brand->is_active = true;
            $brand->save();

            $map[$data['slug']] = (int) $brand->getKey();
        }

        $this->command?->info('  demo brands: '.count($map));

        return $map;
    }

    /**
     * @param  array<string, int>  $categories
     * @param  array<string, int>  $brands
     */
    private function seedProducts(array $categories, array $brands): void
    {
        $count = 0;

        foreach (self::PRODUCTS as $data) {
            $product = Product::withTrashed()->firstOrNew(['sku' => $data['sku']]);

            if ($product->trashed()) {
                $product->restore();
            }

            $product->category_id = $categories[$data['category']];
            $product->brand_id = $data['brand'] === null ? null : $brands[$data['brand']];
            $product->name = $data['name'];
            $product->model = $data['model'];
            $product->description = 'Demo product for local development only.';
            $product->unit = $data['unit'];
            $product->hsn_code = $data['hsn_code'];
            $product->gst_rate = $data['gst_rate'];
            $product->purchase_price = $data['purchase_price'];
            $product->selling_price = $data['selling_price'];
            $product->min_stock_level = $data['min_stock_level'];
            $product->is_active = true;

            // Stock is owned by the Inventory ledger. Only set on first insert,
            // so re-seeding never clobbers a balance the ledger established.
            if (! $product->exists) {
                $product->current_stock = '0';
            }

            $product->save();
            $count++;
        }

        $this->command?->info("  demo products: {$count}");
        $this->command?->line('  (stock is 0 — opening stock belongs to the Inventory stage)');
    }

    /**
     * @return list<string>
     */
    public static function demoSkus(): array
    {
        return array_map(static fn (array $p): string => (string) $p['sku'], self::PRODUCTS);
    }
}
