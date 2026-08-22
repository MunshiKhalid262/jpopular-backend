<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Product;
use App\Models\User;

class ProductPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(PermissionName::ProductsView->value);
    }

    public function view(User $actor, Product $product): bool
    {
        return $actor->can(PermissionName::ProductsView->value);
    }

    public function create(User $actor): bool
    {
        return $actor->can(PermissionName::ProductsCreate->value);
    }

    public function update(User $actor, Product $product): bool
    {
        return $actor->can(PermissionName::ProductsUpdate->value);
    }

    public function delete(User $actor, Product $product): bool
    {
        return $actor->can(PermissionName::ProductsDelete->value);
    }

    /**
     * Gates the purchase_price field in ProductResource.
     *
     * Margin is commercially sensitive: the matrix grants this to Admin, and to
     * a Manager only when granted individually. Enforced here so the field is
     * never serialised for an unauthorised caller -- hiding it in the frontend
     * alone would still ship it over the wire.
     */
    public function viewPurchasePrice(User $actor): bool
    {
        return $actor->can(PermissionName::ProductsViewPurchasePrice->value);
    }
}
