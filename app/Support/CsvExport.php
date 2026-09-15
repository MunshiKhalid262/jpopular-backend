<?php

declare(strict_types=1);

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV downloads, generated on demand and never stored.
 *
 * Written straight to the output stream via php://output, so no file is
 * created under storage/, no path is persisted and there is nothing to clean
 * up afterwards. Rows are streamed from a generator rather than collected into
 * an array, so a large export does not first have to fit in memory.
 */
final class CsvExport
{
    /**
     * Characters that make a spreadsheet treat a cell as a FORMULA.
     *
     * A customer named "=cmd|' /C calc'!A0" would otherwise execute on open in
     * Excel. Any value starting with one of these is prefixed with a single
     * quote, which spreadsheets read as "this is text".
     *
     * `-` is included even though it also starts a negative number, so a
     * genuine "-500" exports as text. That is the right trade: a misread
     * number is a cosmetic problem, an executed formula is a security one, and
     * every monetary column here is already emitted unsigned.
     */
    private const FORMULA_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * @param  list<string>  $headers
     * @param  iterable<int, list<string|int|float|null>>  $rows
     */
    public static function download(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        $safeName = self::filename($filename);

        return response()->streamDownload(function () use ($headers, $rows): void {
            $handle = fopen('php://output', 'wb');

            /*
             * UTF-8 BOM. Excel otherwise reads a UTF-8 CSV as the system
             * codepage and mangles any non-ASCII text -- customer names, the
             * rupee sign -- while LibreOffice and Sheets are unaffected by it.
             */
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, array_map(self::escape(...), $headers));

            foreach ($rows as $row) {
                fputcsv($handle, array_map(self::escape(...), $row));
            }

            fclose($handle);
        }, $safeName, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    /**
     * Neutralises a value that a spreadsheet would otherwise treat as a
     * formula. Numbers and nulls pass through untouched.
     */
    public static function escape(string|int|float|null $value): string
    {
        if ($value === null) {
            return '';
        }

        if (! is_string($value)) {
            return (string) $value;
        }

        if ($value === '') {
            return '';
        }

        return in_array($value[0], self::FORMULA_PREFIXES, true) ? "'".$value : $value;
    }

    /**
     * A filesystem-safe download name.
     *
     * Everything outside [A-Za-z0-9._-] is replaced, so a report label can
     * never introduce a path separator or traversal sequence.
     */
    public static function filename(string $name): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name) ?? '';

        // Collapse runs of dots as well. A separator is already gone by this
        // point so "..' cannot traverse, but leaving it in produces names like
        // "a-..-b.csv" that merely look like an attempt.
        $safe = (string) preg_replace('/\.{2,}/', '-', $safe);
        $safe = trim((string) preg_replace('/-+/', '-', $safe), '-.');

        if ($safe === '') {
            $safe = 'export';
        }

        return str_ends_with($safe, '.csv') ? $safe : $safe.'.csv';
    }
}
