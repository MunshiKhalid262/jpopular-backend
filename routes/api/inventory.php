<?php

declare(strict_types=1);

use App\Enums\PermissionName;
use App\Http\Controllers\Api\V1\Inventory\InventoryController;
use Illuminate\Support\Facades\Route;

/*
| Inventory: stock summary and the append-only movement ledger.
|
| Defence in depth, as in the catalog slice: a coarse `can:` gate on the route
| AND a Policy check inside the controller or Form Request.
|
| Reads are gated on inventory.view, manual writes on inventory.adjust. There
| is deliberately no update or delete route: the ledger is append-only, and a
| correction is a new compensating movement.
*/

Route::middleware(['auth:sanctum', 'active'])->group(function (): void {
    Route::prefix('inventory')->as('inventory.')->group(function (): void {
        Route::get('stock', [InventoryController::class, 'stock'])
            ->middleware('can:'.PermissionName::InventoryView->value)->name('stock');

        Route::get('movements', [InventoryController::class, 'movements'])
            ->middleware('can:'.PermissionName::InventoryView->value)->name('movements');
    });

    /*
     * Product-scoped, because a movement is always about one product and the
     * route model binding gives us the row the ledger will lock.
     */
    Route::post('products/{product}/movements', [InventoryController::class, 'storeMovement'])
        ->middleware('can:'.PermissionName::InventoryAdjust->value)
        ->name('products.movements.store');
});
