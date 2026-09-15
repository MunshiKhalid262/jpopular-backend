<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Date-range parsing for every report and dashboard metric, in one place.
 *
 * WHY THIS EXISTS: `app.timezone` is UTC, but the business runs on IST. A sale
 * at 02:00 on the 15th in Kerala is 20:30 on the 14th in UTC, so "today's
 * sales" computed in UTC would put the morning's takings on yesterday and
 * "this month" would be wrong for the first and last five and a half hours of
 * every month.
 *
 * Every boundary here is therefore built in the BUSINESS timezone and then
 * converted to UTC for the query, because that is how the timestamps are
 * stored. `config('app.business_timezone')` defaults to Asia/Kolkata and can be
 * overridden with BUSINESS_TIMEZONE without touching app.timezone, which would
 * reinterpret every existing stored timestamp.
 *
 * A date filter always covers the WHOLE of both end dates: from 00:00:00.000 on
 * the first to 23:59:59.999 on the last, so an invoice raised at 6pm on the
 * closing date is inside the range rather than just outside it.
 */
final class BusinessPeriod
{
    public function __construct(
        public readonly Carbon $start,
        public readonly Carbon $end,
    ) {}

    public static function timezone(): string
    {
        return (string) config('app.business_timezone', 'Asia/Kolkata');
    }

    /** "Now", as the business experiences it. */
    public static function now(): Carbon
    {
        return Carbon::now(self::timezone());
    }

    public static function today(): self
    {
        $now = self::now();

        return new self($now->copy()->startOfDay(), $now->copy()->endOfDay());
    }

    public static function thisMonth(): self
    {
        $now = self::now();

        return new self($now->copy()->startOfMonth(), $now->copy()->endOfMonth());
    }

    /** The last `$days` days INCLUDING today, oldest first. */
    public static function lastDays(int $days): self
    {
        $now = self::now();

        return new self(
            $now->copy()->subDays(max(1, $days) - 1)->startOfDay(),
            $now->copy()->endOfDay(),
        );
    }

    /**
     * A caller-supplied range, defaulting to the current month when absent.
     *
     * Inputs are plain dates ("2026-09-01") interpreted in the business
     * timezone, never as UTC instants.
     */
    public static function fromRequest(Request $request, string $fromKey = 'date_from', string $toKey = 'date_to'): self
    {
        $tz = self::timezone();

        $from = $request->filled($fromKey)
            ? Carbon::parse($request->string($fromKey)->toString(), $tz)->startOfDay()
            : null;

        $to = $request->filled($toKey)
            ? Carbon::parse($request->string($toKey)->toString(), $tz)->endOfDay()
            : null;

        if ($from === null && $to === null) {
            return self::thisMonth();
        }

        $from ??= $to->copy()->startOfMonth();
        $to ??= self::now()->endOfDay();

        // A reversed range is a slip, not an empty report.
        if ($from->greaterThan($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        return new self($from, $to);
    }

    /**
     * The boundaries as UTC instants, for comparing against stored timestamps.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function utcBounds(): array
    {
        return [$this->start->copy()->utc(), $this->end->copy()->utc()];
    }

    /** Plain business-timezone dates, for labels and filenames. */
    public function startDate(): string
    {
        return $this->start->toDateString();
    }

    public function endDate(): string
    {
        return $this->end->toDateString();
    }

    /**
     * Bounds for filtering a `date` column.
     *
     * The upper bound carries a time of 23:59:59 rather than being a bare
     * date, and that matters: SQLite stores a date column as
     * "2026-09-15 00:00:00", so comparing it against the bare string
     * "2026-09-15" excludes the whole of the closing day by string ordering.
     * A single-day range would come back empty.
     *
     * Bounds rather than whereDate(): wrapping the column in DATE() would stop
     * the index on invoice_date being used.
     *
     * @return array{0: string, 1: string}
     */
    public function dateColumnBounds(): array
    {
        return [
            $this->start->format('Y-m-d').' 00:00:00',
            $this->end->format('Y-m-d').' 23:59:59',
        ];
    }

    /** For filenames and report headings. */
    public function label(): string
    {
        return $this->startDate().'-to-'.$this->endDate();
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'from' => $this->startDate(),
            'to' => $this->endDate(),
            'timezone' => self::timezone(),
        ];
    }
}
