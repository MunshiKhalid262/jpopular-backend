<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Category;
use App\Models\User;

class CategoryPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(PermissionName::CategoriesView->value);
    }

    public function view(User $actor, Category $category): bool
    {
        return $actor->can(PermissionName::CategoriesView->value);
    }

    public function create(User $actor): bool
    {
        return $actor->can(PermissionName::CategoriesManage->value);
    }

    public function update(User $actor, Category $category): bool
    {
        return $actor->can(PermissionName::CategoriesManage->value);
    }

    public function delete(User $actor, Category $category): bool
    {
        return $actor->can(PermissionName::CategoriesManage->value);
    }
}
