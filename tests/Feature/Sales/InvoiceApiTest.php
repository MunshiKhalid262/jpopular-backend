<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Models\BusinessSettings;
use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\ApiTestCase;

class InvoiceApiTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        BusinessSettings::current()->forceFill([
            'business_name' => 'JPopular Motors',
            'state_code' => '32',
            'invoice_prefix' => 'JP',
            'financial_year_start_month' => 4,
            'enable_round_off' => false,
        ])->save();
        BusinessSettings::forgetCache();
    }

    private function product(array $attributes = []): Product
    {
        return Product::factory()->create($attributes + [
            'current_stock' => '100.000',
            'selling_price' => '1000.00',
            'gst_rate' => '18.00',
            'hsn_code' => '87116020',
        ]);
    }

    private function payload(array $overrides = []): array
    {
        $product = $overrides['product'] ?? $this->product();
        $customer = array_key_exists('customer', $overrides)
            ? $overrides['customer']
            : Customer::factory()->create();

        return array_merge([
            'customer_id' => $customer?->id,
            'tax_type' => 'gst',
            'invoice_date' => '2026-09-14',
            'lines' => [
                ['product_id' => $product->id, 'quantity' => '2'],
            ],
        ], array_diff_key($overrides, array_flip(['product', 'customer'])));
    }

    private function createDraft(array $overrides = []): array
    {
        $response = $this->postJson('/api/v1/invoices', $this->payload($overrides))->assertStatus(201);

        return $response->json('data');
    }

    // ------------------------------------------------------------- create

    #[Test]
    public function unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/invoices')->assertStatus(401);
        $this->postJson('/api/v1/invoices', [])->assertStatus(401);
    }

    #[Test]
    public function an_admin_can_create_a_draft_invoice(): void
    {
        Sanctum::actingAs($this->admin());

        $data = $this->createDraft();

        $this->assertSame('draft', $data['status']);
        $this->assertNull($data['invoice_number']);
        $this->assertSame('2000.00', $data['subtotal']);
        $this->assertSame('2360.00', $data['grand_total']);
        $this->assertCount(1, $data['items']);
    }

    #[Test]
    public function a_line_snapshots_the_product_at_creation(): void
    {
        Sanctum::actingAs($this->admin());

        $product = $this->product(['name' => 'Scooter X', 'sku' => 'SCT-X']);
        $data = $this->createDraft(['product' => $product]);

        $item = $data['items'][0];

        $this->assertSame('Scooter X', $item['product_name']);
        $this->assertSame('SCT-X', $item['sku']);
        $this->assertSame('1000.00', $item['unit_price']);
        $this->assertSame('87116020', $item['hsn_code']);
    }

    #[Test]
    public function creating_an_invoice_validates_its_input(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/v1/invoices', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['tax_type', 'invoice_date', 'lines']);

        $product = $this->product();

        $this->postJson('/api/v1/invoices', $this->payload([
            'product' => $product,
            'lines' => [['product_id' => $product->id, 'quantity' => '0']],
        ]))->assertStatus(422)->assertJsonValidationErrors('lines.0.quantity');

        $this->postJson('/api/v1/invoices', $this->payload([
            'lines' => [['product_id' => 999999, 'quantity' => '1']],
        ]))->assertStatus(422)->assertJsonValidationErrors('lines.0.product_id');
    }

    #[Test]
    public function a_gst_invoice_for_a_customer_without_a_state_code_is_refused(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/v1/invoices', $this->payload([
            'customer' => Customer::factory()->withoutStateCode()->create(),
        ]))
            // Refused rather than defaulted: guessing intra-state would charge
            // CGST+SGST on what may be an inter-state supply.
            ->assertStatus(409)
            ->assertJsonPath('code', 'CUSTOMER_STATE_MISSING');
    }

    // ----------------------------------------------------------- finalize

    #[Test]
    public function an_admin_can_finalize_a_draft(): void
    {
        Sanctum::actingAs($this->admin());

        $product = $this->product(['current_stock' => '10.000']);
        $draft = $this->createDraft(['product' => $product]);

        $response = $this->postJson("/api/v1/invoices/{$draft['id']}/finalize")->assertOk();

        $response->assertJsonPath('data.status', 'finalized');
        $response->assertJsonPath('data.invoice_number', 'JP/2026-27/00001');

        $this->assertSame('8.000', $product->fresh()->current_stock);
    }

    #[Test]
    public function finalizing_without_enough_stock_is_refused(): void
    {
        Sanctum::actingAs($this->admin());

        $product = $this->product(['current_stock' => '1.000']);
        $draft = $this->createDraft(['product' => $product]);

        $this->postJson("/api/v1/invoices/{$draft['id']}/finalize")
            ->assertStatus(409)
            ->assertJsonPath('code', 'INSUFFICIENT_STOCK');

        $this->assertSame('1.000', $product->fresh()->current_stock);
    }

    #[Test]
    public function a_finalized_invoice_cannot_be_updated_or_deleted(): void
    {
        Sanctum::actingAs($this->admin());

        $product = $this->product();
        $draft = $this->createDraft(['product' => $product]);
        $this->postJson("/api/v1/invoices/{$draft['id']}/finalize")->assertOk();

        // The Policy refuses before the Action is even reached.
        $this->putJson("/api/v1/invoices/{$draft['id']}", $this->payload(['product' => $product]))
            ->assertStatus(403);

        $this->deleteJson("/api/v1/invoices/{$draft['id']}")->assertStatus(403);
    }

    // ------------------------------------------------------------- cancel

    #[Test]
    public function an_admin_can_cancel_a_finalized_invoice(): void
    {
        Sanctum::actingAs($this->admin());

        $product = $this->product(['current_stock' => '10.000']);
        $draft = $this->createDraft(['product' => $product]);
        $this->postJson("/api/v1/invoices/{$draft['id']}/finalize")->assertOk();

        $this->postJson("/api/v1/invoices/{$draft['id']}/cancel", ['reason' => 'Customer returned everything'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancellation_reason', 'Customer returned everything');

        // Stock comes back.
        $this->assertSame('10.000', $product->fresh()->current_stock);
    }

    #[Test]
    public function cancelling_requires_a_reason(): void
    {
        Sanctum::actingAs($this->admin());

        $draft = $this->createDraft();
        $this->postJson("/api/v1/invoices/{$draft['id']}/finalize")->assertOk();

        $this->postJson("/api/v1/invoices/{$draft['id']}/cancel", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    // --------------------------------------------------------- draft edit

    #[Test]
    public function a_draft_can_be_edited_and_deleted(): void
    {
        Sanctum::actingAs($this->admin());

        $product = $this->product();
        $draft = $this->createDraft(['product' => $product]);

        $this->putJson("/api/v1/invoices/{$draft['id']}", $this->payload([
            'product' => $product,
            'lines' => [['product_id' => $product->id, 'quantity' => '5']],
        ]))
            ->assertOk()
            ->assertJsonPath('data.grand_total', '5900.00');

        $this->deleteJson("/api/v1/invoices/{$draft['id']}")->assertOk();
        $this->assertSoftDeleted('invoices', ['id' => $draft['id']]);
    }

    // ------------------------------------------------------ list and show

    #[Test]
    public function invoices_can_be_listed_filtered_and_paginated(): void
    {
        Sanctum::actingAs($this->admin());

        $product = $this->product();
        $first = $this->createDraft(['product' => $product]);
        $this->postJson("/api/v1/invoices/{$first['id']}/finalize")->assertOk();
        $this->createDraft(['product' => $product]);

        $this->getJson('/api/v1/invoices')->assertOk()->assertJsonCount(2, 'data');

        $this->getJson('/api/v1/invoices?status=finalized')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.invoice_number', 'JP/2026-27/00001');

        $this->getJson('/api/v1/invoices?search=JP/2026-27/00001')
            ->assertOk()->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/invoices?per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.pagination.total', 2);

        $this->getJson('/api/v1/invoices?per_page=5000')
            ->assertOk()
            ->assertJsonPath('meta.pagination.per_page', 100);
    }

    #[Test]
    public function an_invoice_can_be_shown_with_its_items(): void
    {
        Sanctum::actingAs($this->admin());

        $draft = $this->createDraft();

        $this->getJson("/api/v1/invoices/{$draft['id']}")
            ->assertOk()
            ->assertJsonPath('data.id', $draft['id'])
            ->assertJsonCount(1, 'data.items');
    }

    // --------------------------------------------------------- permissions

    #[Test]
    public function a_user_without_invoice_permissions_is_refused(): void
    {
        $draft = null;

        Sanctum::actingAs($this->admin());
        $draft = $this->createDraft();

        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/invoices')->assertStatus(403);
        $this->getJson("/api/v1/invoices/{$draft['id']}")->assertStatus(403);
        $this->postJson('/api/v1/invoices', $this->payload())->assertStatus(403);
        $this->postJson("/api/v1/invoices/{$draft['id']}/finalize")->assertStatus(403);
        $this->postJson("/api/v1/invoices/{$draft['id']}/cancel", ['reason' => 'nope'])->assertStatus(403);
    }

    #[Test]
    public function a_manager_can_raise_and_finalize_but_not_cancel(): void
    {
        // The default matrix gives a manager invoices.create/finalize but
        // withholds invoices.cancel and invoices.delete.
        Sanctum::actingAs($this->manager());

        $product = $this->product();
        $draft = $this->createDraft(['product' => $product]);

        $this->postJson("/api/v1/invoices/{$draft['id']}/finalize")->assertOk();

        $this->postJson("/api/v1/invoices/{$draft['id']}/cancel", ['reason' => 'Trying anyway'])
            ->assertStatus(403);
    }

    // ------------------------------------------------------- customers API

    #[Test]
    public function customers_can_be_managed_for_invoicing(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/v1/customers', [
            'name' => 'Ravi Kumar',
            'phone' => '9876543210',
            'state_code' => '32',
        ])->assertStatus(201)->assertJsonPath('data.can_be_billed_with_gst', true);

        $this->postJson('/api/v1/customers', ['name' => 'Bad', 'state_code' => '3'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('state_code');

        $this->getJson('/api/v1/customers?search=Ravi')
            ->assertOk()->assertJsonCount(1, 'data');
    }

    // -------------------------------------------------------- settings API

    #[Test]
    public function business_settings_can_be_read_and_updated(): void
    {
        Sanctum::actingAs($this->admin());

        $this->getJson('/api/v1/settings/business')
            ->assertOk()
            ->assertJsonPath('data.can_issue_gst_invoices', true);

        $this->putJson('/api/v1/settings/business', [
            'business_name' => 'JPopular Motors Pvt Ltd',
            'state_code' => '29',
            'invoice_prefix' => 'JPM',
        ])
            ->assertOk()
            ->assertJsonPath('data.business_name', 'JPopular Motors Pvt Ltd')
            ->assertJsonPath('data.state_code', '29');

        // Over-long prefixes would breach the GST 16-character invoice number
        // limit, so they are refused here rather than at allocation time.
        $this->putJson('/api/v1/settings/business', [
            'business_name' => 'X',
            'invoice_prefix' => 'TOOLONG',
        ])->assertStatus(422)->assertJsonValidationErrors('invoice_prefix');
    }

    #[Test]
    public function a_user_without_settings_permission_cannot_change_the_business(): void
    {
        Sanctum::actingAs($this->manager());

        // A manager may read settings (needed to raise an invoice) but not
        // change them.
        $this->getJson('/api/v1/settings/business')->assertOk();
        $this->putJson('/api/v1/settings/business', ['business_name' => 'Hijacked'])
            ->assertStatus(403);
    }
}
