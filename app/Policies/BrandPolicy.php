<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Brand;
use App\Models\User;

class BrandPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(PermissionName::BrandsView->value);
    }

    public function view(User $actor, Brand $brand): bool
    {
        return $actor->can(PermissionName::BrandsView->value);
    }

    public function create(User $actor): bool
    {
        return $actor->can(PermissionName::BrandsManage->value);
    }

    public function update(User $actor, Brand $brand): bool
    {
        return $actor->can(PermissionName::BrandsManage->value);
    }

    public function delete(User $actor, Brand $brand): bool
    {
        return $actor->can(PermissionName::BrandsManage->value);
    }
}
