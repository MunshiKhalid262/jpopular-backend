<?php

declare(strict_types=1);

namespace App\Http\Resources\Sales;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Customer
 */
class CustomerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,

            'address' => $this->address,
            'city' => $this->city,
            'state' => $this->state,
            'state_code' => $this->state_code,
            'pincode' => $this->pincode,

            'gstin' => $this->gstin,
            'notes' => $this->notes,
            'is_active' => $this->is_active,

            // Lets the invoice form warn BEFORE the operator builds a GST
            // invoice that cannot be finalized for want of a state code.
            'can_be_billed_with_gst' => $this->canBeBilledWithGst(),

            /*
             * Dealer dispatch defaults. The invoice form copies these in when
             * the dealer is chosen, and they stay editable on that invoice --
             * changing them there never writes back here.
             */
            'default_dispatched_through' => $this->default_dispatched_through,
            'default_destination' => $this->default_destination,
            'default_terms_of_delivery' => $this->default_terms_of_delivery,
            'default_mode_of_payment' => $this->default_mode_of_payment,

            'archived_at' => $this->deleted_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
