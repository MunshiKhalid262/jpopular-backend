<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Exceptions\BusinessRuleException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

final class ChangePassword
{
    /**
     * Changing a password revokes every OTHER token, so a stolen token cannot
     * outlive the password it was obtained with -- while the caller's current
     * session survives, so the user is not logged out of the tab they are using.
     *
     * @throws BusinessRuleException
     */
    public function handle(User $user, string $currentPassword, string $newPassword): User
    {
        if (! Hash::check($currentPassword, $user->password)) {
            throw BusinessRuleException::currentPasswordIncorrect();
        }

        return DB::transaction(function () use ($user, $newPassword): User {
            $user->password = $newPassword; // hashed by the model cast
            $user->save();

            $currentToken = $user->currentAccessToken();

            if ($currentToken instanceof PersonalAccessToken) {
                $user->tokens()->whereKeyNot($currentToken->getKey())->delete();
            } else {
                $user->tokens()->delete();
            }

            return $user;
        });
    }
}
