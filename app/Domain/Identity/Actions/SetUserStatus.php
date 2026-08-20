<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Services\AdminGuard;
use App\Exceptions\BusinessRuleException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class SetUserStatus
{
    public function __construct(private readonly AdminGuard $adminGuard) {}

    /**
     * @throws BusinessRuleException
     */
    public function handle(User $user, bool $isActive, User $actor): User
    {
        if (! $isActive) {
            if ($user->is($actor)) {
                throw BusinessRuleException::cannotDeactivateSelf();
            }

            $this->adminGuard->assertCanBeDeactivated($user);
        }

        return DB::transaction(function () use ($user, $isActive): User {
            $user->is_active = $isActive;
            $user->save();

            // Deactivation must take effect immediately, not at token expiry.
            if (! $isActive) {
                $user->tokens()->delete();
            }

            return $user->load('roles');
        });
    }
}
