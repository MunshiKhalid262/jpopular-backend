<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Sales;

use App\Domain\Sales\Actions\CancelInvoice;
use App\Domain\Sales\Actions\FinalizeInvoice;
use App\Domain\Sales\Actions\ManageInvoice;
use App\Domain\Sales\Data\InvoiceChargeInput;
use App\Domain\Sales\Data\InvoiceLineInput;
use App\Enums\InvoiceStatus;
use App\Enums\TaxType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\StoreInvoiceRequest;
use App\Http\Requests\Sales\UpdateInvoiceRequest;
use App\Http\Resources\Sales\InvoiceResource;
use App\Models\Invoice;
use App\Models\Product;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    private const MAX_PER_PAGE = 100;

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Invoice::class);

        $invoices = Invoice::query()
            ->with('customer:id,name,phone')
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = '%'.$request->string('search')->toString().'%';

                $query->where(function ($inner) use ($term): void {
                    $inner->where('invoice_number', 'like', $term)
                        ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', $term)->orWhere('phone', 'like', $term));
                });
            })
            ->when(
                $request->filled('status') && in_array($request->string('status')->toString(), array_column(InvoiceStatus::cases(), 'value'), true),
                fn ($query) => $query->where('status', $request->string('status')->toString())
            )
            ->when(
                $request->filled('tax_type'),
                fn ($query) => $query->where('tax_type', $request->string('tax_type')->toString())
            )
            ->when(
                $request->filled('customer_id'),
                fn ($query) => $query->where('customer_id', $request->integer('customer_id'))
            )
            ->when(
                $request->filled('date_from'),
                fn ($query) => $query->whereDate('invoice_date', '>=', $request->date('date_from'))
            )
            ->when(
                $request->filled('date_to'),
                fn ($query) => $query->whereDate('invoice_date', '<=', $request->date('date_to'))
            )
            // Newest first, with id breaking ties so paging is deterministic
            // when several invoices share a date.
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->paginate(perPage: min($request->integer('per_page', 25), self::MAX_PER_PAGE))
            ->withQueryString();

        return ApiResponse::success(InvoiceResource::collection($invoices));
    }

    public function show(Invoice $invoice): JsonResponse
    {
        $this->authorize('view', $invoice);

        return ApiResponse::success(
            new InvoiceResource($invoice->load(['customer', 'items', 'charges']))
        );
    }

    public function store(StoreInvoiceRequest $request, ManageInvoice $action): JsonResponse
    {
        $invoice = $action->create(
            attributes: $request->invoiceAttributes(),
            lines: $request->lineInputs(),
            actor: $request->user(),
            invoiceDiscount: $request->invoiceDiscount(),
            charges: $request->chargeInputs(),
        );

        return ApiResponse::success(
            new InvoiceResource($invoice->load(['customer', 'items', 'charges'])),
            status: 201,
        );
    }

    public function update(UpdateInvoiceRequest $request, Invoice $invoice, ManageInvoice $action): JsonResponse
    {
        $updated = $action->update(
            invoice: $invoice,
            attributes: $request->invoiceAttributes(),
            lines: $request->lineInputs(),
            invoiceDiscount: $request->invoiceDiscount(),
            charges: $request->chargeInputs(),
        );

        return ApiResponse::success(new InvoiceResource($updated->load(['customer', 'items', 'charges'])));
    }

    /**
     * Deletes a DRAFT. A finalized invoice is cancelled instead, so its number
     * stays in the sequence.
     */
    public function destroy(Invoice $invoice, ManageInvoice $action): JsonResponse
    {
        $this->authorize('delete', $invoice);

        $action->delete($invoice);

        return ApiResponse::success(['message' => 'Draft invoice deleted.']);
    }

    /**
     * POST /invoices/{invoice}/finalize
     *
     * Re-reads the draft's own lines rather than accepting them from the
     * request: finalization must issue exactly the invoice that was reviewed,
     * not whatever the caller sends at the last moment.
     */
    public function finalize(Request $request, Invoice $invoice, FinalizeInvoice $action): JsonResponse
    {
        $this->authorize('finalize', $invoice);

        $lines = $invoice->items()->get()
            ->map(fn ($item, $index): InvoiceLineInput => new InvoiceLineInput(
                product: Product::query()->findOrFail($item->product_id),
                quantity: (string) $item->quantity,
                /*
                 * The price AS ENTERED, not the net price.
                 *
                 * Under tax-inclusive pricing `unit_price` is already the
                 * taxable rate the draft's composition backed out of the
                 * entered figure. Feeding that back in would divide by
                 * (1 + rate) a second time and quietly under-bill: 36,000
                 * would become 34,285.71 and then 32,653.06. `unit_price_gross`
                 * is stored precisely so re-composition is idempotent.
                 */
                unitPrice: (string) $item->unit_price_gross,
                sortOrder: (int) ($item->sort_order ?? $index),
            ))
            ->values()
            ->all();

        $finalized = $action->handle(
            invoice: $invoice,
            lines: $lines,
            actor: $request->user(),
            invoiceDiscount: (string) $invoice->discount_amount,
            // Re-read from the draft, like the lines: finalization issues the
            // invoice that was reviewed, not whatever arrives at the last moment.
            charges: $invoice->charges()->get()->map(fn ($charge, $i) => new InvoiceChargeInput(
                description: (string) $charge->description,
                amount: (string) $charge->taxable_amount,
                gstRate: (string) $charge->gst_rate,
                hsnCode: $charge->hsn_code,
                sortOrder: (int) ($charge->sort_order ?? $i),
            ))->values()->all(),
        );

        return ApiResponse::success(new InvoiceResource($finalized->load(['customer', 'items', 'charges'])));
    }

    /**
     * POST /invoices/{invoice}/cancel
     */
    public function cancel(Request $request, Invoice $invoice, CancelInvoice $action): JsonResponse
    {
        $this->authorize('cancel', $invoice);

        $validated = $request->validate([
            // Mandatory: a cancelled invoice keeps its number, so the record
            // must say why it was voided.
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $cancelled = $action->handle($invoice, $validated['reason'], $request->user());

        return ApiResponse::success(new InvoiceResource($cancelled->load(['customer', 'items', 'charges'])));
    }

    /** Supporting data for the invoice form. */
    public function meta(): JsonResponse
    {
        $this->authorize('viewAny', Invoice::class);

        return ApiResponse::success([
            'tax_types' => array_map(
                fn (TaxType $type): array => ['value' => $type->value, 'label' => $type->label()],
                TaxType::cases(),
            ),
            'statuses' => array_map(
                fn (InvoiceStatus $status): array => ['value' => $status->value, 'label' => $status->label()],
                InvoiceStatus::cases(),
            ),
        ]);
    }
}
