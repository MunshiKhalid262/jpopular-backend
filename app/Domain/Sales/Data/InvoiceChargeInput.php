<?php

declare(strict_types=1);

namespace App\Domain\Sales\Data;

/**
 * An additional charge on the invoice — insurance, freight, handling.
 *
 * Optional, and taxed at ITS OWN GST rate with its own SAC code rather than
 * inheriting the goods' rate: insurance on a scooter sale is an 18% service
 * even when the scooter itself is 5%, and the GST summary has to show the two
 * separately.
 *
 * The amount is always the TAXABLE value of the charge. Unlike goods lines,
 * a charge is not entered tax-inclusive, because it is computed by the shop
 * rather than quoted to the customer as an MRP.
 */
final readonly class InvoiceChargeInput
{
    public function __construct(
        public string $description,
        public string $amount,
        public string $gstRate = '0',
        public ?string $hsnCode = null,
        public int $sortOrder = 0,
    ) {}
}
