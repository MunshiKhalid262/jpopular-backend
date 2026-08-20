<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Users;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Read-only in this slice. A full role editor is deliberately out of scope --
 * the roles are seeded from PermissionName as the single source of truth, and
 * exposing write endpoints now would let the seeded matrix drift.
 */
class RoleController extends Controller
{
    /**
     * GET /api/v1/roles
     */
    public function index(): JsonResponse
    {
        $roles = Role::query()
            ->with('permissions:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role): array => [
                'id' => $role->getKey(),
                'name' => $role->name,
                'permissions' => $role->permissions->pluck('name')->sort()->values()->all(),
            ])
            ->all();

        return ApiResponse::success($roles);
    }

    /**
     * GET /api/v1/permissions
     */
    public function permissions(): JsonResponse
    {
        $permissions = Permission::query()
            ->orderBy('name')
            ->pluck('name')
            ->all();

        return ApiResponse::success($permissions);
    }
}
