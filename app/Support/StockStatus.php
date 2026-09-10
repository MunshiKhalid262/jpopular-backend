<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Product;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * Stock status is DERIVED, never stored.
 *
 * A persisted status column would be a second source of truth that drifts the
 * moment a movement lands or `min_stock_level` is edited. It is computed from
 * `current_stock` and `min_stock_level` here, and this class also owns the
 * matching SQL so the list filter and the serialised value can never disagree.
 */
final class StockStatus
{
    public const OUT_OF_STOCK = 'out_of_stock';

    public const LOW_STOCK = 'low_stock';

    public const IN_STOCK = 'in_stock';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return [self::OUT_OF_STOCK, self::LOW_STOCK, self::IN_STOCK];
    }

    /**
     * Order matters: exhausted beats low, because zero stock is also at or
     * below any threshold and "Out of stock" is the more actionable label.
     */
    public static function for(Product $product): string
    {
        if (bccomp((string) $product->current_stock, '0', 3) <= 0) {
            return self::OUT_OF_STOCK;
        }

        if (bccomp((string) $product->current_stock, (string) $product->min_stock_level, 3) <= 0) {
            return self::LOW_STOCK;
        }

        return self::IN_STOCK;
    }

    /**
     * The same rule expressed in SQL, so filtering by status returns exactly
     * the rows that serialise with that status.
     *
     * @param  Builder<*>  $query
     * @return Builder<*>
     */
    public static function scope(Builder $query, string $status): Builder
    {
        return match ($status) {
            self::OUT_OF_STOCK => $query->where('current_stock', '<=', 0),

            self::LOW_STOCK => $query->where('current_stock', '>', 0)
                ->whereColumn('current_stock', '<=', 'min_stock_level'),

            self::IN_STOCK => $query->where('current_stock', '>', 0)
                ->whereColumn('current_stock', '>', 'min_stock_level'),

            default => $query,
        };
    }
}
