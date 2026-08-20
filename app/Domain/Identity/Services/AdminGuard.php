<?php

declare(strict_types=1);

namespace App\Domain\Identity\Services;

use App\Enums\RoleName;
use App\Exceptions\BusinessRuleException;
use App\Models\User;

/**
 * Enforces the invariant "at least one active Admin must always exist".
 *
 * Centralised because three separate operations can violate it: deactivating a
 * user, changing a user's roles, and deleting a user.
 */
final class AdminGuard
{
    /**
     * Number of active, non-deleted users holding the Admin role,
     * excluding the given user.
     */
    public function otherActiveAdminCount(User $except): int
    {
        return User::query()
            ->active()
            ->whereKeyNot($except->getKey())
            ->role(RoleName::Admin->value)
            ->count();
    }

    public function isLastActiveAdmin(User $user): bool
    {
        if (! $user->hasRole(RoleName::Admin->value)) {
            return false;
        }

        if (! $user->canAuthenticate()) {
            return false;
        }

        return $this->otherActiveAdminCount($user) === 0;
    }

    /**
     * @throws BusinessRuleException
     */
    public function assertCanBeDeactivated(User $user): void
    {
        if ($this->isLastActiveAdmin($user)) {
            throw BusinessRuleException::lastActiveAdminCannotBeDeactivated();
        }
    }

    /**
     * @param  list<string>  $newRoles
     *
     * @throws BusinessRuleException
     */
    public function assertRolesKeepAnAdmin(User $user, array $newRoles): void
    {
        $keepsAdmin = in_array(RoleName::Admin->value, $newRoles, true);

        if (! $keepsAdmin && $this->isLastActiveAdmin($user)) {
            throw BusinessRuleException::lastActiveAdminCannotLoseAdminRole();
        }
    }

    /**
     * @throws BusinessRuleException
     */
    public function assertCanBeDeleted(User $user): void
    {
        if ($this->isLastActiveAdmin($user)) {
            throw BusinessRuleException::lastActiveAdminCannotBeDeactivated();
        }
    }
}
