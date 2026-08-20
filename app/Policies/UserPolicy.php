<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\User;

/**
 * Authorization for user administration.
 *
 * Every decision is permission-based, never role-name-based, so introducing a
 * third role requires no change here.
 *
 * These checks are the authority. The frontend hiding a button is UX only.
 */
class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(PermissionName::UsersView->value);
    }

    public function view(User $actor, User $target): bool
    {
        return $actor->can(PermissionName::UsersView->value);
    }

    public function create(User $actor): bool
    {
        return $actor->can(PermissionName::UsersManage->value);
    }

    public function update(User $actor, User $target): bool
    {
        return $actor->can(PermissionName::UsersManage->value);
    }

    /**
     * The "cannot deactivate yourself" and "last active Admin" rules are NOT
     * enforced here. A policy answers "may this actor attempt this?"; those are
     * state-dependent business invariants that belong in the Action, where they
     * can be evaluated transactionally and produce a 409 with a machine code
     * rather than a bare 403.
     */
    public function updateStatus(User $actor, User $target): bool
    {
        return $actor->can(PermissionName::UsersManage->value);
    }

    public function assignRoles(User $actor, User $target): bool
    {
        return $actor->can(PermissionName::UsersManage->value);
    }

    public function delete(User $actor, User $target): bool
    {
        return $actor->can(PermissionName::UsersManage->value);
    }
}
