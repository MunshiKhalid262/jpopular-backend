<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\StockMovement;
use App\Models\User;

/**
 * Authorization for the Inventory slice.
 *
 * Reading the stock summary and reading the movement ledger are the same
 * capability -- seeing inventory -- so both map to `viewAny`/inventory.view.
 * Recording a manual movement is a separate, higher privilege.
 *
 * There is no `update` or `delete`: the ledger is append-only, and a
 * correction is a new compensating movement rather than an edit.
 */
class StockMovementPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(PermissionName::InventoryView->value);
    }

    public function view(User $actor, StockMovement $movement): bool
    {
        return $actor->can(PermissionName::InventoryView->value);
    }

    /**
     * Recording a manual movement (opening stock, stock in/out, adjustment).
     *
     * invoice_sale and invoice_cancel are not reachable through the API at
     * all, so this gate covers every operator-initiated stock change.
     */
    public function create(User $actor): bool
    {
        return $actor->can(PermissionName::InventoryAdjust->value);
    }
}
