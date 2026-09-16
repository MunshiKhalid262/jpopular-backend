<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Amounts spelled out in the Indian system, as an invoice requires.
 *
 * Indian numbering, not international: 2,91,245 is "Two Lakh Ninety One
 * Thousand Two Hundred Forty Five", never "Two Hundred Ninety One Thousand".
 *
 * The amount arrives as a DECIMAL STRING and is split into rupees and paise by
 * string manipulation -- never through a float, which would eventually spell
 * out a figure the invoice does not show.
 */
final class AmountInWords
{
    private const ONES = [
        '', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine',
        'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen',
        'Seventeen', 'Eighteen', 'Nineteen',
    ];

    private const TENS = [
        '', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety',
    ];

    /**
     * "291245.00" -> "INR Two Lakh Ninety One Thousand Two Hundred Forty Five Only"
     */
    public static function rupees(?string $amount, string $currency = 'INR'): string
    {
        [$rupees, $paise] = self::split($amount);

        if ($rupees === '0' && $paise === 0) {
            return trim($currency.' Zero Only');
        }

        $words = self::indian($rupees);

        if ($paise > 0) {
            // "and Fifty Six paise", matching the convention on the tax line.
            $words .= ' and '.self::below1000((string) $paise).' paise';
        }

        return trim($currency.' '.$words.' Only');
    }

    /**
     * The tax-amount wording, which names paise even when there are none.
     */
    public static function tax(?string $amount, string $currency = 'INR'): string
    {
        return self::rupees($amount, $currency);
    }

    /**
     * @return array{0: string, 1: int} whole rupees as a digit string, paise as an int
     */
    private static function split(?string $amount): array
    {
        $trimmed = trim((string) $amount);

        if (preg_match('/^-?\d+(\.\d+)?$/', $trimmed) !== 1) {
            return ['0', 0];
        }

        $unsigned = ltrim($trimmed, '-');
        [$whole, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');

        $whole = ltrim($whole, '0');

        return [
            $whole === '' ? '0' : $whole,
            (int) str_pad(substr($fraction, 0, 2), 2, '0'),
        ];
    }

    /**
     * Indian grouping: crore, lakh, thousand, then the last three digits.
     */
    private static function indian(string $digits): string
    {
        // Beyond 99 crore an invoice is not the problem to solve here.
        $value = $digits;

        $crore = self::chopRight($value, 7);
        $lakh = self::chopRight($crore['rest'], 5);
        $thousand = self::chopRight($lakh['rest'], 3);

        $parts = [];

        if ($crore['taken'] !== 0) {
            $parts[] = self::below1000((string) $crore['taken']).' Crore';
        }

        if ($lakh['taken'] !== 0) {
            $parts[] = self::below1000((string) $lakh['taken']).' Lakh';
        }

        if ($thousand['taken'] !== 0) {
            $parts[] = self::below1000((string) $thousand['taken']).' Thousand';
        }

        $remainder = (int) ($thousand['rest'] === '' ? '0' : $thousand['rest']);

        if ($remainder !== 0) {
            $parts[] = self::below1000((string) $remainder);
        }

        return implode(' ', $parts);
    }

    /**
     * Takes everything left of the last `$keep` digits.
     *
     * @return array{taken: int, rest: string}
     */
    private static function chopRight(string $digits, int $keep): array
    {
        if (strlen($digits) <= $keep) {
            return ['taken' => 0, 'rest' => $digits];
        }

        return [
            'taken' => (int) substr($digits, 0, strlen($digits) - $keep),
            'rest' => substr($digits, -$keep),
        ];
    }

    private static function below1000(string $number): string
    {
        $value = (int) $number;

        if ($value === 0) {
            return '';
        }

        $words = [];

        if ($value >= 100) {
            $words[] = self::ONES[intdiv($value, 100)].' Hundred';
            $value %= 100;
        }

        if ($value >= 20) {
            $words[] = self::TENS[intdiv($value, 10)];
            $value %= 10;
        }

        if ($value > 0) {
            $words[] = self::ONES[$value];
        }

        return implode(' ', array_filter($words));
    }
}
