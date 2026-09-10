<?php

declare(strict_types=1);

namespace App\Domain\Sales\Exceptions;

use App\Exceptions\BusinessRuleException;

/**
 * Raised when a GST invoice cannot be finalized because the state information
 * needed to decide CGST+SGST vs IGST is missing.
 *
 * Deliberately a refusal rather than a default: guessing intra-state would
 * silently charge the wrong tax.
 */
final class GstAddressRequiredException extends BusinessRuleException
{
    public static function businessStateMissing(): self
    {
        return new self(
            'Your business state is not configured. Set the state in business settings before raising a GST invoice.',
            'BUSINESS_STATE_MISSING',
        );
    }

    public static function customerRequired(): self
    {
        return new self(
            'A GST invoice needs a customer with a state. Select or add a customer, or bill this sale as Non-GST.',
            'GST_CUSTOMER_REQUIRED',
        );
    }

    public static function customerStateMissing(string $customerName): self
    {
        return new self(
            sprintf(
                'No state recorded for %s. Add the state to their address before raising a GST invoice, or bill this sale as Non-GST.',
                $customerName,
            ),
            'CUSTOMER_STATE_MISSING',
        );
    }
}
