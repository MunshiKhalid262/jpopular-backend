<?php

declare(strict_types=1);

namespace App\Http\Resources\Inventory;

use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the append-only stock ledger.
 *
 * `quantity` stays SIGNED exactly as stored -- positive in, negative out --
 * because that sign is what makes SUM(quantity) reconcile against
 * products.current_stock. The UI renders the sign; it is not normalised away
 * here.
 *
 * @mixin StockMovement
 */
class StockMovementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'increases_stock' => $this->type->increasesStock(),

            'quantity' => $this->quantity,
            'previous_stock' => $this->previous_stock,
            'new_stock' => $this->new_stock,

            'unit_cost' => $this->unit_cost,
            'note' => $this->note,

            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,
            'reference_label' => $this->referenceLabel(),

            'product' => $this->whenLoaded(
                'product',
                fn (): ?array => $this->product === null ? null : [
                    'id' => $this->product->id,
                    'name' => $this->product->name,
                    'sku' => $this->product->sku,
                    'unit' => $this->product->unit,
                ],
            ),
            'product_id' => $this->product_id,

            // Nullable: created_by is nullOnDelete, and a movement outlives
            // the user who recorded it.
            'created_by' => $this->whenLoaded(
                'createdBy',
                fn (): ?array => $this->createdBy === null ? null : [
                    'id' => $this->createdBy->id,
                    'name' => $this->createdBy->name,
                ],
            ),

            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
