<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes  (prefix: /api)
|--------------------------------------------------------------------------
|
| Split per module so this file never becomes a 400-line wall.
| See ARCHITECTURE-V1.md section 2.2.
|
*/

/**
 * Infrastructure health probe. Intentionally retained: unauthenticated,
 * no database access, safe for load balancers and uptime monitors.
 */
Route::get('/health', function () {
    return response()->json([
        'success' => true,
        'message' => 'JPopular API is running',
    ]);
})->name('health');

Route::prefix('v1')->as('api.v1.')->group(function (): void {
    require __DIR__.'/api/auth.php';
    require __DIR__.'/api/users.php';
});
