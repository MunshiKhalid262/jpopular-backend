<?php

declare(strict_types=1);

namespace App\Domain\Payments\Actions;

use App\Domain\Payments\Exceptions\PaymentException;
use App\Domain\Payments\Services\InvoiceBalance;
use App\Enums\PaymentMethod;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Records one payment against a finalized invoice.
 *
 * The invoice row is LOCKED for the whole transaction, and the due amount is
 * recomputed from the locked row rather than from whatever the caller was
 * holding. Without that, two payments arriving together could both see the
 * same balance and jointly overpay the invoice.
 */
final class RecordPayment
{
    public function __construct(private readonly InvoiceBalance $balance) {}

    /**
     * @param  array{
     *     amount: string,
     *     payment_method: PaymentMethod,
     *     reference?: string|null,
     *     note?: string|null,
     *     received_at?: \DateTimeInterface|string|null,
     * }  $attributes
     */
    public function handle(Invoice $invoice, array $attributes, User $actor): Payment
    {
        return DB::transaction(function () use ($invoice, $attributes, $actor): Payment {
            /** @var Invoice $locked */
            $locked = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isCancelled()) {
                throw PaymentException::invoiceCancelled();
            }

            if (! $locked->isFinalized()) {
                throw PaymentException::invoiceNotFinalized();
            }

            $amount = bcadd((string) $attributes['amount'], '0', 2);
            $due = $this->balance->dueOn($locked);

            // Checked inside the lock, so concurrent payments cannot both pass.
            if (bccomp($amount, $due, 2) > 0) {
                throw PaymentException::exceedsDue($amount, $due);
            }

            $payment = new Payment;
            $payment->forceFill([
                'invoice_id' => $locked->getKey(),
                'amount' => $amount,
                'payment_method' => $attributes['payment_method']->value,
                'reference' => $attributes['reference'] ?? null,
                'note' => $attributes['note'] ?? null,
                'received_at' => $attributes['received_at'] ?? now(),
                'created_by' => $actor->getKey(),
            ])->save();

            $this->balance->refresh($locked);

            return $payment->refresh();
        });
    }
}
