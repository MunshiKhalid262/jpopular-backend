<?php

declare(strict_types=1);

namespace App\Domain\Sales\Data;

/**
 * A computed additional charge, ready to persist.
 *
 * Every value is a decimal STRING, as everywhere else in the tax engine.
 */
final readonly class InvoiceChargeTotals
{
    public function __construct(
        public string $description,
        public ?string $hsnCode,
        public string $taxableAmount,
        public string $gstRate,
        public string $cgstRate,
        public string $cgstAmount,
        public string $sgstRate,
        public string $sgstAmount,
        public string $igstRate,
        public string $igstAmount,
        public string $taxAmount,
        public string $total,
        public int $sortOrder,
    ) {}
}
