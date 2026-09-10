<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The DOCUMENT lifecycle. Payment state lives separately in PaymentStatus,
 * because a cancelled invoice that was partly paid needs both facts.
 */
enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Finalized = 'finalized';
    case Cancelled = 'cancelled';

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
            self::Draft => 'Draft',
            self::Finalized => 'Finalized',
            self::Cancelled => 'Cancelled',
        };
    }

    /** Only a draft may be edited or deleted. */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }
}
