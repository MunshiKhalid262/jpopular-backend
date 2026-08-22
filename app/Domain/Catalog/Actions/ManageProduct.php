<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Services\ProductImageStore;
use App\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

final class ManageProduct
{
    /**
     * Fields a catalog request may write. `current_stock` and `image_path` are
     * deliberately excluded -- stock belongs to the Inventory domain, and the
     * image path is derived from the stored file, never from client input.
     */
    private const WRITABLE = [
        'category_id',
        'brand_id',
        'name',
        'sku',
        'model',
        'description',
        'unit',
        'hsn_code',
        'gst_rate',
        'purchase_price',
        'selling_price',
        'min_stock_level',
        'is_active',
    ];

    public function __construct(private readonly ProductImageStore $images) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, ?UploadedFile $image = null): Product
    {
        return DB::transaction(function () use ($attributes, $image): Product {
            $product = new Product;

            $this->apply($product, $attributes);

            // Set explicitly rather than leaning on the column defaults: an
            // unset attribute would serialise as null in the response even
            // though the database stored the default.
            $product->is_active = $attributes['is_active'] ?? true;
            $product->min_stock_level = $attributes['min_stock_level'] ?? '0';

            // Stock always starts at zero. Opening stock is an Inventory
            // operation and must go through the ledger, not product creation.
            $product->current_stock = '0';

            if ($image !== null) {
                $product->image_path = $this->images->store($image);
            }

            $product->save();
            $product->refresh();

            return $product->load(['category', 'brand']);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(
        Product $product,
        array $attributes,
        ?UploadedFile $image = null,
        bool $removeImage = false,
    ): Product {
        $previousImage = $product->image_path;
        $replacedImage = false;

        $updated = DB::transaction(function () use ($product, $attributes, $image, $removeImage, &$replacedImage): Product {
            $this->apply($product, $attributes);

            if ($image !== null) {
                $product->image_path = $this->images->store($image);
                $replacedImage = true;
            } elseif ($removeImage) {
                $product->image_path = null;
                $replacedImage = true;
            }

            $product->save();

            return $product->load(['category', 'brand']);
        });

        // Delete the old file only AFTER the row is committed, so a failed
        // transaction never leaves a product pointing at a deleted image.
        if ($replacedImage && $previousImage !== null && $previousImage !== $updated->image_path) {
            $this->images->delete($previousImage);
        }

        return $updated;
    }

    /**
     * Archive (soft delete). The image file is retained: historical invoices
     * may still reference the product, and an archived product can be restored.
     */
    public function archive(Product $product): void
    {
        $product->delete();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function apply(Product $product, array $attributes): void
    {
        foreach (self::WRITABLE as $field) {
            if (! array_key_exists($field, $attributes)) {
                continue;
            }

            $value = $attributes[$field];

            // Trim the SKU but never change its case: it is a business-entered
            // identifier and silently rewriting it would surprise the operator.
            if ($field === 'sku' && is_string($value)) {
                $value = trim($value);
            }

            $product->{$field} = $value;
        }
    }
}
