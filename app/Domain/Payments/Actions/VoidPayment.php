<?php

declare(strict_types=1);

namespace App\Domain\Payments\Actions;

use App\Domain\Payments\Exceptions\PaymentException;
use App\Domain\Payments\Services\InvoiceBalance;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Voids a payment.
 *
 * The row is NOT deleted: a payment that was recorded and then reversed is
 * part of the financial history, and a receipt may already be in a customer's
 * hands. It is marked voided with a reason and stops counting towards the
 * invoice balance.
 */
final class VoidPayment
{
    public function __construct(private readonly InvoiceBalance $balance) {}

    public function handle(Payment $payment, string $reason, User $actor): Payment
    {
        if ($payment->isVoided()) {
            throw PaymentException::alreadyVoided();
        }

        return DB::transaction(function () use ($payment, $reason, $actor): Payment {
            // Lock the invoice, not just the payment: the cached balance is on
            // the invoice, and a concurrent payment must not interleave.
            /** @var Invoice $invoice */
            $invoice = Invoice::query()->whereKey($payment->invoice_id)->lockForUpdate()->firstOrFail();

            $payment->forceFill([
                'voided_at' => now(),
                'voided_by' => $actor->getKey(),
                'void_reason' => $reason,
            ])->save();

            $this->balance->refresh($invoice);

            return $payment->refresh();
        });
    }
}
