<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Exceptions\DuplicateMovementException;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * THE ONLY class permitted to write `stock_movements` or
 * `products.current_stock`.
 *
 * `products.current_stock` is a cache of SUM(stock_movements.quantity); this
 * class is what keeps the two in step. ARCHITECTURE-V1.md section 4.6 sets out
 * three rules, all enforced here:
 *
 *   1. Single writer -- nothing else touches either table.
 *   2. Same transaction, product row locked -- every mutation runs
 *      SELECT ... FOR UPDATE on the product, then inserts the movement and
 *      updates the cache atomically.
 *   3. Reconcilable -- `recalculate()` recomputes the cache from the ledger, so
 *      drift is a monitored invariant rather than an unfalsifiable worry.
 *
 * Callers MUST already be inside a transaction when recording several
 * movements that must succeed or fail together (invoice finalization does
 * this). `record()` opens its own transaction when called standalone.
 */
final class StockLedger
{
    /**
     * Applies one movement and returns it.
     *
     * @param  array{
     *     product: Product,
     *     type: StockMovementType,
     *     quantity: string,
     *     reference_type?: string|null,
     *     reference_id?: int|null,
     *     unit_cost?: string|null,
     *     note?: string|null,
     *     user_id?: int|null,
     *     occurred_at?: \DateTimeInterface|string|null,
     * }  $movement
     *
     * @throws InsufficientStockException
     */
    public function record(array $movement): StockMovement
    {
        return DB::transaction(fn (): StockMovement => $this->recordLocked($movement));
    }

    /**
     * The locked write. Assumes an open transaction.
     *
     * @param  array<string, mixed>  $movement
     *
     * @throws InsufficientStockException
     */
    public function recordLocked(array $movement): StockMovement
    {
        /** @var Product $product */
        $product = $movement['product'];
        /** @var StockMovementType $type */
        $type = $movement['type'];

        $magnitude = $this->normaliseQuantity((string) $movement['quantity']);

        if (bccomp($magnitude, '0', 3) <= 0) {
            throw new RuntimeException('A stock movement quantity must be greater than zero.');
        }

        // Re-read the product under a row lock. Everything below is computed
        // from THIS value, not from whatever the caller happened to hold.
        /** @var Product $locked */
        $locked = Product::query()
            ->whereKey($product->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        $previous = $this->normaliseQuantity((string) $locked->current_stock);

        // The enum owns the sign, so no caller has to remember it.
        $signed = $type->direction() === 1
            ? $magnitude
            : bcmul($magnitude, '-1', 3);

        $new = bcadd($previous, $signed, 3);

        // Stock may never go negative. Checked here, inside the lock, so two
        // concurrent sales cannot both see enough stock.
        if (bccomp($new, '0', 3) < 0) {
            throw InsufficientStockException::for(
                $locked->name,
                $locked->sku,
                $previous,
                $magnitude,
                $locked->unit,
            );
        }

        try {
            $record = new StockMovement;
            $record->product_id = (int) $locked->getKey();
            $record->type = $type;
            $record->quantity = $signed;
            $record->previous_stock = $previous;
            $record->new_stock = $new;
            $record->reference_type = $movement['reference_type'] ?? null;
            $record->reference_id = $movement['reference_id'] ?? null;
            $record->unit_cost = $movement['unit_cost'] ?? null;
            $record->note = $movement['note'] ?? null;
            $record->created_by = $movement['user_id'] ?? null;
            $record->occurred_at = $movement['occurred_at'] ?? now();
            $record->save();
        } catch (QueryException $exception) {
            // The UNIQUE(type, reference_type, reference_id) index fired: this
            // exact movement already exists. That is the idempotency
            // guarantee doing its job, so surface it as such rather than as a
            // database error.
            if ($this->isUniqueViolation($exception)) {
                throw new DuplicateMovementException(
                    'This stock movement has already been recorded.',
                    'DUPLICATE_STOCK_MOVEMENT',
                );
            }

            throw $exception;
        }

        // Cache update, same transaction as the ledger row.
        $locked->current_stock = $new;
        $locked->save();

        return $record;
    }

    /**
     * Recomputes the cached balance from the ledger.
     *
     * Returns the difference found, so `inventory:reconcile` can report drift
     * rather than silently repairing it.
     *
     * @return array{previous: string, recalculated: string, drifted: bool}
     */
    public function recalculate(Product $product, bool $fix = false): array
    {
        return DB::transaction(function () use ($product, $fix): array {
            /** @var Product $locked */
            $locked = Product::query()
                ->whereKey($product->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $cached = $this->normaliseQuantity((string) $locked->current_stock);

            $sum = (string) (StockMovement::query()
                ->where('product_id', $locked->getKey())
                ->sum('quantity') ?: '0');

            $recalculated = $this->normaliseQuantity($sum);
            $drifted = bccomp($cached, $recalculated, 3) !== 0;

            if ($drifted && $fix) {
                $locked->current_stock = $recalculated;
                $locked->save();
            }

            return [
                'previous' => $cached,
                'recalculated' => $recalculated,
                'drifted' => $drifted,
            ];
        });
    }

    /**
     * Locks several products in DETERMINISTIC id order and returns them keyed
     * by id.
     *
     * Ordering matters: two concurrent invoices touching the same two products
     * in opposite orders would deadlock. Sorting by id makes every caller
     * acquire them in the same sequence.
     *
     * @param  list<int>  $productIds
     * @return array<int, Product>
     */
    public function lockProducts(array $productIds): array
    {
        $unique = array_values(array_unique($productIds));
        sort($unique);

        return Product::query()
            ->whereIn('id', $unique)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id')
            ->all();
    }

    /** Quantities are DECIMAL(12,3) throughout; never a float. */
    private function normaliseQuantity(string $value): string
    {
        return bcadd($value === '' ? '0' : $value, '0', 3);
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        // 23000/23505 are the SQLSTATE integrity-constraint classes; the
        // message check covers SQLite, which reports 23000 for several things.
        return in_array($exception->getCode(), ['23000', '23505'], true)
            && str_contains(mb_strtolower($exception->getMessage()), 'unique');
    }
}
