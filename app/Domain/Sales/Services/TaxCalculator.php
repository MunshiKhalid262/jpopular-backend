<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services;

use App\Domain\Sales\Data\InvoiceChargeInput;
use App\Domain\Sales\Data\InvoiceChargeTotals;
use App\Domain\Sales\Data\InvoiceLineInput;
use App\Domain\Sales\Data\InvoiceLineTotals;
use App\Domain\Sales\Data\InvoiceTotals;
use App\Domain\Shared\Money;
use App\Enums\SupplyType;
use App\Enums\TaxType;

/**
 * The authoritative tax engine. Pure and side-effect free, so it is trivially
 * testable and reusable.
 *
 * ---------------------------------------------------------------------------
 * ORDER OF OPERATIONS (documented because it is a legal question, not just an
 * arithmetic one)
 *
 *   1. line subtotal        = quantity x unit price
 *   2. invoice discount     apportioned across lines PRO-RATA on line subtotal
 *   3. line taxable value   = line subtotal - that line's share of the discount
 *   4. GST                  computed on the DISCOUNTED taxable value
 *   5. per-line rounding    to 2 dp, then summed
 *   6. round-off            optional, to the nearest whole rupee
 *
 * Discount is applied BEFORE tax. Under Indian GST a discount shown on the
 * face of the invoice reduces the taxable value, so subtracting it after tax
 * would overstate the tax collected and misreport the liability.
 *
 * Tax is computed and rounded PER LINE and then summed, rather than taxing the
 * invoice total once. Per-line rounding is what the GST return expects, and
 * the two approaches differ by paise.
 * ---------------------------------------------------------------------------
 *
 * NON-GST: every tax field is zero. The line still records the product's
 * configured `gst_rate` for internal reference, but that rate cannot influence
 * any amount -- there is no code path from it to a total when taxType is
 * non_gst.
 */
final class TaxCalculator
{
    /**
     * @param  list<InvoiceLineInput>  $lines
     * @param  string  $invoiceDiscount  flat amount off the whole invoice
     * @param  bool  $pricesIncludeTax  the entered unit prices already contain GST
     * @param  list<InvoiceChargeInput>  $charges  optional extra charges, taxed at their own rate
     */
    public function calculate(
        array $lines,
        TaxType $taxType,
        ?SupplyType $supplyType,
        string $invoiceDiscount = '0',
        bool $roundOffEnabled = false,
        bool $pricesIncludeTax = false,
        array $charges = [],
    ): InvoiceTotals {
        // ---- 1. line subtotals and the invoice subtotal -----------------
        $subtotal = Money::zero();
        $lineSubtotals = [];
        $netUnitPrices = [];

        foreach ($lines as $index => $line) {
            /*
             * TAX-INCLUSIVE PRICING.
             *
             * When the entered price already contains GST, the taxable value
             * is backed out of it: 36,000 at 5% is 34,285.71 plus 1,714.29.
             *
             * The division happens PER UNIT and is rounded before multiplying
             * by the quantity, not the other way round. That ordering is what
             * makes the printed "Rate" column multiply cleanly to the printed
             * "Amount": 34,285.71 x 6 = 2,05,714.26, where dividing the gross
             * line total instead would give 2,05,714.29 and an invoice whose
             * own columns do not reconcile.
             */
            $netUnitPrice = $this->netUnitPrice(
                $line->unitPrice,
                (string) $line->product->gst_rate,
                $taxType,
                $pricesIncludeTax,
            );

            $netUnitPrices[$index] = $netUnitPrice;

            $lineSubtotal = $netUnitPrice->times($line->quantity)->round();
            $lineSubtotals[$index] = $lineSubtotal;
            $subtotal = $subtotal->plus($lineSubtotal);
        }

        $subtotal = $subtotal->round();

        // A discount can never exceed the subtotal, which would make the
        // taxable value negative.
        $discount = Money::of($invoiceDiscount)->round();

        if ($discount->isGreaterThan($subtotal)) {
            $discount = $subtotal;
        }

        // ---- 2 & 3. apportion the discount, derive taxable values -------
        $lineDiscounts = $this->apportionDiscount($lineSubtotals, $discount, $subtotal);

        // ---- 4 & 5. tax per line ----------------------------------------
        $computedLines = [];
        $taxableTotal = Money::zero();
        $cgstTotal = Money::zero();
        $sgstTotal = Money::zero();
        $igstTotal = Money::zero();

        foreach ($lines as $index => $line) {
            $lineSubtotal = $lineSubtotals[$index];
            $lineDiscount = $lineDiscounts[$index];
            $taxable = $lineSubtotal->minus($lineDiscount)->round();

            $productRate = (string) $line->product->gst_rate;

            $cgstRate = Money::zero();
            $sgstRate = Money::zero();
            $igstRate = Money::zero();
            $cgst = Money::zero();
            $sgst = Money::zero();
            $igst = Money::zero();

            if ($taxType === TaxType::Gst) {
                if ($supplyType === SupplyType::InterState) {
                    // Whole rate as IGST.
                    $igstRate = Money::of($productRate);
                    $igst = $taxable->percentage($productRate)->round();
                } else {
                    // Split equally: 18% becomes 9% + 9%.
                    $half = Money::of($productRate)->dividedBy('2');
                    $cgstRate = $half;
                    $sgstRate = $half;
                    $cgst = $taxable->percentage($half->toRawString())->round();
                    $sgst = $taxable->percentage($half->toRawString())->round();
                }
            }

            $taxAmount = $cgst->plus($sgst)->plus($igst)->round();
            $lineTotal = $taxable->plus($taxAmount)->round();

            $computedLines[] = new InvoiceLineTotals(
                product: $line->product,
                productName: $line->product->name,
                sku: $line->product->sku,
                hsnCode: $line->product->hsn_code,
                unit: $line->product->unit,
                quantity: bcadd($line->quantity, '0', 3),
                // The TAXABLE rate, which is what every downstream total is
                // built from.
                unitPrice: $netUnitPrices[$index]->toString(),
                /*
                 * The price as entered. Under tax-inclusive pricing this is
                 * the customer-facing figure and differs from unitPrice; under
                 * tax-exclusive pricing the two are the same. Snapshotted so
                 * the printed "Rate (Incl. of Tax)" column is a stored fact
                 * rather than something re-derived at print time.
                 */
                unitPriceGross: Money::of($line->unitPrice)->toString(),
                // Snapshot the configured rate even on a non-GST bill: it
                // records what the rate WAS, never what was charged.
                gstRate: Money::of($productRate)->toString(),
                lineSubtotal: $lineSubtotal->toString(),
                discountAmount: $lineDiscount->toString(),
                taxableAmount: $taxable->toString(),
                cgstRate: $cgstRate->toString(),
                cgstAmount: $cgst->toString(),
                sgstRate: $sgstRate->toString(),
                sgstAmount: $sgst->toString(),
                igstRate: $igstRate->toString(),
                igstAmount: $igst->toString(),
                taxAmount: $taxAmount->toString(),
                lineTotal: $lineTotal->toString(),
                sortOrder: $line->sortOrder,
            );

            $taxableTotal = $taxableTotal->plus($taxable);
            $cgstTotal = $cgstTotal->plus($cgst);
            $sgstTotal = $sgstTotal->plus($sgst);
            $igstTotal = $igstTotal->plus($igst);
        }

        /*
         * ---- 5b. additional charges --------------------------------------
         *
         * Taxed at their OWN rate, after the goods and after the discount: an
         * insurance charge is an 18% service on a 5% scooter sale, and a
         * discount negotiated on the goods does not reduce it.
         */
        $computedCharges = [];

        foreach ($charges as $index => $charge) {
            $computed = $this->computeCharge($charge, $index, $taxType, $supplyType);

            $computedCharges[] = $computed;

            $taxableTotal = $taxableTotal->plus(Money::of($computed->taxableAmount));
            $cgstTotal = $cgstTotal->plus(Money::of($computed->cgstAmount));
            $sgstTotal = $sgstTotal->plus(Money::of($computed->sgstAmount));
            $igstTotal = $igstTotal->plus(Money::of($computed->igstAmount));
        }

        $taxableTotal = $taxableTotal->round();
        $cgstTotal = $cgstTotal->round();
        $sgstTotal = $sgstTotal->round();
        $igstTotal = $igstTotal->round();
        $totalTax = $cgstTotal->plus($sgstTotal)->plus($igstTotal)->round();

        // ---- 6. round-off ------------------------------------------------
        $beforeRounding = $taxableTotal->plus($totalTax)->round();
        $roundOff = $roundOffEnabled ? $beforeRounding->roundOffDelta() : Money::zero();
        $grandTotal = $beforeRounding->plus($roundOff)->round();

        return new InvoiceTotals(
            taxType: $taxType,
            // Meaningless on a non-GST bill, so recorded as null.
            supplyType: $taxType === TaxType::Gst ? $supplyType : null,
            lines: $computedLines,
            charges: $computedCharges,
            subtotal: $subtotal->toString(),
            discountAmount: $discount->toString(),
            taxableAmount: $taxableTotal->toString(),
            cgstAmount: $cgstTotal->toString(),
            sgstAmount: $sgstTotal->toString(),
            igstAmount: $igstTotal->toString(),
            totalTax: $totalTax->toString(),
            roundOff: $roundOff->toString(),
            grandTotal: $grandTotal->toString(),
        );
    }

    /**
     * One additional charge, taxed at its own rate.
     */
    private function computeCharge(
        InvoiceChargeInput $charge,
        int $index,
        TaxType $taxType,
        ?SupplyType $supplyType,
    ): InvoiceChargeTotals {
        $taxable = Money::of($charge->amount)->round();
        $rate = $charge->gstRate;

        $cgstRate = Money::zero();
        $sgstRate = Money::zero();
        $igstRate = Money::zero();
        $cgst = Money::zero();
        $sgst = Money::zero();
        $igst = Money::zero();

        if ($taxType === TaxType::Gst) {
            if ($supplyType === SupplyType::InterState) {
                $igstRate = Money::of($rate);
                $igst = $taxable->percentage($rate)->round();
            } else {
                $half = Money::of($rate)->dividedBy('2');
                $cgstRate = $half;
                $sgstRate = $half;
                $cgst = $taxable->percentage($half->toRawString())->round();
                $sgst = $taxable->percentage($half->toRawString())->round();
            }
        }

        $taxAmount = $cgst->plus($sgst)->plus($igst)->round();

        return new InvoiceChargeTotals(
            description: $charge->description,
            hsnCode: $charge->hsnCode,
            taxableAmount: $taxable->toString(),
            gstRate: Money::of($rate)->toString(),
            cgstRate: $cgstRate->toString(),
            cgstAmount: $cgst->toString(),
            sgstRate: $sgstRate->toString(),
            sgstAmount: $sgst->toString(),
            igstRate: $igstRate->toString(),
            igstAmount: $igst->toString(),
            taxAmount: $taxAmount->toString(),
            total: $taxable->plus($taxAmount)->round()->toString(),
            sortOrder: $charge->sortOrder !== 0 ? $charge->sortOrder : $index,
        );
    }

    /**
     * The taxable unit price.
     *
     * Tax-exclusive pricing returns the entered price unchanged. Tax-inclusive
     * pricing divides the GST back out, rounded to 2 dp per unit.
     *
     * A non-GST bill charges no tax, so there is nothing to remove and the
     * entered price IS the taxable price whichever mode is configured.
     */
    private function netUnitPrice(
        string $unitPrice,
        string $gstRate,
        TaxType $taxType,
        bool $pricesIncludeTax,
    ): Money {
        $price = Money::of($unitPrice);

        if (! $pricesIncludeTax || $taxType !== TaxType::Gst) {
            return $price;
        }

        // 1 + rate/100, e.g. 5% -> 1.05. A zero-rated line divides by 1.
        $divisor = bcadd('1', bcdiv($gstRate, '100', 8), 8);

        if (bccomp($divisor, '0', 8) <= 0) {
            return $price;
        }

        return $price->dividedBy($divisor)->round();
    }

    /**
     * Splits a flat invoice discount across lines in proportion to each line's
     * subtotal.
     *
     * Any rounding remainder is assigned to the LAST line, so the apportioned
     * shares always sum to exactly the discount given -- otherwise a few paise
     * would silently appear or vanish from the taxable value.
     *
     * @param  array<int, Money>  $lineSubtotals
     * @return array<int, Money>
     */
    private function apportionDiscount(array $lineSubtotals, Money $discount, Money $subtotal): array
    {
        $shares = [];

        if ($discount->isZero() || $subtotal->isZero()) {
            foreach (array_keys($lineSubtotals) as $index) {
                $shares[$index] = Money::zero();
            }

            return $shares;
        }

        $allocated = Money::zero();
        $indexes = array_keys($lineSubtotals);
        $lastIndex = end($indexes);

        foreach ($lineSubtotals as $index => $lineSubtotal) {
            if ($index === $lastIndex) {
                // Remainder, so the total is exact.
                $shares[$index] = $discount->minus($allocated)->round();

                continue;
            }

            $share = $discount
                ->times($lineSubtotal->toRawString())
                ->dividedBy($subtotal->toRawString())
                ->round();

            $shares[$index] = $share;
            $allocated = $allocated->plus($share);
        }

        return $shares;
    }
}
