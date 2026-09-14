<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services;

use App\Domain\Sales\Data\InvoiceLineInput;
use App\Domain\Sales\Data\InvoiceTotals;
use App\Enums\TaxType;
use App\Models\BusinessSettings;
use App\Models\Invoice;
use App\Models\InvoiceItem;

/**
 * Computes an invoice's totals and writes its lines.
 *
 * Shared by draft editing and by finalization so a draft's totals are produced
 * by exactly the same tax engine that will freeze them -- the figure an
 * operator sees while building the invoice is the figure that gets issued.
 *
 * WRITING THE LINES IS THE SNAPSHOT. Every displayed value (product name, SKU,
 * HSN, unit, price, GST rate) is copied onto invoice_items here and never read
 * from the live product again. `product_id` survives only as a reporting link.
 *
 * Assumes an open transaction.
 */
final class InvoiceComposer
{
    public function __construct(
        private readonly TaxCalculator $tax,
        private readonly SupplyTypeResolver $supplyTypes,
    ) {}

    /**
     * Recomputes totals from `$lines` and REPLACES the invoice's items.
     *
     * @param  list<InvoiceLineInput>  $lines
     */
    public function apply(Invoice $invoice, array $lines, string $invoiceDiscount = '0'): InvoiceTotals
    {
        $settings = BusinessSettings::current();

        /*
         * Resolved only for a GST invoice. SupplyTypeResolver refuses rather
         * than guessing when either state code is missing, because defaulting
         * to intra-state would charge CGST+SGST on what may be an inter-state
         * supply and misfile the return.
         */
        $supplyType = $invoice->tax_type === TaxType::Gst
            ? $this->supplyTypes->resolve($invoice->customer)
            : null;

        $totals = $this->tax->calculate(
            lines: $lines,
            taxType: $invoice->tax_type,
            supplyType: $supplyType,
            invoiceDiscount: $invoiceDiscount,
            roundOffEnabled: (bool) $settings->enable_round_off,
        );

        $invoice->forceFill([
            'supply_type' => $totals->supplyType?->value,
            'seller_state_code' => $invoice->tax_type === TaxType::Gst ? $settings->state_code : null,
            'place_of_supply_state_code' => $invoice->tax_type === TaxType::Gst
                ? $invoice->customer?->state_code
                : null,

            'subtotal' => $totals->subtotal,
            'discount_amount' => $totals->discountAmount,
            'taxable_amount' => $totals->taxableAmount,
            'cgst_amount' => $totals->cgstAmount,
            'sgst_amount' => $totals->sgstAmount,
            'igst_amount' => $totals->igstAmount,
            'total_tax' => $totals->totalTax,
            'round_off' => $totals->roundOff,
            'grand_total' => $totals->grandTotal,
        ])->save();

        // Replace wholesale rather than diffing: a draft's lines are
        // provisional, and a partial update risks a stale line surviving.
        $invoice->items()->delete();

        foreach ($totals->lines as $line) {
            $item = new InvoiceItem;

            $item->forceFill([
                'invoice_id' => $invoice->getKey(),
                'product_id' => $line->product->getKey(),

                'product_name' => $line->productName,
                'sku' => $line->sku,
                'hsn_code' => $line->hsnCode,
                'unit' => $line->unit,

                'quantity' => $line->quantity,
                'unit_price' => $line->unitPrice,
                'gst_rate' => $line->gstRate,

                'line_subtotal' => $line->lineSubtotal,
                'discount_amount' => $line->discountAmount,
                'taxable_amount' => $line->taxableAmount,

                'cgst_rate' => $line->cgstRate,
                'cgst_amount' => $line->cgstAmount,
                'sgst_rate' => $line->sgstRate,
                'sgst_amount' => $line->sgstAmount,
                'igst_rate' => $line->igstRate,
                'igst_amount' => $line->igstAmount,

                'tax_amount' => $line->taxAmount,
                'line_total' => $line->lineTotal,
                'sort_order' => $line->sortOrder,
            ])->save();
        }

        return $totals;
    }
}
