<?php

declare(strict_types=1);

namespace App\Domain\Payments\Services;

use App\Enums\PaymentStatus;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;

/**
 * Keeps an invoice's cached payment figures in step with its payment rows.
 *
 * `paid_amount` is a CACHE of SUM(payments.amount) over non-voided payments,
 * exactly as products.current_stock caches the stock ledger. This class is the
 * only thing that writes it, so the two cannot drift.
 *
 * Recomputed from the rows every time rather than incremented: an increment
 * that runs twice, or misses a void, is silently wrong forever, whereas a
 * recompute is self-correcting.
 */
final class InvoiceBalance
{
    /**
     * Recalculates paid_amount and payment_status from the invoice's effective
     * payments. Assumes the invoice row is already locked by the caller.
     */
    public function refresh(Invoice $invoice): Invoice
    {
        $paid = (string) ($invoice->payments()->effective()->sum('amount') ?: '0');
        $paid = bcadd($paid, '0', 2);

        $grandTotal = bcadd((string) $invoice->grand_total, '0', 2);

        $attributes = [
            'paid_amount' => $paid,
            'payment_status' => $this->statusFor($paid, $grandTotal)->value,
        ];

        /*
         * On MySQL `due_amount` is a STORED GENERATED column and writing to it
         * is an error. On SQLite it is a plain column that nothing else
         * maintains, so it has to be set here -- this is the "same code path"
         * the migration's comment refers to.
         */
        if (DB::getDriverName() !== 'mysql') {
            $attributes['due_amount'] = bcsub($grandTotal, $paid, 2);
        }

        $invoice->forceFill($attributes)->save();

        return $invoice;
    }

    /** Outstanding amount on the invoice as it currently stands. */
    public function dueOn(Invoice $invoice): string
    {
        return bcsub((string) $invoice->grand_total, (string) $invoice->paid_amount, 2);
    }

    private function statusFor(string $paid, string $grandTotal): PaymentStatus
    {
        if (bccomp($paid, '0', 2) <= 0) {
            return PaymentStatus::Unpaid;
        }

        // Fully paid at or above the total: an overpayment still reads as paid
        // rather than inventing a fourth state.
        return bccomp($paid, $grandTotal, 2) >= 0
            ? PaymentStatus::Paid
            : PaymentStatus::PartiallyPaid;
    }
}
