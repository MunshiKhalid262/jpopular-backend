<?php

declare(strict_types=1);

use App\Enums\PermissionName;
use App\Http\Controllers\Api\V1\Sales\CustomerController;
use App\Http\Controllers\Api\V1\Sales\InvoiceController;
use App\Http\Controllers\Api\V1\Sales\InvoiceDocumentController;
use App\Http\Controllers\Api\V1\Settings\BusinessSettingsController;
use Illuminate\Support\Facades\Route;

/*
| Sales: customers, invoices, and the invoice document (preview/PDF/print).
|
| Defence in depth as elsewhere: a coarse `can:` gate on the route AND a Policy
| check inside the controller or Form Request.
|
| A finalized invoice has no update or delete route. It is cancelled instead,
| so its number stays in the sequence -- a gap is itself an audit question.
*/

Route::middleware(['auth:sanctum', 'active'])->group(function (): void {
    Route::prefix('customers')->as('customers.')->group(function (): void {
        Route::get('/', [CustomerController::class, 'index'])
            ->middleware('can:'.PermissionName::CustomersView->value)->name('index');
        Route::post('/', [CustomerController::class, 'store'])
            ->middleware('can:'.PermissionName::CustomersManage->value)->name('store');
        Route::get('{customer}', [CustomerController::class, 'show'])
            ->middleware('can:'.PermissionName::CustomersView->value)->name('show');
        Route::put('{customer}', [CustomerController::class, 'update'])
            ->middleware('can:'.PermissionName::CustomersManage->value)->name('update');
        Route::delete('{customer}', [CustomerController::class, 'destroy'])
            ->middleware('can:'.PermissionName::CustomersDelete->value)->name('destroy');
    });

    Route::prefix('invoices')->as('invoices.')->group(function (): void {
        Route::get('/', [InvoiceController::class, 'index'])
            ->middleware('can:'.PermissionName::InvoicesView->value)->name('index');
        Route::get('meta', [InvoiceController::class, 'meta'])
            ->middleware('can:'.PermissionName::InvoicesView->value)->name('meta');
        Route::post('/', [InvoiceController::class, 'store'])
            ->middleware('can:'.PermissionName::InvoicesCreate->value)->name('store');

        Route::get('{invoice}', [InvoiceController::class, 'show'])
            ->middleware('can:'.PermissionName::InvoicesView->value)->name('show');
        Route::put('{invoice}', [InvoiceController::class, 'update'])
            ->middleware('can:'.PermissionName::InvoicesUpdate->value)->name('update');
        Route::delete('{invoice}', [InvoiceController::class, 'destroy'])
            ->middleware('can:'.PermissionName::InvoicesDelete->value)->name('destroy');

        Route::post('{invoice}/finalize', [InvoiceController::class, 'finalize'])
            ->middleware('can:'.PermissionName::InvoicesFinalize->value)->name('finalize');
        Route::post('{invoice}/cancel', [InvoiceController::class, 'cancel'])
            ->middleware('can:'.PermissionName::InvoicesCancel->value)->name('cancel');

        /*
         * The document, in three renderings of the SAME template and data.
         * All gated on invoices.print: allowing one while refusing another
         * would be a distinction without a difference.
         */
        Route::get('{invoice}/pdf', [InvoiceDocumentController::class, 'pdf'])
            ->middleware('can:'.PermissionName::InvoicesPrint->value)->name('pdf');
        Route::get('{invoice}/pdf/inline', [InvoiceDocumentController::class, 'inlinePdf'])
            ->middleware('can:'.PermissionName::InvoicesPrint->value)->name('pdf.inline');
        Route::get('{invoice}/preview', [InvoiceDocumentController::class, 'preview'])
            ->middleware('can:'.PermissionName::InvoicesPrint->value)->name('preview');
    });

    Route::prefix('settings')->as('settings.')->group(function (): void {
        Route::get('business', [BusinessSettingsController::class, 'show'])
            ->middleware('can:'.PermissionName::SettingsView->value)->name('business.show');
        Route::put('business', [BusinessSettingsController::class, 'update'])
            ->middleware('can:'.PermissionName::SettingsUpdate->value)->name('business.update');
    });
});
