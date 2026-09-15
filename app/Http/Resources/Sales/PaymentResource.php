<?php

declare(strict_types=1);

namespace App\Http\Resources\Sales;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Payment
 */
class PaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_id' => $this->invoice_id,

            'amount' => $this->amount,
            'payment_method' => $this->payment_method->value,
            'payment_method_label' => $this->payment_method->label(),
            'reference' => $this->reference,
            'note' => $this->note,

            // A voided payment stays in the history but counts towards nothing.
            'is_voided' => $this->isVoided(),
            'voided_at' => $this->voided_at?->toIso8601String(),
            'void_reason' => $this->void_reason,

            'invoice' => $this->whenLoaded(
                'invoice',
                fn (): ?array => $this->invoice === null ? null : [
                    'id' => $this->invoice->id,
                    'invoice_number' => $this->invoice->invoice_number,
                    'customer_name' => $this->invoice->relationLoaded('customer')
                        ? $this->invoice->customer?->name
                        : null,
                ],
            ),

            'created_by' => $this->whenLoaded(
                'createdBy',
                fn (): ?array => $this->createdBy === null ? null : [
                    'id' => $this->createdBy->id,
                    'name' => $this->createdBy->name,
                ],
            ),

            'received_at' => $this->received_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
