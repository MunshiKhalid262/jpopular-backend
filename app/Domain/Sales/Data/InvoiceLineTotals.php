<?php

declare(strict_types=1);

namespace App\Domain\Sales\Data;

use App\Models\Product;

/**
 * A fully computed invoice line. Every field is a decimal string ready to
 * persist, and the product snapshot is captured here so nothing downstream
 * needs to re-read the product.
 */
final readonly class InvoiceLineTotals
{
    public function __construct(
        public Product $product,
        public string $productName,
        public string $sku,
        public ?string $hsnCode,
        public string $unit,
        public string $quantity,
        public string $unitPrice,
        /** The product's configured rate. Informational on a non-GST bill. */
        public string $gstRate,
        public string $lineSubtotal,
        public string $discountAmount,
        public string $taxableAmount,
        public string $cgstRate,
        public string $cgstAmount,
        public string $sgstRate,
        public string $sgstAmount,
        public string $igstRate,
        public string $igstAmount,
        public string $taxAmount,
        public string $lineTotal,
        public int $sortOrder,
    ) {}
}
