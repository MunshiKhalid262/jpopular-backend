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

            'archived_at' => $this->deleted_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
