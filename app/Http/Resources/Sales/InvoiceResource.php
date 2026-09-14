<?php

declare(strict_types=1);

namespace App\Http\Resources\Sales;

use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Invoice
 */
class InvoiceResource extends JsonResource
{
    /**
     * Money is emitted as DECIMAL-cast STRINGS, never numbers, so no precision
     * is lost crossing JSON into JavaScript.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_number' => $this->invoice_number,
            'financial_year' => $this->financial_year,
            'invoice_date' => $this->invoice_date?->toDateString(),

            'tax_type' => $this->tax_type->value,
            'tax_type_label' => $this->tax_type->label(),
            'status' => $this->status->value,
            'payment_status' => $this->payment_status->value,
            'supply_type' => $this->supply_type?->value,

            'subtotal' => $this->subtotal,
            'discount_amount' => $this->discount_amount,
            'taxable_amount' => $this->taxable_amount,
            'cgst_amount' => $this->cgst_amount,
            'sgst_amount' => $this->sgst_amount,
            'igst_amount' => $this->igst_amount,
            'total_tax' => $this->total_tax,
            'round_off' => $this->round_off,
            'grand_total' => $this->grand_total,
            'paid_amount' => $this->paid_amount,
            'due_amount' => $this->due_amount,

            'notes' => $this->notes,
            'terms' => $this->terms,

            'customer' => $this->whenLoaded(
                'customer',
                fn (): ?array => $this->customer === null ? null : [
                    'id' => $this->customer->id,
                    'name' => $this->customer->name,
                    'phone' => $this->customer->phone,
                    'gstin' => $this->customer->gstin,
                    'state_code' => $this->customer->state_code,
                ],
            ),
            'customer_id' => $this->customer_id,

            'items' => InvoiceItemResource::collection($this->whenLoaded('items')),

            'cancellation_reason' => $this->cancellation_reason,
            'finalized_at' => $this->finalized_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
