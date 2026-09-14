<?php

declare(strict_types=1);

namespace App\Http\Resources\Sales;

use App\Models\InvoiceItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A snapshotted line.
 *
 * Every field comes from the invoice_items row, never from the related
 * Product, so a renamed or repriced product cannot alter a historical
 * invoice. `product_id` is exposed as a reporting link only.
 *
 * @mixin InvoiceItem
 */
class InvoiceItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,

            'product_name' => $this->product_name,
            'sku' => $this->sku,
            'hsn_code' => $this->hsn_code,
            'unit' => $this->unit,

            'quantity' => $this->quantity,
            'unit_price' => $this->unit_price,
            'gst_rate' => $this->gst_rate,

            'line_subtotal' => $this->line_subtotal,
            'discount_amount' => $this->discount_amount,
            'taxable_amount' => $this->taxable_amount,

            'cgst_rate' => $this->cgst_rate,
            'cgst_amount' => $this->cgst_amount,
            'sgst_rate' => $this->sgst_rate,
            'sgst_amount' => $this->sgst_amount,
            'igst_rate' => $this->igst_rate,
            'igst_amount' => $this->igst_amount,

            'tax_amount' => $this->tax_amount,
            'line_total' => $this->line_total,
            'sort_order' => $this->sort_order,
        ];
    }
}
