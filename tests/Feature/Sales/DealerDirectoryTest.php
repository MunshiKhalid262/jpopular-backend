<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Enums\CustomerType;
use App\Models\Customer;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\ApiTestCase;

/**
 * Dealers are customers with a type and a set of dispatch defaults.
 *
 * Kept on the customers table rather than in a parallel entity: invoices
 * already point at customers with a RESTRICT foreign key, and a dealer has the
 * same billing identity a customer has.
 */
class DealerDirectoryTest extends ApiTestCase
{
    #[Test]
    public function a_customer_defaults_to_the_customer_type(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/v1/customers', ['name' => 'Walk-in Ravi'])
            ->assertStatus(201)
            ->assertJsonPath('data.type', 'customer');
    }

    #[Test]
    public function a_dealer_can_be_created_with_dispatch_defaults(): void
    {
        Sanctum::actingAs($this->admin());

        $response = $this->postJson('/api/v1/customers', [
            'type' => 'dealer',
            'name' => 'ACME MOTORS',
            'state_code' => '19',
            'gstin' => '19AMYPI5698G2Z0',
            'default_dispatched_through' => 'BY ROAD',
            'default_destination' => 'SANKARPUR',
            'default_terms_of_delivery' => 'Ex-works',
            'default_mode_of_payment' => '30 days credit',
        ])->assertStatus(201);

        $response->assertJsonPath('data.type', 'dealer');
        $response->assertJsonPath('data.default_dispatched_through', 'BY ROAD');
        $response->assertJsonPath('data.default_destination', 'SANKARPUR');
        $response->assertJsonPath('data.default_terms_of_delivery', 'Ex-works');
    }

    #[Test]
    public function a_dealer_stores_no_separate_consignee(): void
    {
        Sanctum::actingAs($this->admin());

        /*
         * The dealer IS the consignee -- goods go to the party that bought
         * them. A second copy of the same company could only ever drift out of
         * step with the first, so the API does not accept one.
         */
        $response = $this->postJson('/api/v1/customers', [
            'type' => 'dealer',
            'name' => 'ACME MOTORS',
            'default_consignee_name' => 'SOMEWHERE ELSE',
        ])->assertStatus(201);

        $response->assertJsonMissingPath('data.default_consignee_name');
        $this->assertArrayNotHasKey('default_consignee_name', Customer::first()->getAttributes());
    }

    #[Test]
    public function the_list_can_be_filtered_to_dealers_only(): void
    {
        Sanctum::actingAs($this->admin());

        Customer::factory()->create(['name' => 'Walk-in Ravi']);
        Customer::factory()->dealer()->create(['name' => 'ACME MOTORS']);

        // The invoice form's dealer picker relies on this; without it the
        // operator would be offered every walk-in customer too.
        $dealers = $this->getJson('/api/v1/customers?type=dealer')->assertOk()->json('data');

        $this->assertCount(1, $dealers);
        $this->assertSame('ACME MOTORS', $dealers[0]['name']);

        $customers = $this->getJson('/api/v1/customers?type=customer')->assertOk()->json('data');

        $this->assertCount(1, $customers);
        $this->assertSame('Walk-in Ravi', $customers[0]['name']);

        // Unfiltered still returns both.
        $this->getJson('/api/v1/customers')->assertOk()->assertJsonCount(2, 'data');
    }

    #[Test]
    public function a_walk_in_can_be_promoted_to_a_dealer(): void
    {
        Sanctum::actingAs($this->admin());

        $customer = Customer::factory()->create(['name' => 'Ravi Kumar']);

        // The point of keeping dealers on the customers table: a customer who
        // starts buying in bulk becomes a dealer without being re-keyed, and
        // their historical invoices keep pointing at the same row.
        $this->putJson("/api/v1/customers/{$customer->id}", [
            'name' => 'Ravi Kumar',
            'type' => 'dealer',
            'default_destination' => 'MEMARI',
        ])->assertOk()->assertJsonPath('data.type', 'dealer');

        $this->assertSame(CustomerType::Dealer, $customer->fresh()->type);
        $this->assertSame($customer->id, $customer->fresh()->id);
    }

    #[Test]
    public function an_unknown_type_is_rejected(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/v1/customers', ['name' => 'X', 'type' => 'wholesaler'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');
    }

    #[Test]
    public function invoice_defaults_carry_only_the_repeating_fields(): void
    {
        $dealer = Customer::factory()->dealer()->create();

        $defaults = $dealer->invoiceDefaults();

        $this->assertSame('BY ROAD', $defaults['dispatched_through']);
        $this->assertSame('SANKARPUR', $defaults['destination']);
        $this->assertSame('Ex-works', $defaults['terms_of_delivery']);
        $this->assertSame('30 days credit', $defaults['mode_of_payment']);

        // The consignee is the dealer, so it is not a default to be copied.
        $this->assertArrayNotHasKey('consignee_name', $defaults);

        /*
         * Per-trip fields must never be defaulted: pre-filling them would put
         * last week's lorry and e-Way Bill on this week's invoice.
         */
        foreach (['eway_bill_no', 'vehicle_no', 'lr_rr_no', 'buyer_order_no', 'irn'] as $perTrip) {
            $this->assertArrayNotHasKey($perTrip, $defaults);
        }
    }

    #[Test]
    public function the_destination_falls_back_to_the_dealer_city(): void
    {
        $dealer = Customer::factory()->dealer()->create([
            'default_destination' => null,
            'city' => 'KOLKATA',
        ]);

        /*
         * Where else would the goods be going? Leaving it blank means the
         * operator retypes the same town on every invoice, which is the chore
         * dealers exist to remove.
         */
        $this->assertSame('KOLKATA', $dealer->invoiceDefaults()['destination']);
    }

    #[Test]
    public function dealers_require_the_same_permissions_as_customers(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/customers?type=dealer')->assertStatus(403);
        $this->postJson('/api/v1/customers', ['name' => 'X', 'type' => 'dealer'])->assertStatus(403);
    }
}
