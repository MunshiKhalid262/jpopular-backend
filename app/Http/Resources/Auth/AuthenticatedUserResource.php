<?php

declare(strict_types=1);

namespace App\Http\Resources\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The /auth/me payload: identity plus the effective permission set the
 * frontend uses to drive navigation and button visibility.
 *
 * Permissions here are for UX only -- every one of them is re-checked
 * server-side by a Policy or `can:` middleware.
 *
 * @mixin User
 */
class AuthenticatedUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'is_active' => $this->is_active,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'roles' => $this->getRoleNames()->values()->all(),
            // Effective permissions: role-derived plus any direct grants.
            'permissions' => $this->getAllPermissions()
                ->pluck('name')
                ->sort()
                ->values()
                ->all(),
        ];
    }
}
