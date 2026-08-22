<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Units of measure for V1.
 *
 * The architecture keeps `products.unit` a simple string column and defers a
 * units module. This enum is only the validation allow-list plus a label map,
 * so the set stays consistent without introducing a table.
 *
 * Note for later: GST returns expect standard UQC codes. Promoting this to a
 * `units` table is listed as an open question in ARCHITECTURE-V1.md section 16.
 */
enum ProductUnit: string
{
    case Piece = 'pcs';
    case Set = 'set';
    case Box = 'box';
    case Metre = 'metre';

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
            self::Piece => 'Piece',
            self::Set => 'Set',
            self::Box => 'Box',
            self::Metre => 'Metre',
        };
    }
}
