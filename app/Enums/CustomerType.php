<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What kind of buyer this is.
 *
 * A dealer is still a customer -- same name, GSTIN, address and state code,
 * and invoices point at the same row -- so this is a flag on the customer
 * rather than a parallel entity. A walk-in who starts buying in bulk becomes a
 * dealer by changing this, not by being re-keyed somewhere else.
 *
 * What a dealer adds is DEFAULTS: the transporter, destination and payment
 * terms that repeat on every supply to them, so an operator is not retyping the
 * same dispatch details each time. The dealer is also the consignee -- goods go
 * to the party that bought them -- so there is no second address to store.
 */
enum CustomerType: string
{
    case Customer = 'customer';
    case Dealer = 'dealer';

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
            self::Customer => 'Customer',
            self::Dealer => 'Dealer',
        };
    }
}
