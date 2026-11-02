<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DocumentSequence;
use App\Models\Invoice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sends the invoice counters back to zero, so the next invoice is 00001.
 *
 * For setting the shop up: once the trial invoices are gone, continuing the
 * numbering from where the rehearsal left off means the first real invoice is
 * 00004 and every later one is permanently off by three.
 *
 * REFUSES if any invoice still holds a number in a series being reset,
 * INCLUDING a soft-deleted one. Rewinding the counter past a number that is
 * still on a row would hand the same number out twice, and `invoice_number` is
 * unique -- so the second finalize of the day would fail with a constraint
 * error nobody could explain. Better to refuse now and say why.
 */
class ResetInvoiceNumbering extends Command
{
    protected $signature = 'invoices:reset-numbering
        {--force : Skip the confirmation}';

    protected $description = 'Reset invoice counters so the next invoice is 00001';

    public function handle(): int
    {
        $sequences = DocumentSequence::query()->orderBy('type')->get();

        if ($sequences->isEmpty()) {
            $this->info('No counters exist yet; the next invoice is already 00001.');

            return self::SUCCESS;
        }

        $this->line('');
        $this->table(
            ['Type', 'Prefix', 'Financial year', 'Last number'],
            $sequences->map(fn (DocumentSequence $s): array => [
                $s->type, $s->prefix, $s->financial_year, $s->last_number,
            ])->all(),
        );

        // Any numbered invoice still on a row, archived or not.
        $held = Invoice::withTrashed()->whereNotNull('invoice_number')->count();

        if ($held > 0) {
            $this->error(sprintf(
                '%d invoice%s still hold%s a number. Resetting now would issue a number twice.',
                $held,
                $held === 1 ? '' : 's',
                $held === 1 ? 's' : '',
            ));
            $this->line('Delete those invoices first, then run this again.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm('Reset every counter to zero?', false)) {
            $this->line('Nothing was changed.');

            return self::SUCCESS;
        }

        DB::transaction(function (): void {
            DocumentSequence::query()->delete();
        });

        Log::warning('Invoice numbering reset', ['sequences_removed' => $sequences->count()]);

        $this->line('');
        $this->info('Counters cleared. The next invoice of each type will be 00001.');

        return self::SUCCESS;
    }
}
