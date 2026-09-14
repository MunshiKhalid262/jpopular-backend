<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Customer;
use App\Models\User;

class CustomerPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(PermissionName::CustomersView->value);
    }

    public function view(User $actor, Customer $customer): bool
    {
        return $actor->can(PermissionName::CustomersView->value);
    }

    public function create(User $actor): bool
    {
        return $actor->can(PermissionName::CustomersManage->value);
    }

    public function update(User $actor, Customer $customer): bool
    {
        return $actor->can(PermissionName::CustomersManage->value);
    }

    public function delete(User $actor, Customer $customer): bool
    {
        return $actor->can(PermissionName::CustomersDelete->value);
    }
}
