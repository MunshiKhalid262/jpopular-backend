<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services;

use App\Enums\SupplyType;
use App\Enums\TaxType;
use App\Models\BusinessSettings;
use App\Models\Invoice;
use App\Models\InvoiceCharge;
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
        $invoice->loadMissing(['items', 'charges', 'customer']);

        return new self($invoice, BusinessSettings::current());
    }

    public function isDealer(): bool
    {
        return $this->invoice->isDealerInvoice();
    }

    /** @return Collection<int, InvoiceCharge> */
    public function charges(): Collection
    {
        return $this->invoice->charges;
    }

    /** Total quantity across all lines, as the sample's "8 NOS" total. */
    public function totalQuantity(): string
    {
        $total = '0';

        foreach ($this->lines() as $item) {
            $total = bcadd($total, (string) $item->quantity, 3);
        }

        return $total;
    }

    /** The unit shown beside the quantity total, when every line shares one. */
    public function commonUnit(): ?string
    {
        $units = $this->lines()->pluck('unit')->unique();

        return $units->count() === 1 ? (string) $units->first() : null;
    }

    /**
     * Whether any line was priced inclusive of tax, which decides if the
     * "Rate (Incl. of Tax)" column is worth printing at all.
     */
    public function showsInclusiveRate(): bool
    {
        return $this->chargesGst()
            && (bool) $this->invoice->prices_include_tax
            && $this->lines()->contains(
                fn ($item): bool => bccomp((string) $item->unit_price_gross, (string) $item->unit_price, 2) !== 0
            );
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

        /*
         * Grouped by HSN/SAC, as a GST summary is read: the sample shows
         * 87116020 for the scooters and 997135 for the insurance service on
         * separate rows, each at its own rate. Charges are folded in for
         * exactly that reason.
         */
        $add = function (?string $hsn, string $rate, array $amounts) use (&$groups): void {
            $key = ($hsn ?? '-').'|'.$rate;

            $groups[$key] ??= [
                'hsn_code' => $hsn,
                'rate' => $rate,
                'taxable' => '0.00',
                'cgst_rate' => $amounts['cgst_rate'],
                'cgst' => '0.00',
                'sgst_rate' => $amounts['sgst_rate'],
                'sgst' => '0.00',
                'igst_rate' => $amounts['igst_rate'],
                'igst' => '0.00',
                'tax' => '0.00',
            ];

            foreach (['taxable', 'cgst', 'sgst', 'igst', 'tax'] as $field) {
                $groups[$key][$field] = bcadd($groups[$key][$field], $amounts[$field], 2);
            }
        };

        foreach ($this->lines() as $item) {
            $add((string) $item->hsn_code ?: null, (string) $item->gst_rate, [
                'taxable' => (string) $item->taxable_amount,
                'cgst_rate' => (string) $item->cgst_rate,
                'cgst' => (string) $item->cgst_amount,
                'sgst_rate' => (string) $item->sgst_rate,
                'sgst' => (string) $item->sgst_amount,
                'igst_rate' => (string) $item->igst_rate,
                'igst' => (string) $item->igst_amount,
                'tax' => (string) $item->tax_amount,
            ]);
        }

        foreach ($this->charges() as $charge) {
            $add((string) $charge->hsn_code ?: null, (string) $charge->gst_rate, [
                'taxable' => (string) $charge->taxable_amount,
                'cgst_rate' => (string) $charge->cgst_rate,
                'cgst' => (string) $charge->cgst_amount,
                'sgst_rate' => (string) $charge->sgst_rate,
                'sgst' => (string) $charge->sgst_amount,
                'igst_rate' => (string) $charge->igst_rate,
                'igst' => (string) $charge->igst_amount,
                'tax' => (string) $charge->tax_amount,
            ]);
        }

        ksort($groups);

        return array_values($groups);
    }

    /**
     * Column totals for the foot of the HSN summary.
     *
     * @return array<string, string>
     */
    public function taxSummaryTotals(): array
    {
        $totals = ['taxable' => '0.00', 'cgst' => '0.00', 'sgst' => '0.00', 'igst' => '0.00', 'tax' => '0.00'];

        foreach ($this->taxSummary() as $row) {
            foreach ($totals as $field => $value) {
                $totals[$field] = bcadd($value, $row[$field], 2);
            }
        }

        return $totals;
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
