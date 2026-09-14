<?php

declare(strict_types=1);

namespace App\Domain\Sales\Exceptions;

use App\Exceptions\BusinessRuleException;

/**
 * Raised when an invoice is asked to do something its current status forbids.
 *
 * A 409 with a machine code rather than a 500: editing a finalized invoice or
 * cancelling a draft is a legitimate outcome the UI must handle.
 */
final class InvoiceStateException extends BusinessRuleException
{
    public static function notDraft(): self
    {
        return new self(
            'Only a draft invoice can be edited. A finalized invoice is a legal document and cannot be changed -- cancel it and raise a new one.',
            'INVOICE_NOT_DRAFT',
        );
    }

    public static function alreadyFinalized(): self
    {
        return new self(
            'This invoice has already been finalized.',
            'INVOICE_ALREADY_FINALIZED',
        );
    }

    public static function notFinalized(): self
    {
        return new self(
            'Only a finalized invoice can be cancelled. Delete the draft instead.',
            'INVOICE_NOT_FINALIZED',
        );
    }

    public static function alreadyCancelled(): self
    {
        return new self(
            'This invoice has already been cancelled.',
            'INVOICE_ALREADY_CANCELLED',
        );
    }

    public static function noLines(): self
    {
        return new self(
            'An invoice needs at least one line before it can be finalized.',
            'INVOICE_HAS_NO_LINES',
        );
    }

    public static function cannotDeleteFinalized(): self
    {
        return new self(
            'A finalized invoice cannot be deleted. Cancel it instead, so the record and its number survive.',
            'INVOICE_CANNOT_BE_DELETED',
        );
    }
}
