<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Sales\Data\InvoiceChargeInput;
use App\Domain\Sales\Data\InvoiceLineInput;
use App\Domain\Sales\Services\TaxCalculator;
use App\Enums\SupplyType;
use App\Enums\TaxType;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tax-inclusive pricing, checked against a real dealer invoice.
 *
 * The reference figures come from an actual supplier invoice to JPopular
 * (WB-AR/2627/1391): two scooter lines priced inclusive of 5% GST, plus an
 * 18% insurance charge, with a round-off. If this test drifts, the printed
 * invoice stops reconciling with the one the trade actually issues.
 */
class TaxInclusivePricingTest extends TestCase
{
    use RefreshDatabase;

    private function product(string $gstRate = '5.00'): Product
    {
        return Product::factory()->create([
            'gst_rate' => $gstRate,
            'hsn_code' => '87116020',
            'current_stock' => '100.000',
        ]);
    }

    private function line(Product $product, string $quantity, string $price): InvoiceLineInput
    {
        return new InvoiceLineInput($product, $quantity, $price);
    }

    // ------------------------------------------------- the reference invoice

    #[Test]
    public function it_reproduces_the_reference_dealer_invoice(): void
    {
        $scooter = $this->product('5.00');

        $totals = app(TaxCalculator::class)->calculate(
            lines: [
                // YAKUZA RUBIE 60V: 6 at 36,000 inclusive
                $this->line($scooter, '6', '36000.00'),
                // YAKUZA NEU 60V: 2 at 37,500 inclusive
                $this->line($scooter, '2', '37500.00'),
            ],
            taxType: TaxType::Gst,
            supplyType: SupplyType::IntraState,
            roundOffEnabled: true,
            pricesIncludeTax: true,
            charges: [
                new InvoiceChargeInput(
                    description: 'Insurance Charges on Sales (18%)',
                    amount: '207.86',
                    gstRate: '18.00',
                    hsnCode: '997135',
                ),
            ],
        );

        // Per-unit division, rounded, THEN multiplied -- which is what makes
        // the printed Rate column multiply cleanly to the printed Amount.
        $this->assertSame('34285.71', $totals->lines[0]->unitPrice);
        $this->assertSame('36000.00', $totals->lines[0]->unitPriceGross);
        $this->assertSame('205714.26', $totals->lines[0]->lineSubtotal);

        $this->assertSame('35714.29', $totals->lines[1]->unitPrice);
        $this->assertSame('71428.58', $totals->lines[1]->lineSubtotal);

        // Goods taxable: 2,05,714.26 + 71,428.58
        $this->assertSame('277142.84', $totals->subtotal);

        // Goods 2.5% + 2.5%, insurance 9% + 9%.
        $this->assertSame('6928.57', bcsub($totals->cgstAmount, '18.71', 2));
        $this->assertSame('6947.28', $totals->cgstAmount);
        $this->assertSame('6947.28', $totals->sgstAmount);
        $this->assertSame('0.00', $totals->igstAmount);
        $this->assertSame('13894.56', $totals->totalTax);

        // Taxable including the insurance charge.
        $this->assertSame('277350.70', $totals->taxableAmount);

        // 2,77,350.70 + 13,894.56 = 2,91,245.26, rounded down by 0.26.
        $this->assertSame('-0.26', $totals->roundOff);
        $this->assertSame('291245.00', $totals->grandTotal);
    }

    #[Test]
    public function the_charge_is_taxed_at_its_own_rate_not_the_goods_rate(): void
    {
        $scooter = $this->product('5.00');

        $totals = app(TaxCalculator::class)->calculate(
            lines: [$this->line($scooter, '1', '1050.00')],
            taxType: TaxType::Gst,
            supplyType: SupplyType::IntraState,
            pricesIncludeTax: true,
            charges: [new InvoiceChargeInput('Freight', '1000.00', '18.00', '996511')],
        );

        $charge = $totals->charges[0];

        // 18% on the charge, not the scooter's 5%.
        $this->assertSame('9.00', $charge->cgstRate);
        $this->assertSame('90.00', $charge->cgstAmount);
        $this->assertSame('90.00', $charge->sgstAmount);
        $this->assertSame('1180.00', $charge->total);
    }

    // ----------------------------------------------------- mode behaviour

    #[Test]
    public function tax_exclusive_pricing_is_unchanged(): void
    {
        // The existing behaviour every historical invoice was raised under.
        $product = $this->product('18.00');

        $totals = app(TaxCalculator::class)->calculate(
            lines: [$this->line($product, '2', '1000.00')],
            taxType: TaxType::Gst,
            supplyType: SupplyType::IntraState,
            pricesIncludeTax: false,
        );

        $this->assertSame('1000.00', $totals->lines[0]->unitPrice);
        $this->assertSame('1000.00', $totals->lines[0]->unitPriceGross);
        $this->assertSame('2000.00', $totals->taxableAmount);
        $this->assertSame('2360.00', $totals->grandTotal);
    }

    #[Test]
    public function inclusive_and_exclusive_reach_the_same_total_from_different_inputs(): void
    {
        $product = $this->product('18.00');

        $exclusive = app(TaxCalculator::class)->calculate(
            lines: [$this->line($product, '1', '1000.00')],
            taxType: TaxType::Gst,
            supplyType: SupplyType::IntraState,
            pricesIncludeTax: false,
        );

        $inclusive = app(TaxCalculator::class)->calculate(
            lines: [$this->line($product, '1', '1180.00')],
            taxType: TaxType::Gst,
            supplyType: SupplyType::IntraState,
            pricesIncludeTax: true,
        );

        $this->assertSame($exclusive->grandTotal, $inclusive->grandTotal);
        $this->assertSame($exclusive->taxableAmount, $inclusive->taxableAmount);
    }

    #[Test]
    public function a_non_gst_bill_ignores_the_inclusive_flag(): void
    {
        // There is no tax to remove, so the entered price IS the price.
        $product = $this->product('18.00');

        $totals = app(TaxCalculator::class)->calculate(
            lines: [$this->line($product, '2', '1000.00')],
            taxType: TaxType::NonGst,
            supplyType: null,
            pricesIncludeTax: true,
        );

        $this->assertSame('1000.00', $totals->lines[0]->unitPrice);
        $this->assertSame('2000.00', $totals->grandTotal);
        $this->assertSame('0.00', $totals->totalTax);
    }

    #[Test]
    public function an_inter_state_inclusive_sale_backs_out_igst(): void
    {
        $product = $this->product('5.00');

        $totals = app(TaxCalculator::class)->calculate(
            lines: [$this->line($product, '6', '36000.00')],
            taxType: TaxType::Gst,
            supplyType: SupplyType::InterState,
            pricesIncludeTax: true,
        );

        $this->assertSame('205714.26', $totals->taxableAmount);
        $this->assertSame('10285.71', $totals->igstAmount);
        $this->assertSame('0.00', $totals->cgstAmount);
    }

    #[Test]
    public function a_zero_rated_line_is_unaffected_by_the_inclusive_mode(): void
    {
        $product = $this->product('0.00');

        $totals = app(TaxCalculator::class)->calculate(
            lines: [$this->line($product, '3', '500.00')],
            taxType: TaxType::Gst,
            supplyType: SupplyType::IntraState,
            pricesIncludeTax: true,
        );

        // Dividing by 1 + 0% must not shift the price.
        $this->assertSame('500.00', $totals->lines[0]->unitPrice);
        $this->assertSame('1500.00', $totals->grandTotal);
    }
}
