<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Display formatting for the decimal STRINGS the database returns.
 *
 * Every helper works by string manipulation and never calls floatval(), so no
 * displayed figure passes through PHP floating point. This mirrors
 * src/lib/money.ts on the frontend deliberately: an invoice rendered to PDF
 * and the same invoice rendered in the browser must not disagree by a paisa.
 */
final class DecimalFormat
{
    /**
     * @return array{negative: bool, integer: string, fraction: string}|null
     */
    private static function parse(string $value): ?array
    {
        $trimmed = trim($value);

        if (preg_match('/^-?\d+(\.\d+)?$/', $trimmed) !== 1) {
            return null;
        }

        $negative = str_starts_with($trimmed, '-');
        $unsigned = $negative ? substr($trimmed, 1) : $trimmed;
        [$integer, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');

        return ['negative' => $negative, 'integer' => $integer, 'fraction' => $fraction];
    }

    /** Indian digit grouping: last three digits, then groups of two. */
    private static function group(string $integer): string
    {
        if (strlen($integer) <= 3) {
            return $integer;
        }

        $lastThree = substr($integer, -3);
        $rest = substr($integer, 0, -3);

        return preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest).','.$lastThree;
    }

    /** "125000.00" -> "1,25,000.00" (no symbol; templates add it). */
    public static function amount(?string $value): string
    {
        if ($value === null || $value === '') {
            return '0.00';
        }

        $parsed = self::parse($value);

        if ($parsed === null) {
            return $value;
        }

        $fraction = str_pad(substr($parsed['fraction'], 0, 2), 2, '0');

        return ($parsed['negative'] ? '-' : '').self::group($parsed['integer']).'.'.$fraction;
    }

    /** "25.000" -> "25"   "1.500" -> "1.5" */
    public static function quantity(?string $value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }

        $parsed = self::parse($value);

        if ($parsed === null) {
            return $value;
        }

        $fraction = rtrim($parsed['fraction'], '0');
        $integer = self::group($parsed['integer']);

        return ($parsed['negative'] ? '-' : '').($fraction === '' ? $integer : $integer.'.'.$fraction);
    }

    /** "18.00" -> "18"   "2.50" -> "2.5" (templates add the % sign). */
    public static function rate(?string $value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }

        $parsed = self::parse($value);

        if ($parsed === null) {
            return $value;
        }

        $fraction = rtrim($parsed['fraction'], '0');

        return $fraction === '' ? $parsed['integer'] : $parsed['integer'].'.'.$fraction;
    }
}
