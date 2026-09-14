<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Sales;

use App\Domain\Sales\Services\InvoiceDocument;
use App\Domain\Sales\Services\InvoicePdfRenderer;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Preview, download and print for an invoice.
 *
 * All three render the SAME template from the SAME snapshot data, so what the
 * operator previews is what prints and what downloads.
 *
 * NOTHING IS STORED. The PDF is generated on demand, streamed straight to the
 * client, and freed when the response ends -- no file under storage/, no S3
 * object, no path column, and so nothing to back up or clean up. The invoice
 * rows in MySQL are the source of truth; the PDF is a projection of them.
 */
class InvoiceDocumentController extends Controller
{
    public function __construct(private readonly InvoicePdfRenderer $renderer) {}

    /**
     * GET /invoices/{invoice}/pdf — download.
     */
    public function pdf(Invoice $invoice): Response
    {
        $this->authorize('print', $invoice);

        $document = InvoiceDocument::for($invoice);

        return response($this->renderer->render($document), 200, [
            'Content-Type' => 'application/pdf',
            // The filename is derived from the invoice number with every
            // unsafe character replaced, so no path can be smuggled through it
            // and no server path is revealed.
            'Content-Disposition' => 'attachment; filename="'.$document->filename().'"',
            // A finalized invoice is immutable, but a cancelled one changes
            // status, so a stale cached copy would misinform.
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    /**
     * GET /invoices/{invoice}/pdf?disposition=inline — view in the browser's
     * PDF viewer rather than downloading.
     */
    public function inlinePdf(Invoice $invoice): Response
    {
        $this->authorize('print', $invoice);

        $document = InvoiceDocument::for($invoice);

        return response($this->renderer->render($document), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$document->filename().'"',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    /**
     * GET /invoices/{invoice}/preview — the same document as HTML.
     *
     * Returned as HTML rather than JSON so the frontend can show it in an
     * iframe and print it directly, with no second rendering path that could
     * disagree with the PDF.
     */
    public function preview(Request $request, Invoice $invoice): Response
    {
        $this->authorize('print', $invoice);

        $document = InvoiceDocument::for($invoice);

        $media = $request->string('media')->toString() === 'print' ? 'print' : 'screen';

        return response()
            ->view(InvoicePdfRenderer::VIEW, $document->toViewData() + ['media' => $media])
            ->header('Cache-Control', 'private, no-store, max-age=0');
    }
}
