<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->numerify('9#########'),
            'password' => Hash::make('password-for-tests-1'),
            'is_active' => true,
            'email_verified_at' => now(),
            'last_login_at' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function admin(): static
    {
        return $this->afterCreating(
            fn (User $user) => $user->assignRole(RoleName::Admin->value)
        );
    }

    public function manager(): static
    {
        return $this->afterCreating(
            fn (User $user) => $user->assignRole(RoleName::Manager->value)
        );
    }
}
