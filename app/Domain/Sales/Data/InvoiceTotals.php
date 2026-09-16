<?php

declare(strict_types=1);

namespace App\Domain\Sales\Data;

use App\Enums\SupplyType;
use App\Enums\TaxType;

/**
 * The computed result of a tax calculation: invoice-level totals plus one
 * computed line per input line.
 *
 * Every value is a decimal STRING, ready to persist to a DECIMAL column.
 */
final readonly class InvoiceTotals
{
    /**
     * @param  list<InvoiceLineTotals>  $lines
     * @param  list<InvoiceChargeTotals>  $charges
     */
    public function __construct(
        public TaxType $taxType,
        public ?SupplyType $supplyType,
        public array $lines,
        public array $charges,
        public string $subtotal,
        public string $discountAmount,
        public string $taxableAmount,
        public string $cgstAmount,
        public string $sgstAmount,
        public string $igstAmount,
        public string $totalTax,
        public string $roundOff,
        public string $grandTotal,
    ) {}
}
