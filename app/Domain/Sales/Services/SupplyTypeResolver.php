<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services;

use App\Domain\Sales\Exceptions\GstAddressRequiredException;
use App\Enums\SupplyType;
use App\Models\BusinessSettings;
use App\Models\Customer;

/**
 * Decides whether a GST invoice is intra-state (CGST + SGST) or inter-state
 * (IGST), by comparing the seller's state with the place of supply.
 *
 * SAFE RULE, applied deliberately rather than guessing:
 *
 *   A GST invoice cannot be finalized unless BOTH state codes are known.
 *   Defaulting to intra-state would silently charge CGST+SGST on what may be
 *   an inter-state supply, understating IGST and misfiling the return. So a
 *   missing state code is a hard, explained refusal, not a fallback.
 *
 * A NON-GST bill needs no state at all and never reaches this class.
 */
final class SupplyTypeResolver
{
    /**
     * @throws GstAddressRequiredException
     */
    public function resolve(?Customer $customer): SupplyType
    {
        $settings = BusinessSettings::current();

        if (! $settings->canIssueGstInvoices()) {
            throw GstAddressRequiredException::businessStateMissing();
        }

        if ($customer === null) {
            // A walk-in with no record has no place of supply. Non-GST billing
            // is the correct mode for an anonymous counter sale.
            throw GstAddressRequiredException::customerRequired();
        }

        if (! $customer->canBeBilledWithGst()) {
            throw GstAddressRequiredException::customerStateMissing($customer->name);
        }

        return $this->compare((string) $settings->state_code, (string) $customer->state_code);
    }

    /** Exposed separately so it can be unit-tested without touching settings. */
    public function compare(string $sellerStateCode, string $placeOfSupplyStateCode): SupplyType
    {
        return $sellerStateCode === $placeOfSupplyStateCode
            ? SupplyType::IntraState
            : SupplyType::InterState;
    }
}
