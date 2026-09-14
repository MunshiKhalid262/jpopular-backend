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
        return Pdf::loadView(self::VIEW, $document->toViewData() + ['media' => 'pdf'])
            ->setPaper('a4')
            ->output();
    }
}
