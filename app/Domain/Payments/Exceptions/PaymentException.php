<?php

declare(strict_types=1);

namespace App\Domain\Payments\Exceptions;

use App\Exceptions\BusinessRuleException;
use App\Support\DecimalFormat;

/**
 * Payment rules that a well-formed request can still violate.
 *
 * 409 with a machine code rather than a 500: taking a payment on a cancelled
 * invoice, or for more than is owed, are outcomes the UI must handle.
 */
final class PaymentException extends BusinessRuleException
{
    public static function exceedsDue(string $amount, string $due): self
    {
        return new self(
            sprintf(
                'That payment is more than the invoice still owes: %s due, %s offered.',
                DecimalFormat::amount($due),
                DecimalFormat::amount($amount),
            ),
            'PAYMENT_EXCEEDS_DUE',
        );
    }

    public static function invoiceNotFinalized(): self
    {
        return new self(
            'Payments can only be recorded against a finalized invoice.',
            'INVOICE_NOT_FINALIZED',
        );
    }

    public static function invoiceCancelled(): self
    {
        return new self(
            'This invoice has been cancelled, so it cannot take a payment.',
            'INVOICE_CANCELLED',
        );
    }

    public static function alreadyVoided(): self
    {
        return new self(
            'This payment has already been voided.',
            'PAYMENT_ALREADY_VOIDED',
        );
    }
}
