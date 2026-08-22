<?php

declare(strict_types=1);

use App\Enums\PermissionName;
use App\Http\Controllers\Api\V1\Catalog\BrandController;
use App\Http\Controllers\Api\V1\Catalog\CategoryController;
use App\Http\Controllers\Api\V1\Catalog\ProductController;
use Illuminate\Support\Facades\Route;

/*
| Catalog: categories, brands, products.
|
| Defence in depth, as established in the auth slice: a coarse `can:` gate on
| the route AND a Policy check inside the controller or Form Request.
*/

Route::middleware(['auth:sanctum', 'active'])->group(function (): void {
    Route::prefix('categories')->as('categories.')->group(function (): void {
        Route::get('/', [CategoryController::class, 'index'])
            ->middleware('can:'.PermissionName::CategoriesView->value)->name('index');
        Route::post('/', [CategoryController::class, 'store'])
            ->middleware('can:'.PermissionName::CategoriesManage->value)->name('store');
        Route::get('{category}', [CategoryController::class, 'show'])
            ->middleware('can:'.PermissionName::CategoriesView->value)->name('show');
        Route::put('{category}', [CategoryController::class, 'update'])
            ->middleware('can:'.PermissionName::CategoriesManage->value)->name('update');
        Route::delete('{category}', [CategoryController::class, 'destroy'])
            ->middleware('can:'.PermissionName::CategoriesManage->value)->name('destroy');
    });

    Route::prefix('brands')->as('brands.')->group(function (): void {
        Route::get('/', [BrandController::class, 'index'])
            ->middleware('can:'.PermissionName::BrandsView->value)->name('index');
        Route::post('/', [BrandController::class, 'store'])
            ->middleware('can:'.PermissionName::BrandsManage->value)->name('store');
        Route::get('{brand}', [BrandController::class, 'show'])
            ->middleware('can:'.PermissionName::BrandsView->value)->name('show');
        Route::put('{brand}', [BrandController::class, 'update'])
            ->middleware('can:'.PermissionName::BrandsManage->value)->name('update');
        Route::delete('{brand}', [BrandController::class, 'destroy'])
            ->middleware('can:'.PermissionName::BrandsManage->value)->name('destroy');
    });

    Route::prefix('products')->as('products.')->group(function (): void {
        Route::get('/', [ProductController::class, 'index'])
            ->middleware('can:'.PermissionName::ProductsView->value)->name('index');
        Route::post('/', [ProductController::class, 'store'])
            ->middleware('can:'.PermissionName::ProductsCreate->value)->name('store');
        Route::get('{product}', [ProductController::class, 'show'])
            ->middleware('can:'.PermissionName::ProductsView->value)->name('show');
        Route::put('{product}', [ProductController::class, 'update'])
            ->middleware('can:'.PermissionName::ProductsUpdate->value)->name('update');
        Route::put('{product}/status', [ProductController::class, 'updateStatus'])
            ->middleware('can:'.PermissionName::ProductsUpdate->value)->name('status.update');
        Route::delete('{product}', [ProductController::class, 'destroy'])
            ->middleware('can:'.PermissionName::ProductsDelete->value)->name('destroy');
    });
});
