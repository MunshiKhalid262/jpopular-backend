<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Sales;

use App\Domain\Payments\Actions\RecordPayment;
use App\Domain\Payments\Actions\VoidPayment;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\StorePaymentRequest;
use App\Http\Resources\Sales\PaymentResource;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    private const MAX_PER_PAGE = 100;

    /** GET /payments */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Payment::class);

        $payments = Payment::query()
            ->with(['invoice:id,invoice_number,customer_id', 'invoice.customer:id,name', 'createdBy:id,name'])
            ->when(
                $request->filled('invoice_id'),
                fn ($query) => $query->where('invoice_id', $request->integer('invoice_id'))
            )
            ->when(
                $request->filled('payment_method'),
                fn ($query) => $query->where('payment_method', $request->string('payment_method')->toString())
            )
            // Voided payments stay visible here so the history is truthful;
            // they simply do not count towards any total.
            ->when(
                $request->boolean('effective_only'),
                fn ($query) => $query->effective()
            )
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->paginate(perPage: min($request->integer('per_page', 25), self::MAX_PER_PAGE))
            ->withQueryString();

        return ApiResponse::success(PaymentResource::collection($payments));
    }

    /** POST /invoices/{invoice}/payments */
    public function store(StorePaymentRequest $request, Invoice $invoice, RecordPayment $action): JsonResponse
    {
        $payment = $action->handle(
            invoice: $invoice,
            attributes: [
                'amount' => (string) $request->validated('amount'),
                'payment_method' => PaymentMethod::from((string) $request->validated('payment_method')),
                'reference' => $request->validated('reference'),
                'note' => $request->validated('note'),
                'received_at' => $request->validated('received_at'),
            ],
            actor: $request->user(),
        );

        // Re-read so the client gets the committed balance, not a stale model.
        $invoice->refresh();

        return ApiResponse::success([
            'payment' => (new PaymentResource($payment->load(['createdBy:id,name'])))->resolve(),
            'invoice' => [
                'id' => $invoice->id,
                'grand_total' => $invoice->grand_total,
                'paid_amount' => $invoice->paid_amount,
                'due_amount' => $invoice->due_amount,
                'payment_status' => $invoice->payment_status->value,
            ],
        ], status: 201);
    }

    /** POST /payments/{payment}/void */
    public function void(Request $request, Payment $payment, VoidPayment $action): JsonResponse
    {
        $this->authorize('void', $payment);

        $validated = $request->validate([
            // Mandatory: the row survives, so it must say why it was reversed.
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $voided = $action->handle($payment, $validated['reason'], $request->user());

        return ApiResponse::success(new PaymentResource($voided->load(['createdBy:id,name'])));
    }

    /** Payment methods, from the enum rather than a duplicated list. */
    public function methods(): JsonResponse
    {
        $this->authorize('viewAny', Payment::class);

        return ApiResponse::success([
            'payment_methods' => array_map(
                fn (PaymentMethod $method): array => ['value' => $method->value, 'label' => $method->label()],
                PaymentMethod::cases(),
            ),
        ]);
    }
}
