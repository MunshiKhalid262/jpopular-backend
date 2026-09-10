<?php

declare(strict_types=1);

namespace App\Domain\Sales\Data;

use App\Models\Product;

/**
 * One requested invoice line, as it arrives from a validated request.
 *
 * `unitPrice` defaults to the product's selling price; a caller may only
 * override it when the actor holds the price-override permission, which the
 * Form Request enforces.
 */
final readonly class InvoiceLineInput
{
    public function __construct(
        public Product $product,
        public string $quantity,
        public string $unitPrice,
        public int $sortOrder = 0,
    ) {}
}
