<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services;

use App\Enums\SupplyType;
use App\Enums\TaxType;
use App\Models\BusinessSettings;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Support\Collection;

/**
 * The renderable form of an invoice, shared by the PDF, the preview and the
 * print view so all three are the same document rendered three ways.
 *
 * HISTORICAL ACCURACY IS ENFORCED HERE. Every line value is read from the
 * invoice_items SNAPSHOT and never from the related Product. The product
 * relation is deliberately not loaded or touched: an invoice raised months ago
 * must show the name, price, HSN and rate actually charged, even after the
 * catalog has moved on or the product has been archived.
 *
 * The seller block is the one exception, read from current business settings.
 * That is intentional -- an address correction should appear on reprints --
 * and it is why the seller's GST state code is snapshotted onto the invoice
 * separately, since changing THAT would alter the tax treatment.
 */
final class InvoiceDocument
{
    private function __construct(
        public readonly Invoice $invoice,
        public readonly BusinessSettings $seller,
    ) {}

    public static function for(Invoice $invoice): self
    {
        // Only the snapshot rows and the customer are needed. `items.product`
        // is pointedly absent.
        $invoice->loadMissing(['items', 'customer']);

        return new self($invoice, BusinessSettings::current());
    }

    public function chargesGst(): bool
    {
        return $this->invoice->tax_type === TaxType::Gst;
    }

    public function isInterState(): bool
    {
        return $this->invoice->supply_type === SupplyType::InterState;
    }

    public function isCancelled(): bool
    {
        return $this->invoice->isCancelled();
    }

    /**
     * "Tax Invoice" is a GST term of art: using it on a bill that charges no
     * GST would misrepresent the document.
     */
    public function heading(): string
    {
        return $this->chargesGst() ? 'Tax Invoice' : 'Invoice';
    }

    /** @return Collection<int, InvoiceItem> */
    public function lines(): Collection
    {
        return $this->invoice->items;
    }

    /**
     * Whether any line carries an HSN code. A GST invoice with none would
     * otherwise render an empty column.
     */
    public function hasHsnCodes(): bool
    {
        return $this->lines()->contains(fn ($item): bool => filled($item->hsn_code));
    }

    /**
     * GST totals grouped by rate, as a GST return expects.
     *
     * @return list<array<string, string>>
     */
    public function taxSummary(): array
    {
        if (! $this->chargesGst()) {
            return [];
        }

        $groups = [];

        foreach ($this->lines() as $item) {
            $key = (string) $item->gst_rate;

            $groups[$key] ??= [
                'rate' => (string) $item->gst_rate,
                'taxable' => '0.00',
                'cgst' => '0.00',
                'sgst' => '0.00',
                'igst' => '0.00',
            ];

            foreach (['taxable' => 'taxable_amount', 'cgst' => 'cgst_amount', 'sgst' => 'sgst_amount', 'igst' => 'igst_amount'] as $target => $column) {
                $groups[$key][$target] = bcadd($groups[$key][$target], (string) $item->{$column}, 2);
            }
        }

        ksort($groups, SORT_NUMERIC);

        return array_values($groups);
    }

    /** Outstanding balance. Zero payments simply means the whole total is due. */
    public function balanceDue(): string
    {
        return bcsub((string) $this->invoice->grand_total, (string) $this->invoice->paid_amount, 2);
    }

    /**
     * A filesystem-safe download name derived from the invoice number.
     *
     * The number contains slashes ("JP/2026-27/00001"), which would be read as
     * path separators, so every character outside [A-Za-z0-9.-] is replaced
     * and runs are collapsed. A draft has no number yet and falls back to its
     * id, so the name is never empty.
     */
    public function filename(): string
    {
        $base = $this->invoice->invoice_number ?? ('draft-'.$this->invoice->getKey());

        $safe = preg_replace('/[^A-Za-z0-9.-]+/', '-', $base) ?? '';
        $safe = trim((string) preg_replace('/-+/', '-', $safe), '-.');

        return 'invoice-'.($safe === '' ? (string) $this->invoice->getKey() : $safe).'.pdf';
    }

    /**
     * @return array<string, mixed>
     */
    public function toViewData(): array
    {
        return ['doc' => $this, 'invoice' => $this->invoice, 'seller' => $this->seller];
    }
}
