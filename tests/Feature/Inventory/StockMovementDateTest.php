<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\Services\StockLedger;
use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\ApiTestCase;

/**
 * The date a stock movement is recorded against, and correcting it later.
 *
 * The date says WHEN stock moved. It carries no arithmetic: the cached balance
 * is SUM(quantity) with no date filter, so moving a date never changes a
 * figure. That is what makes it the one field safe to edit in place on an
 * otherwise append-only ledger.
 */
class StockMovementDateTest extends ApiTestCase
{
    private function product(): Product
    {
        return Product::factory()->create(['current_stock' => '0.000', 'unit' => 'Nos']);
    }

    private function manualMovement(Product $product): StockMovement
    {
        return app(StockLedger::class)->record([
            'product' => $product,
            'type' => StockMovementType::StockIn,
            'quantity' => '10.000',
            'reference_type' => 'Test',
            'reference_id' => (int) $product->getKey(),
            'user_id' => null,
            'note' => 'delivery',
            'occurred_at' => '2026-10-01 09:00:00',
        ]);
    }

    // --------------------------------------------------- recording a date

    #[Test]
    public function a_movement_can_be_recorded_for_a_past_date(): void
    {
        Sanctum::actingAs($this->admin());
        $product = $this->product();

        $this->postJson("/api/v1/products/{$product->id}/movements", [
            'type' => 'stock_in',
            'quantity' => '5',
            'note' => 'Delivery booked in late',
            'occurred_at' => '2026-10-02',
        ])->assertStatus(201);

        $this->assertSame(
            '2026-10-02',
            StockMovement::firstOrFail()->occurred_at->toDateString(),
        );
    }

    #[Test]
    public function a_movement_can_be_recorded_for_a_future_date(): void
    {
        Sanctum::actingAs($this->admin());
        $product = $this->product();

        /*
         * A delivery can be booked before it lands. The balance does not wait
         * for the date -- it counts immediately -- but the paperwork says when
         * the goods are expected, which is what the operator needs on it.
         */
        $future = now()->addDays(7)->toDateString();

        $this->postJson("/api/v1/products/{$product->id}/movements", [
            'type' => 'stock_in',
            'quantity' => '5',
            'note' => 'Expected delivery',
            'occurred_at' => $future,
        ])->assertStatus(201);

        $this->assertSame($future, StockMovement::firstOrFail()->occurred_at->toDateString());
    }

    #[Test]
    public function the_date_defaults_to_now_when_none_is_given(): void
    {
        Sanctum::actingAs($this->admin());
        $product = $this->product();

        $this->postJson("/api/v1/products/{$product->id}/movements", [
            'type' => 'stock_in',
            'quantity' => '5',
            'note' => 'Counter delivery',
        ])->assertStatus(201);

        $this->assertSame(
            now()->toDateString(),
            StockMovement::firstOrFail()->occurred_at->toDateString(),
        );
    }

    // ---------------------------------------------------- editing a date

    #[Test]
    public function the_date_of_a_manual_movement_can_be_corrected(): void
    {
        Sanctum::actingAs($this->admin());
        $movement = $this->manualMovement($this->product());

        $this->patchJson("/api/v1/inventory/movements/{$movement->id}", [
            'occurred_at' => '2026-09-15',
        ])->assertOk()->assertJsonPath('data.id', $movement->id);

        $this->assertSame('2026-09-15', $movement->fresh()->occurred_at->toDateString());
    }

    #[Test]
    public function correcting_a_date_does_not_move_any_stock(): void
    {
        Sanctum::actingAs($this->admin());
        $product = $this->product();
        $movement = $this->manualMovement($product);

        $before = $product->fresh()->current_stock;

        $this->patchJson("/api/v1/inventory/movements/{$movement->id}", [
            'occurred_at' => '2020-01-01',
        ])->assertOk();

        // The whole reason the date is editable at all.
        $this->assertSame($before, $product->fresh()->current_stock);
        $this->assertSame('10.000', $product->fresh()->current_stock);
        $this->assertSame('10.000', $movement->fresh()->quantity);
    }

    #[Test]
    public function an_invoice_movement_keeps_the_invoice_date(): void
    {
        Sanctum::actingAs($this->admin());
        $product = $this->product();

        // Something has to be on the shelf before it can be sold off it.
        $this->manualMovement($product);

        $movement = app(StockLedger::class)->record([
            'product' => $product->refresh(),
            'type' => StockMovementType::InvoiceSale,
            'quantity' => '1.000',
            'reference_type' => 'InvoiceItem',
            'reference_id' => 1,
            'user_id' => null,
            'note' => 'sold',
            'occurred_at' => '2026-10-01',
        ]);

        /*
         * Editing this would leave the invoice and the ledger disagreeing
         * about when the goods went out, with nothing to say which is right.
         */
        $this->patchJson("/api/v1/inventory/movements/{$movement->id}", [
            'occurred_at' => '2026-09-15',
        ])->assertStatus(422)->assertJsonPath('code', 'MOVEMENT_NOT_EDITABLE');

        $this->assertSame('2026-10-01', $movement->fresh()->occurred_at->toDateString());
    }

    #[Test]
    public function a_date_is_required_and_must_be_one(): void
    {
        Sanctum::actingAs($this->admin());
        $movement = $this->manualMovement($this->product());

        $this->patchJson("/api/v1/inventory/movements/{$movement->id}", [])
            ->assertStatus(422)->assertJsonValidationErrors('occurred_at');

        $this->patchJson("/api/v1/inventory/movements/{$movement->id}", ['occurred_at' => 'last tuesday-ish'])
            ->assertStatus(422)->assertJsonValidationErrors('occurred_at');
    }

    #[Test]
    public function editing_a_date_needs_the_adjust_permission(): void
    {
        $movement = $this->manualMovement($this->product());

        $viewer = User::factory()->create();
        $viewer->assignRole('manager');
        Sanctum::actingAs($viewer);

        // A manager may read the ledger; rewriting it is another matter.
        $response = $this->patchJson("/api/v1/inventory/movements/{$movement->id}", [
            'occurred_at' => '2026-09-15',
        ]);

        $this->assertContains($response->status(), [403, 422]);
        $this->assertSame('2026-10-01', $movement->fresh()->occurred_at->toDateString());
    }
}
