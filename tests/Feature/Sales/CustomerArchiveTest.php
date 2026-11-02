<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Models\BusinessSettings;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\ApiTestCase;

/**
 * Removing a customer or dealer archives them; it never deletes the row.
 *
 * They appear on every invoice ever raised for them and `invoices.customer_id`
 * is RESTRICT on delete, so removing the row outright would either fail or
 * orphan a tax document. Archiving hides them from new invoices and leaves the
 * history exactly as issued.
 */
class CustomerArchiveTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        BusinessSettings::current()->forceFill([
            'state_code' => '19',
            'invoice_prefix' => 'JP',
        ])->save();
        BusinessSettings::forgetCache();
    }

    private function customerWithInvoice(): Customer
    {
        $customer = Customer::factory()->create([
            'name' => 'KHALID MOTORS',
            'state' => 'West Bengal',
            'state_code' => '19',
        ]);

        $product = Product::factory()->create([
            'gst_rate' => '5.00',
            'hsn_code' => '87116020',
            'current_stock' => '100.000',
        ]);

        $this->postJson('/api/v1/invoices', [
            'customer_id' => $customer->id,
            'invoice_type' => 'customer',
            'tax_type' => 'gst',
            'invoice_date' => '2026-09-22',
            'lines' => [[
                'product_id' => $product->id,
                'quantity' => '1',
                'unit_price' => '36000.00',
            ]],
        ])->assertStatus(201);

        return $customer;
    }

    #[Test]
    public function archiving_a_customer_leaves_their_invoices_alone(): void
    {
        Sanctum::actingAs($this->admin());
        $customer = $this->customerWithInvoice();

        $this->deleteJson("/api/v1/customers/{$customer->id}")->assertOk();

        // Soft deleted, not gone.
        $this->assertSoftDeleted('customers', ['id' => $customer->id]);

        // And the invoice still points at them, so the document still prints.
        $this->assertSame(1, Invoice::where('customer_id', $customer->id)->count());
    }

    #[Test]
    public function an_archived_customer_drops_out_of_the_list(): void
    {
        Sanctum::actingAs($this->admin());

        $keep = Customer::factory()->create(['name' => 'Still Trading']);
        $drop = Customer::factory()->create(['name' => 'Gone Away']);

        $this->deleteJson("/api/v1/customers/{$drop->id}")->assertOk();

        $names = collect($this->getJson('/api/v1/customers')->assertOk()->json('data'))
            ->pluck('name');

        $this->assertTrue($names->contains($keep->name));
        $this->assertFalse($names->contains($drop->name));
    }

    #[Test]
    public function a_dealer_archives_the_same_way(): void
    {
        Sanctum::actingAs($this->admin());

        $dealer = Customer::factory()->dealer()->create(['name' => 'ACME MOTORS']);

        $this->deleteJson("/api/v1/customers/{$dealer->id}")->assertOk();

        $this->assertSoftDeleted('customers', ['id' => $dealer->id]);
        $this->getJson('/api/v1/customers?type=dealer')->assertOk()->assertJsonCount(0, 'data');
    }

    #[Test]
    public function the_list_reports_how_many_invoices_each_party_is_on(): void
    {
        Sanctum::actingAs($this->admin());
        $customer = $this->customerWithInvoice();

        /*
         * The archive dialog says "is on N invoices" before the operator
         * confirms. Without this it would have to either stay vague or ask the
         * server once per row.
         */
        $this->getJson('/api/v1/customers')
            ->assertOk()
            ->assertJsonPath('data.0.invoice_count', 1);

        $this->getJson("/api/v1/customers/{$customer->id}")
            ->assertOk()
            ->assertJsonPath('data.invoice_count', 1);
    }

    #[Test]
    public function archiving_needs_the_delete_permission(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('manager');
        Sanctum::actingAs($manager);

        $customer = Customer::factory()->create();

        // A manager may raise invoices for a customer without being able to
        // remove one from the directory.
        $this->deleteJson("/api/v1/customers/{$customer->id}")->assertStatus(403);

        $this->assertNotSoftDeleted('customers', ['id' => $customer->id]);
    }
}
