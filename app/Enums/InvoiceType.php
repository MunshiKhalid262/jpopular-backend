<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Who the invoice is raised to.
 *
 * This is orthogonal to TaxType: a dealer invoice and a customer invoice can
 * each be GST or non-GST. The type decides the DOCUMENT, not the tax.
 *
 * A dealer supply moves goods by road, so its invoice carries the transport
 * and e-Way Bill details a driver must be able to show. A counter sale to a
 * walk-in customer carries none of that, and printing empty dispatch boxes on
 * a retail bill would be noise.
 */
enum InvoiceType: string
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
            self::Customer => 'Customer invoice',
            self::Dealer => 'Dealer invoice',
        };
    }

    /**
     * Whether the transport block and e-Way Bill page appear.
     */
    public function carriesTransportDetails(): bool
    {
        return $this === self::Dealer;
    }

    /**
     * Dealer and customer invoices run in SEPARATE numbered series, so the two
     * document streams can be reconciled independently.
     */
    public function sequenceKey(): string
    {
        return 'invoice_'.$this->value;
    }
}
