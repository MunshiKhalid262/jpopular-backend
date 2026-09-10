<?php

declare(strict_types=1);

namespace App\Http\Resources\Inventory;

use App\Models\Product;
use App\Support\StockStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the inventory stock summary.
 *
 * Deliberately narrower than ProductResource: this is the stock view, so it
 * carries no pricing at all. That also keeps `purchase_price` out of the
 * payload without needing the permission check ProductResource performs.
 *
 * @mixin Product
 */
class StockSummaryResource extends JsonResource
{
    /**
     * Quantities are DECIMAL-cast STRINGS, never numbers, so no precision is
     * lost crossing JSON into JavaScript.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'sku' => $this->sku,
            'unit' => $this->unit,

            'current_stock' => $this->current_stock,
            'min_stock_level' => $this->min_stock_level,

            // Derived on every read from current_stock and min_stock_level.
            // Never stored, so it cannot drift.
            'stock_status' => StockStatus::for($this->resource),

            'is_active' => $this->is_active,

            'category' => $this->whenLoaded(
                'category',
                fn (): ?array => $this->category === null ? null : [
                    'id' => $this->category->id,
                    'name' => $this->category->name,
                ],
            ),
            'brand' => $this->whenLoaded(
                'brand',
                fn (): ?array => $this->brand === null ? null : [
                    'id' => $this->brand->id,
                    'name' => $this->brand->name,
                ],
            ),

            'category_id' => $this->category_id,
            'brand_id' => $this->brand_id,
        ];
    }
}
