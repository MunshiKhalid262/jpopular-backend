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

            'invoice_type' => $this->invoice_type->value,
            'invoice_type_label' => $this->invoice_type->label(),
            // Snapshotted at creation, so a historical invoice reports the
            // pricing mode it was actually raised under.
            'prices_include_tax' => (bool) $this->prices_include_tax,

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
            'charges' => InvoiceChargeResource::collection($this->whenLoaded('charges')),

            /*
             * Transport and dispatch. Always present for a uniform payload
             * shape; only a dealer invoice fills them in.
             *
             * No consignee: the goods go to the party that bought them, so the
             * customer above is both bill-to and ship-to.
             */

            'eway_bill_no' => $this->eway_bill_no,
            'vehicle_no' => $this->vehicle_no,
            'dispatched_through' => $this->dispatched_through,
            'destination' => $this->destination,
            'lr_rr_no' => $this->lr_rr_no,
            'lr_rr_date' => $this->lr_rr_date?->toDateString(),
            'delivery_note' => $this->delivery_note,
            'delivery_note_date' => $this->delivery_note_date?->toDateString(),
            'dispatch_doc_no' => $this->dispatch_doc_no,
            'buyer_order_no' => $this->buyer_order_no,
            'buyer_order_date' => $this->buyer_order_date?->toDateString(),
            'terms_of_delivery' => $this->terms_of_delivery,
            'mode_of_payment' => $this->mode_of_payment,
            'other_references' => $this->other_references,

            // Typed in from the government portal; never generated here.
            'irn' => $this->irn,
            'ack_no' => $this->ack_no,
            'ack_date' => $this->ack_date?->toDateString(),

            'cancellation_reason' => $this->cancellation_reason,
            'finalized_at' => $this->finalized_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
