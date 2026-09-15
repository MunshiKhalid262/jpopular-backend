<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Reporting;

use App\Domain\Reporting\Services\DashboardMetrics;
use App\Enums\PermissionName;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * GET /dashboard
     *
     * One response for the whole dashboard rather than six endpoints the
     * frontend would have to fan out to and stitch together.
     */
    public function __invoke(Request $request, DashboardMetrics $metrics): JsonResponse
    {
        abort_unless(
            $request->user()?->can(PermissionName::DashboardView->value) ?? false,
            403,
        );

        return ApiResponse::success($metrics->all());
    }
}
