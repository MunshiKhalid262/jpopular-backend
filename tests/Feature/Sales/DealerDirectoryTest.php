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
            'default_consignee_name' => 'ACME GODOWN',
            'default_consignee_address' => 'SANKARPUR, West Bengal',
            'default_consignee_state_code' => '19',
            'default_dispatched_through' => 'BY ROAD',
            'default_destination' => 'SANKARPUR',
            'default_terms_of_delivery' => 'Ex-works',
        ])->assertStatus(201);

        $response->assertJsonPath('data.type', 'dealer');
        $response->assertJsonPath('data.default_dispatched_through', 'BY ROAD');
        $response->assertJsonPath('data.default_destination', 'SANKARPUR');
        $response->assertJsonPath('data.default_consignee_name', 'ACME GODOWN');
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
    public function the_consignee_state_code_is_validated(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/v1/customers', [
            'name' => 'ACME',
            'type' => 'dealer',
            'default_consignee_state_code' => '1',
        ])->assertStatus(422)->assertJsonValidationErrors('default_consignee_state_code');
    }

    #[Test]
    public function invoice_defaults_carry_only_the_repeating_fields(): void
    {
        $dealer = Customer::factory()->dealer()->create([
            'default_consignee_name' => 'ACME GODOWN',
        ]);

        $defaults = $dealer->invoiceDefaults();

        $this->assertSame('ACME GODOWN', $defaults['consignee_name']);
        $this->assertSame('BY ROAD', $defaults['dispatched_through']);

        /*
         * Per-trip fields must never be defaulted: pre-filling them would put
         * last week's lorry and e-Way Bill on this week's invoice.
         */
        foreach (['eway_bill_no', 'vehicle_no', 'lr_rr_no', 'buyer_order_no', 'irn'] as $perTrip) {
            $this->assertArrayNotHasKey($perTrip, $defaults);
        }
    }

    #[Test]
    public function dealers_require_the_same_permissions_as_customers(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/customers?type=dealer')->assertStatus(403);
        $this->postJson('/api/v1/customers', ['name' => 'X', 'type' => 'dealer'])->assertStatus(403);
    }
}
