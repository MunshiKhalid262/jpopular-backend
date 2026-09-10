<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\Services\StockLedger;
use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Records one operator-initiated stock movement: opening stock, stock in,
 * stock out, or an adjustment in either direction.
 *
 * `invoice_sale` and `invoice_cancel` are NOT reachable from here -- those are
 * written only by the invoice Actions, so stock can never move behind an
 * invoice's back. The Form Request restricts `type` to
 * StockMovementType::manualTypes().
 */
final class RecordStockMovement
{
    public function __construct(private readonly StockLedger $ledger) {}

    /**
     * @param  array{
     *     type: StockMovementType,
     *     quantity: string,
     *     note?: string|null,
     *     unit_cost?: string|null,
     *     occurred_at?: string|null,
     * }  $attributes
     */
    public function handle(Product $product, array $attributes, User $actor): StockMovement
    {
        return DB::transaction(fn (): StockMovement => $this->ledger->recordLocked([
            'product' => $product,
            'type' => $attributes['type'],
            'quantity' => $attributes['quantity'],
            'note' => $attributes['note'] ?? null,
            'unit_cost' => $attributes['unit_cost'] ?? null,
            'occurred_at' => $attributes['occurred_at'] ?? now(),
            'user_id' => (int) $actor->getKey(),
            // Manual movements have no source document; the note carries the
            // reason instead, and is mandatory for adjustments and stock-out.
            'reference_type' => null,
            'reference_id' => null,
        ]));
    }
}
