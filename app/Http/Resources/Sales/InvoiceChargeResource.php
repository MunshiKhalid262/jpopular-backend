<?php

declare(strict_types=1);

namespace App\Http\Resources\Sales;

use App\Models\InvoiceCharge;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An additional charge snapshotted onto an invoice.
 *
 * Taxed at its own rate with its own SAC code, so it appears as its own row in
 * the GST summary rather than being folded into the goods.
 *
 * @mixin InvoiceCharge
 */
class InvoiceChargeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'description' => $this->description,
            'hsn_code' => $this->hsn_code,

            'taxable_amount' => $this->taxable_amount,
            'gst_rate' => $this->gst_rate,

            'cgst_rate' => $this->cgst_rate,
            'cgst_amount' => $this->cgst_amount,
            'sgst_rate' => $this->sgst_rate,
            'sgst_amount' => $this->sgst_amount,
            'igst_rate' => $this->igst_rate,
            'igst_amount' => $this->igst_amount,

            'tax_amount' => $this->tax_amount,
            'total' => $this->total,
            'sort_order' => $this->sort_order,
        ];
    }
}
