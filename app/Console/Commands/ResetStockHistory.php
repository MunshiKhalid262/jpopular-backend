<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Inventory\Services\StockLedger;
use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Clears the stock movement ledger.
 *
 * For setting the shop up: once the trial invoices and adjustments have served
 * their purpose, the movement history is noise, and every report that reads it
 * is reporting on rehearsals.
 *
 * Two modes, because "clear the history" means two different things depending
 * on whether the stock on the shelf is real yet:
 *
 *   rebaseline  keeps today's quantities and gives each product ONE opening
 *               entry for them. The shelf is right, the history starts now.
 *
 *   zero        removes the quantities too, leaving every product at nothing,
 *               ready for opening stock to be entered properly.
 *
 * Either way the ledger and the cached `products.current_stock` agree
 * afterwards by construction, so this also repairs any drift between them.
 *
 * Archived products are included. Archiving hides a product from the catalog;
 * it does not mean its stock row should be left behind holding a figure
 * nothing explains.
 */
class ResetStockHistory extends Command
{
    protected $signature = 'inventory:reset-history
        {--mode= : rebaseline (keep quantities) or zero (clear them too)}
        {--dry-run : Report what would change and stop}
        {--force : Skip the confirmation}';

    protected $description = 'Clear the stock movement history, optionally keeping current quantities';

    public function handle(StockLedger $ledger): int
    {
        $mode = (string) $this->option('mode');

        if (! in_array($mode, ['rebaseline', 'zero'], true)) {
            $this->error('Pass --mode=rebaseline (keep quantities) or --mode=zero (clear them too).');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        /** @var Collection<int, Product> $products */
        $products = Product::withTrashed()->orderBy('id')->get();
        $movements = StockMovement::query()->count();

        $this->line('');
        $this->line("Movements to remove: <options=bold>{$movements}</>");
        $this->line('');

        $rows = [];

        foreach ($products as $product) {
            $ledgerSum = (string) (StockMovement::where('product_id', $product->getKey())->sum('quantity') ?: '0');
            $after = $mode === 'zero' ? '0.000' : (string) $product->current_stock;

            $rows[] = [
                $product->getKey(),
                mb_substr((string) $product->name, 0, 30).($product->trashed() ? ' [archived]' : ''),
                (string) $product->current_stock,
                $ledgerSum,
                $after,
            ];
        }

        $this->table(['#', 'Product', 'Stock now', 'Ledger now', 'Stock after'], $rows);

        if ($dryRun) {
            $this->info('Dry run: nothing was changed.');

            return self::SUCCESS;
        }

        $warning = $mode === 'zero'
            ? 'This deletes every stock movement AND sets every product to zero.'
            : 'This deletes every stock movement and replaces it with one opening entry per product.';

        if (! $this->option('force') && ! $this->confirm($warning.' Continue?', false)) {
            $this->line('Nothing was changed.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($products, $ledger, $mode): void {
            // What the shelf holds right now, before the ledger is emptied.
            $opening = $products->mapWithKeys(
                fn (Product $product): array => [$product->getKey() => (string) $product->current_stock],
            );

            StockMovement::query()->delete();

            // The cache is a sum of rows that no longer exist, so it has to go
            // back to zero before anything is written on top of it.
            Product::withTrashed()->update(['current_stock' => '0.000']);

            if ($mode === 'zero') {
                return;
            }

            foreach ($products as $product) {
                $quantity = $opening[$product->getKey()] ?? '0';

                if (bccomp($quantity, '0', 3) <= 0) {
                    continue;
                }

                $ledger->record([
                    'product' => $product->fresh(),
                    'type' => StockMovementType::OpeningStock,
                    'quantity' => $quantity,
                    'reference_type' => 'Reset',
                    'reference_id' => (int) $product->getKey(),
                    'user_id' => null,
                    'note' => 'Opening stock set when the movement history was cleared',
                ]);
            }
        });

        Log::warning('Stock history reset', [
            'mode' => $mode,
            'movements_removed' => $movements,
            'products' => $products->count(),
        ]);

        $this->line('');
        $this->info(sprintf(
            'Removed %d movement%s across %d product%s.',
            $movements,
            $movements === 1 ? '' : 's',
            $products->count(),
            $products->count() === 1 ? '' : 's',
        ));

        if ($mode === 'rebaseline') {
            $this->line('Each product with stock now has a single opening entry for it.');
        } else {
            $this->line('Every product is at zero. Enter opening stock from Inventory.');
        }

        return self::SUCCESS;
    }
}
