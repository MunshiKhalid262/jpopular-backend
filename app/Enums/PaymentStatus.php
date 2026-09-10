<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * DERIVED from the payments table, never set by hand.
 *
 * Nobody "sets an invoice to paid"; they record a payment and the status
 * follows. Making it commandable invites drift from SUM(payments.amount).
 */
enum PaymentStatus: string
{
    case Unpaid = 'unpaid';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'Unpaid',
            self::PartiallyPaid => 'Partially paid',
            self::Paid => 'Paid',
        };
    }
}
