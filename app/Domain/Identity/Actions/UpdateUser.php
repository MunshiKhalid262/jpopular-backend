<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class UpdateUser
{
    /**
     * @param  array{name?: string, email?: string, phone?: string|null, password?: string}  $attributes
     */
    public function handle(User $user, array $attributes): User
    {
        return DB::transaction(function () use ($user, $attributes): User {
            if (array_key_exists('name', $attributes)) {
                $user->name = $attributes['name'];
            }

            if (array_key_exists('email', $attributes)) {
                $user->email = $attributes['email'];
            }

            if (array_key_exists('phone', $attributes)) {
                $user->phone = $attributes['phone'];
            }

            // An admin-initiated password reset invalidates that user's sessions.
            if (array_key_exists('password', $attributes) && $attributes['password'] !== null) {
                $user->password = $attributes['password'];
                $user->tokens()->delete();
            }

            $user->save();

            return $user->load('roles');
        });
    }
}
