<?php

declare(strict_types=1);

namespace App\Http\Resources\Catalog;

use App\Domain\Catalog\Services\ProductImageStore;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    /**
     * Monetary and quantity fields are emitted as DECIMAL-cast STRINGS, not
     * numbers, so no precision is lost crossing JSON into JavaScript.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'sku' => $this->sku,
            'model' => $this->model,
            'description' => $this->description,
            'unit' => $this->unit,
            'hsn_code' => $this->hsn_code,

            'gst_rate' => $this->gst_rate,
            'selling_price' => $this->selling_price,

            /*
             * purchase_price is commercially sensitive: it reveals margin.
             * The key is omitted entirely -- not nulled -- for a caller without
             * products.view_purchase_price, so the value never leaves the
             * server. See ARCHITECTURE-V1.md section 8 and ProductPolicy.
             */
            $this->mergeWhen(
                $user !== null && $user->can('viewPurchasePrice', Product::class),
                fn (): array => ['purchase_price' => $this->purchase_price],
            ),

            'current_stock' => $this->current_stock,
            'min_stock_level' => $this->min_stock_level,

            'is_active' => $this->is_active,
            'image_url' => app(ProductImageStore::class)->url($this->image_path),

            'category' => $this->whenLoaded(
                'category',
                fn (): ?array => $this->category === null ? null : [
                    'id' => $this->category->id,
                    'name' => $this->category->name,
                    'slug' => $this->category->slug,
                ],
            ),
            'brand' => $this->whenLoaded(
                'brand',
                fn (): ?array => $this->brand === null ? null : [
                    'id' => $this->brand->id,
                    'name' => $this->brand->name,
                    'slug' => $this->brand->slug,
                ],
            ),

            'category_id' => $this->category_id,
            'brand_id' => $this->brand_id,

            'archived_at' => $this->deleted_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
