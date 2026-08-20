<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class CreateUser
{
    /**
     * @param  array{name: string, email: string, phone?: string|null, password: string, is_active?: bool}  $attributes
     * @param  list<string>  $roles
     */
    public function handle(array $attributes, array $roles): User
    {
        return DB::transaction(function () use ($attributes, $roles): User {
            $user = new User;
            $user->name = $attributes['name'];
            $user->email = $attributes['email'];
            $user->phone = $attributes['phone'] ?? null;
            $user->password = $attributes['password']; // hashed by the model cast
            $user->is_active = $attributes['is_active'] ?? true;
            $user->save();

            $user->syncRoles($roles);

            return $user->load('roles');
        });
    }
}
