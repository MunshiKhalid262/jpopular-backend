<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domain\Sales\Actions\FinalizeInvoice;
use App\Domain\Sales\Actions\ManageInvoice;
use App\Domain\Sales\Data\InvoiceLineInput;
use App\Enums\TaxType;
use App\Models\BusinessSettings;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\User;
use App\Support\BusinessPeriod;

/**
 * Builds real invoices for tests.
 *
 * Invoices are always created and finalized through the Actions rather than
 * fabricated with a factory: finalizing allocates a number, snapshots the
 * lines and deducts stock, and a test built on a hand-made "finalized" row
 * would pass while the real path stayed broken.
 */
trait MakesInvoices
{
    /** The seller needs a state code before any GST invoice can be raised. */
    protected function configureBusiness(string $stateCode = '32'): void
    {
        BusinessSettings::current()->forceFill([
            'business_name' => 'JPopular Motors',
            'gstin' => '32ABCDE1234F1Z5',
            'state' => 'Kerala',
            'state_code' => $stateCode,
            'invoice_prefix' => 'JP',
            'financial_year_start_month' => 4,
            'enable_round_off' => false,
        ])->save();

        BusinessSettings::forgetCache();
    }

    protected function reportProduct(array $attributes = []): Product
    {
        return Product::factory()->create($attributes + [
            'current_stock' => '1000.000',
            'selling_price' => '1000.00',
            'gst_rate' => '18.00',
            'hsn_code' => '87116020',
        ]);
    }

    /**
     * A finalized invoice.
     *
     * @param  array{
     *     quantity?: string,
     *     tax_type?: TaxType,
     *     customer?: Customer|null,
     *     product?: Product,
     *     actor?: User,
     *     date?: string,
     * }  $options
     */
    protected function finalizedInvoice(array $options = []): Invoice
    {
        $actor = $options['actor'] ?? User::factory()->create();
        $product = $options['product'] ?? $this->reportProduct();
        $customer = array_key_exists('customer', $options)
            ? $options['customer']
            : Customer::factory()->create();

        $lines = [new InvoiceLineInput(
            product: $product,
            quantity: $options['quantity'] ?? '1',
            unitPrice: (string) $product->selling_price,
        )];

        $draft = app(ManageInvoice::class)->create(
            attributes: [
                'customer_id' => $customer?->getKey(),
                'tax_type' => $options['tax_type'] ?? TaxType::Gst,
                'invoice_date' => $options['date'] ?? self::businessToday(),
            ],
            lines: $lines,
            actor: $actor,
        );

        return app(FinalizeInvoice::class)->handle($draft, $lines, $actor);
    }

    /**
     * Today, as the BUSINESS sees it.
     *
     * Not now()->toDateString(), which is UTC. The two disagree for five and a
     * half hours of every day: at 00:15 IST it is still the previous date in
     * UTC, so a fixture dated that way lands outside the report's idea of
     * "today" and the test fails at night for no reason a reader could guess.
     * Production is unaffected -- an operator picks the date in business time.
     */
    protected static function businessToday(): string
    {
        return BusinessPeriod::now()->toDateString();
    }

    /** A draft, for asserting that drafts never count as sales. */
    protected function draftInvoice(array $options = []): Invoice
    {
        $actor = $options['actor'] ?? User::factory()->create();
        $product = $options['product'] ?? $this->reportProduct();

        $lines = [new InvoiceLineInput(
            product: $product,
            quantity: $options['quantity'] ?? '1',
            unitPrice: (string) $product->selling_price,
        )];

        return app(ManageInvoice::class)->create(
            attributes: [
                'customer_id' => (Customer::factory()->create())->getKey(),
                'tax_type' => $options['tax_type'] ?? TaxType::Gst,
                'invoice_date' => $options['date'] ?? self::businessToday(),
            ],
            lines: $lines,
            actor: $actor,
        );
    }
}
