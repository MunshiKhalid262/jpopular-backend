<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * A short code tying an API error response to its log entry.
 *
 * A 500 that says only "An unexpected error occurred" is untraceable: the
 * operator reports a failure, and there is no way to find which of the day's
 * log lines was theirs. Every server error now carries a reference that is
 * printed in the response AND written to the log, so `grep` finds the exact
 * stack trace in one step.
 *
 * Bound per request in the container rather than held in a static, so a queue
 * worker or a test run cannot leak one request's reference into the next.
 */
final class ErrorReference
{
    public const KEY = 'jpopular.error_reference';

    /**
     * The reference for the current request, generated once and reused.
     */
    public static function current(): string
    {
        if (! app()->bound(self::KEY)) {
            app()->instance(self::KEY, strtoupper(substr((string) Str::uuid(), 0, 8)));
        }

        return (string) app(self::KEY);
    }

    /** Forgets the current reference, so the next error gets a fresh one. */
    public static function forget(): void
    {
        if (app()->bound(self::KEY)) {
            app()->forgetInstance(self::KEY);
        }
    }
}
