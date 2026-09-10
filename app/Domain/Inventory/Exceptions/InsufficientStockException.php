<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Exceptions;

use App\Exceptions\BusinessRuleException;

/**
 * Raised when a movement would drive stock below zero.
 *
 * A 409 with a machine code rather than a 500: this is a legitimate business
 * outcome the UI must handle, not an outage.
 */
final class InsufficientStockException extends BusinessRuleException
{
    public static function for(
        string $productName,
        string $sku,
        string $available,
        string $requested,
        string $unit,
    ): self {
        return new self(
            sprintf(
                'Not enough stock for %s (%s): %s %s available, %s %s requested.',
                $productName,
                $sku,
                rtrim(rtrim($available, '0'), '.') ?: '0',
                $unit,
                rtrim(rtrim($requested, '0'), '.') ?: '0',
                $unit,
            ),
            'INSUFFICIENT_STOCK',
        );
    }
}
