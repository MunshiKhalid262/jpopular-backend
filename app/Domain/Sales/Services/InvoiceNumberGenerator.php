<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services;

use App\Enums\InvoiceType;
use App\Models\BusinessSettings;
use App\Models\DocumentSequence;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Allocates sequential, concurrency-safe invoice numbers.
 *
 * The number is NEVER derived from MAX(invoice_number) + 1 or COUNT(*) + 1:
 * under two concurrent finalizations both reads return the same value and
 * produce a duplicate. Instead a counter row per series is locked with
 * SELECT ... FOR UPDATE, so allocation is serialised by the database.
 *
 * Format: {prefix}/{financial year}/{zero-padded number}, e.g.
 * `JP/2026-27/00001`. The prefix comes from business settings, and the
 * financial-year boundary from `financial_year_start_month`, so neither is
 * hard-coded.
 *
 * GST requires the number to be <= 16 characters using alphanumerics, `/` and
 * `-` only.
 *
 * NOTE ON PADDING: 5 digits, not 6. A 6-digit counter with the full financial
 * year ("JP/2026-27/000001") is 17 characters and would breach the GST limit.
 * Five digits gives 99,999 invoices per financial year, which is far beyond
 * this business's volume, and lands on exactly 16 characters with a two-
 * character prefix. A longer prefix will not fit this format;
 * `assertWithinGstLimit()` refuses it with that instruction rather than
 * quietly issuing an invalid number.
 */
final class InvoiceNumberGenerator
{
    public const TYPE = 'invoice';

    private const PAD_LENGTH = 5;

    private const GST_MAX_LENGTH = 16;

    /**
     * MUST be called inside an open transaction: the row lock has to be held
     * until the invoice itself is committed, or two finalizations could reuse
     * the same number.
     */
    public function next(
        DateTimeInterface|string|null $invoiceDate = null,
        InvoiceType $invoiceType = InvoiceType::Customer,
    ): string {
        $settings = BusinessSettings::current();

        /*
         * Dealer and customer invoices run in SEPARATE series, each with its
         * own prefix and its own counter row, so the two document streams can
         * be reconciled independently and neither leaves gaps in the other.
         */
        $prefix = $invoiceType === InvoiceType::Dealer
            ? (trim((string) $settings->dealer_invoice_prefix) ?: 'JD')
            : (trim((string) $settings->invoice_prefix) ?: 'JP');

        $sequenceType = $invoiceType->sequenceKey();

        $date = $invoiceDate === null ? Carbon::now() : Carbon::parse($invoiceDate);

        $financialYear = $this->financialYear($date, (int) $settings->financial_year_start_month);

        // firstOrCreate then lock: a brand-new series has no row to lock yet.
        DocumentSequence::query()->firstOrCreate(
            ['type' => $sequenceType, 'prefix' => $prefix, 'financial_year' => $financialYear],
            ['last_number' => 0],
        );

        /** @var DocumentSequence $sequence */
        $sequence = DocumentSequence::query()
            ->where('type', $sequenceType)
            ->where('prefix', $prefix)
            ->where('financial_year', $financialYear)
            ->lockForUpdate()
            ->firstOrFail();

        $next = $sequence->last_number + 1;
        $sequence->last_number = $next;
        $sequence->save();

        $number = sprintf(
            '%s/%s/%s',
            $prefix,
            $financialYear,
            str_pad((string) $next, self::PAD_LENGTH, '0', STR_PAD_LEFT),
        );

        $this->assertWithinGstLimit($number);

        return $number;
    }

    /**
     * The Indian financial year containing `$date`, as `2026-27`.
     *
     * With the default start month of April, 2026-03-31 belongs to 2025-26 and
     * 2026-04-01 to 2026-27.
     */
    public function financialYear(DateTimeInterface|string $date, int $startMonth = 4): string
    {
        $moment = Carbon::parse($date);
        $startMonth = max(1, min(12, $startMonth));

        $startYear = $moment->month >= $startMonth ? $moment->year : $moment->year - 1;

        return sprintf('%d-%02d', $startYear, ($startYear + 1) % 100);
    }

    /**
     * @throws RuntimeException
     */
    private function assertWithinGstLimit(string $number): void
    {
        if (mb_strlen($number) > self::GST_MAX_LENGTH) {
            throw new RuntimeException(sprintf(
                'Invoice number [%s] is %d characters; GST allows at most %d. Shorten the invoice prefix in business settings.',
                $number,
                mb_strlen($number),
                self::GST_MAX_LENGTH,
            ));
        }

        if (preg_match('#^[A-Za-z0-9/\-]+$#', $number) !== 1) {
            throw new RuntimeException(sprintf(
                'Invoice number [%s] contains characters GST does not permit. Use letters, digits, / and - only.',
                $number,
            ));
        }
    }
}
