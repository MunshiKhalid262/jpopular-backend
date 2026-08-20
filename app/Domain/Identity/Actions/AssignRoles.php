<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Services\AdminGuard;
use App\Exceptions\BusinessRuleException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class AssignRoles
{
    public function __construct(private readonly AdminGuard $adminGuard) {}

    /**
     * @param  list<string>  $roles
     *
     * @throws BusinessRuleException
     */
    public function handle(User $user, array $roles): User
    {
        $this->adminGuard->assertRolesKeepAnAdmin($user, $roles);

        return DB::transaction(function () use ($user, $roles): User {
            $user->syncRoles($roles);

            // Permissions are baked into the token consumer's expectations, so
            // force a fresh /auth/me by invalidating existing sessions.
            $user->tokens()->delete();

            return $user->load('roles');
        });
    }
}
