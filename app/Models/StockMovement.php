<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StockMovementType;
use Database\Factories\StockMovementFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A row in the append-only stock ledger.
 *
 * Immutable by design: no updated_at, no soft deletes, and nothing in the
 * application updates or deletes a movement. Corrections are new compensating
 * rows so the history stays truthful.
 *
 * @property int $id
 * @property int $product_id
 * @property string $type
 * @property string $quantity signed: positive in, negative out
 * @property string $previous_stock
 * @property string $new_stock
 */
class StockMovement extends Model
{
    /** @use HasFactory<StockMovementFactory> */
    use HasFactory;

    // The ledger records its own insert time and has no updated_at.
    public const UPDATED_AT = null;

    /**
     * Written only by StockLedger, so nothing is mass-assignable from a
     * request.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => StockMovementType::class,
            'quantity' => 'decimal:3',
            'previous_stock' => 'decimal:3',
            'new_stock' => 'decimal:3',
            'unit_cost' => 'decimal:2',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @param  Builder<StockMovement>  $query
     * @return Builder<StockMovement>
     */
    public function scopeForProduct(Builder $query, int $productId): Builder
    {
        return $query->where('product_id', $productId);
    }

    /**
     * @param  Builder<StockMovement>  $query
     * @return Builder<StockMovement>
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    /** Human description of what this movement referenced, for the UI. */
    public function referenceLabel(): ?string
    {
        if ($this->reference_type === null) {
            return null;
        }

        return match ($this->reference_type) {
            'InvoiceItem' => 'Invoice item #'.$this->reference_id,
            'Invoice' => 'Invoice #'.$this->reference_id,
            default => $this->reference_type.' #'.$this->reference_id,
        };
    }
}
