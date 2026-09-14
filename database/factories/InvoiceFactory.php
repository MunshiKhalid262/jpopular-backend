<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\InvoiceStatus;
use App\Enums\TaxType;
use App\Models\Customer;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Draft invoices only.
 *
 * There is deliberately no `finalized()` state here: finalizing allocates a
 * sequential number, snapshots the lines and deducts stock through the ledger.
 * A factory that set `status = finalized` directly would fabricate an invoice
 * that never went through any of that, and tests built on it would pass while
 * the real path stayed broken. Tests finalize through FinalizeInvoice instead.
 *
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'tax_type' => TaxType::Gst,
            'status' => InvoiceStatus::Draft,
            'invoice_date' => now()->toDateString(),
            'notes' => null,
            'terms' => null,
        ];
    }

    public function nonGst(): static
    {
        return $this->state(fn (): array => ['tax_type' => TaxType::NonGst]);
    }

    /** A counter sale with no customer record. */
    public function walkIn(): static
    {
        return $this->state(fn (): array => [
            'customer_id' => null,
            'tax_type' => TaxType::NonGst,
        ]);
    }
}
