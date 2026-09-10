<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The billing mode, chosen EXPLICITLY by the operator on every invoice.
 *
 * Deliberately never inferred from whether the customer holds a GSTIN: a
 * GSTIN-holding buyer may still be billed without GST, and a retail buyer may
 * need a GST invoice. Inferring it would silently charge or omit tax.
 */
enum TaxType: string
{
    case Gst = 'gst';
    case NonGst = 'non_gst';

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
            self::Gst => 'GST Invoice',
            self::NonGst => 'Non-GST Bill',
        };
    }

    public function chargesTax(): bool
    {
        return $this === self::Gst;
    }
}
