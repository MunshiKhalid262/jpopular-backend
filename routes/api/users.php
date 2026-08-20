<?php

declare(strict_types=1);

use App\Enums\PermissionName;
use App\Http\Controllers\Api\V1\Users\RoleController;
use App\Http\Controllers\Api\V1\Users\UserController;
use Illuminate\Support\Facades\Route;

/*
| Defence in depth: a coarse `can:` gate on the route AND a Policy check inside
| the controller / Form Request. Either alone would be sufficient; both means a
| future refactor cannot silently open an endpoint.
*/

Route::middleware(['auth:sanctum', 'active'])->group(function (): void {
    Route::prefix('users')->as('users.')->group(function (): void {
        Route::get('/', [UserController::class, 'index'])
            ->middleware('can:'.PermissionName::UsersView->value)
            ->name('index');

        Route::post('/', [UserController::class, 'store'])
            ->middleware('can:'.PermissionName::UsersManage->value)
            ->name('store');

        Route::get('{user}', [UserController::class, 'show'])
            ->middleware('can:'.PermissionName::UsersView->value)
            ->name('show');

        Route::put('{user}', [UserController::class, 'update'])
            ->middleware('can:'.PermissionName::UsersManage->value)
            ->name('update');

        Route::put('{user}/status', [UserController::class, 'updateStatus'])
            ->middleware('can:'.PermissionName::UsersManage->value)
            ->name('status.update');

        Route::put('{user}/roles', [UserController::class, 'updateRoles'])
            ->middleware('can:'.PermissionName::UsersManage->value)
            ->name('roles.update');

        Route::delete('{user}', [UserController::class, 'destroy'])
            ->middleware('can:'.PermissionName::UsersManage->value)
            ->name('destroy');
    });

    Route::get('roles', [RoleController::class, 'index'])
        ->middleware('can:'.PermissionName::RolesView->value)
        ->name('roles.index');

    Route::get('permissions', [RoleController::class, 'permissions'])
        ->middleware('can:'.PermissionName::RolesView->value)
        ->name('permissions.index');
});
