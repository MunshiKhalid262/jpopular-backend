<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services;

use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Renders an invoice to PDF bytes, IN MEMORY ONLY.
 *
 * Nothing here writes to storage/app, storage/app/public, a temp file of our
 * own, or S3, and no PDF path is ever persisted. `output()` returns the
 * document as a string which the controller streams straight to the client;
 * once the response is sent, PHP frees it and nothing remains.
 *
 * This is deliberate: the finalized invoice and its item snapshots in MySQL
 * are the source of truth, and the PDF is a projection of them. Storing the
 * projection would create a second copy that can silently disagree with the
 * data, and would need its own backup, access control and cleanup.
 *
 * (dompdf may use the system temp directory internally for font metrics; that
 * is the library's own cache, not a stored invoice, and holds no invoice data.)
 */
final class InvoicePdfRenderer
{
    public const VIEW = 'invoices.document';

    /** Raw PDF bytes for the given document. */
    public function render(InvoiceDocument $document): string
    {
        /*
         * Embed only the glyphs the invoice actually uses.
         *
         * dompdf subsets by default; laravel-dompdf turns it back off. With it
         * off, all four DejaVu Sans faces are embedded whole -- 2.7 MB of font
         * data for an invoice that uses about a hundred characters, which is
         * ~95% of the file. A 1.6 MB invoice is slow to send over mobile data,
         * and messaging and mail clients routinely skip generating a preview
         * thumbnail for an attachment that large.
         *
         * The glyphs still render identically: subsetting drops the unused
         * ones, not the ones on the page. DejaVu is kept as the face because
         * the rupee sign is not in dompdf's built-in Helvetica.
         */
        return Pdf::setOption('isFontSubsettingEnabled', true)
            ->loadView(self::VIEW, $document->toViewData() + ['media' => 'pdf'])
            ->setPaper('a4')
            ->output();
    }
}
