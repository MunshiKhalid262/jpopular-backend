<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProductUnit;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $category_id
 * @property int|null $brand_id
 * @property string $name
 * @property string $sku
 * @property string|null $model
 * @property string|null $description
 * @property string $unit
 * @property string|null $hsn_code
 * @property string $gst_rate
 * @property string|null $purchase_price
 * @property string $selling_price
 * @property string $current_stock
 * @property string $min_stock_level
 * @property bool $is_active
 * @property string|null $image_path
 */
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Explicit allow-list.
     *
     * `current_stock` is deliberately ABSENT and must stay absent: stock is
     * written only by the Inventory domain's StockLedger, inside a transaction
     * with the product row locked. Adding it here would let a catalog request
     * silently rewrite the cached balance and desynchronise it from the ledger.
     *
     * `image_path` is also absent -- it is set from the stored file, never from
     * client input, so a caller cannot point a product at an arbitrary path.
     *
     * @var list<string>
     */
    protected $fillable = [
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

    /**
     * Decimal casts return STRINGS, never floats, so money never passes
     * through PHP floating point. See ARCHITECTURE-V1.md section 2.5.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'gst_rate' => 'decimal:2',
            'purchase_price' => 'decimal:2',
            'selling_price' => 'decimal:2',
            'current_stock' => 'decimal:3',
            'min_stock_level' => 'decimal:3',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function unitEnum(): ?ProductUnit
    {
        return ProductUnit::tryFrom($this->unit);
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Stock at or below the reorder threshold, but not yet exhausted.
     * Used by Inventory and the dashboard in later slices.
     *
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeLowStock(Builder $query): Builder
    {
        return $query->whereColumn('current_stock', '<=', 'min_stock_level')
            ->where('current_stock', '>', 0);
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeOutOfStock(Builder $query): Builder
    {
        return $query->where('current_stock', '<=', 0);
    }
}
