<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Whether a GST invoice is intra-state (CGST + SGST) or inter-state (IGST).
 *
 * Derived by comparing the seller's state code from business settings with the
 * place of supply, never entered by hand.
 */
enum SupplyType: string
{
    case IntraState = 'intra_state';
    case InterState = 'inter_state';

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
            self::IntraState => 'Intra-state (CGST + SGST)',
            self::InterState => 'Inter-state (IGST)',
        };
    }
}
