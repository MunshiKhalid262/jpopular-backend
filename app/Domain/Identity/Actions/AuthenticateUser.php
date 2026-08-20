<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class AuthenticateUser
{
    /**
     * Verifies credentials and issues a Sanctum token.
     *
     * Deliberately returns an identical error for "no such user", "wrong
     * password", and "inactive account" so the endpoint cannot be used to
     * enumerate which emails exist or which accounts are disabled.
     *
     * Soft-deleted users are excluded automatically by the SoftDeletes global
     * scope, so they can never authenticate.
     *
     * @return array{user: User, token: string}
     *
     * @throws ValidationException
     */
    public function handle(string $email, string $password, ?string $deviceName = null): array
    {
        /** @var User|null $user */
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            // Burn an equivalent hashing cost so response timing does not
            // reveal whether the email exists.
            Hash::check($password, Hash::make('timing-equalizer'));

            $passwordMatches = false;
        } else {
            $passwordMatches = Hash::check($password, $user->password);
        }

        if (! $passwordMatches || ! $user->canAuthenticate()) {
            throw ValidationException::withMessages([
                'email' => [__('These credentials do not match our records.')],
            ]);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        $token = $user->createToken(
            name: $deviceName !== null && $deviceName !== '' ? $deviceName : 'jpopular-web',
        );

        return [
            'user' => $user->load('roles'),
            'token' => $token->plainTextToken,
        ];
    }
}
