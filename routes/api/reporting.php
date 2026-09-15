<?php

declare(strict_types=1);

use App\Enums\PermissionName;
use App\Http\Controllers\Api\V1\Reporting\DashboardController;
use App\Http\Controllers\Api\V1\Reporting\ReportController;
use Illuminate\Support\Facades\Route;

/*
| Dashboard and reports.
|
| Defence in depth as elsewhere: a coarse `can:` gate on the route AND the
| controller's own check. Every export carries BOTH the report's permission and
| reports.export, so an export URL cannot be used to sidestep the gate on the
| report it exports.
*/

Route::middleware(['auth:sanctum', 'active'])->group(function (): void {
    Route::get('dashboard', DashboardController::class)
        ->middleware('can:'.PermissionName::DashboardView->value)
        ->name('dashboard');

    Route::prefix('reports')->as('reports.')
        ->middleware('can:'.PermissionName::ReportsView->value)
        ->group(function (): void {
            Route::get('sales', [ReportController::class, 'sales'])
                ->middleware('can:'.PermissionName::ReportsSales->value)->name('sales');
            Route::get('sales/export', [ReportController::class, 'exportSales'])
                ->middleware('can:'.PermissionName::ReportsExport->value)->name('sales.export');

            Route::get('gst', [ReportController::class, 'gst'])
                ->middleware('can:'.PermissionName::ReportsGst->value)->name('gst');
            Route::get('gst/export', [ReportController::class, 'exportGst'])
                ->middleware('can:'.PermissionName::ReportsExport->value)->name('gst.export');

            Route::get('non-gst', [ReportController::class, 'nonGst'])
                ->middleware('can:'.PermissionName::ReportsSales->value)->name('non-gst');
            Route::get('non-gst/export', [ReportController::class, 'exportNonGst'])
                ->middleware('can:'.PermissionName::ReportsExport->value)->name('non-gst.export');

            Route::get('payments', [ReportController::class, 'payments'])
                ->middleware('can:'.PermissionName::ReportsPayments->value)->name('payments');
            Route::get('payments/export', [ReportController::class, 'exportPayments'])
                ->middleware('can:'.PermissionName::ReportsExport->value)->name('payments.export');

            Route::get('outstanding', [ReportController::class, 'outstanding'])
                ->middleware('can:'.PermissionName::ReportsPayments->value)->name('outstanding');
            Route::get('outstanding/export', [ReportController::class, 'exportOutstanding'])
                ->middleware('can:'.PermissionName::ReportsExport->value)->name('outstanding.export');

            Route::get('inventory', [ReportController::class, 'inventory'])
                ->middleware('can:'.PermissionName::ReportsInventory->value)->name('inventory');
            Route::get('inventory/export', [ReportController::class, 'exportInventory'])
                ->middleware('can:'.PermissionName::ReportsExport->value)->name('inventory.export');

            Route::get('stock-movements', [ReportController::class, 'stockMovements'])
                ->middleware('can:'.PermissionName::ReportsStock->value)->name('stock-movements');
            Route::get('stock-movements/export', [ReportController::class, 'exportStockMovements'])
                ->middleware('can:'.PermissionName::ReportsExport->value)->name('stock-movements.export');
        });
});
