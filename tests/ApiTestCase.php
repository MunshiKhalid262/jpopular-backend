<?php

declare(strict_types=1);

namespace Tests;

use App\Enums\RoleName;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

abstract class ApiTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        // spatie caches the permission map in the container; a fresh database
        // per test would otherwise be read through a stale cache.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Drop the guard's memoised user.
     *
     * The container persists across multiple HTTP calls inside a single test
     * method, so a guard that has already resolved a user will keep returning
     * it even after the underlying token row is deleted. Production is
     * unaffected -- each real request gets a fresh container -- but a test that
     * revokes a token and then re-requests must clear this, or it asserts
     * against a cached identity rather than the database.
     */
    protected function forgetResolvedUser(): void
    {
        $this->app['auth']->forgetGuards();
    }

    protected function admin(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole(RoleName::Admin->value);

        return $user->fresh();
    }

    protected function manager(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole(RoleName::Manager->value);

        return $user->fresh();
    }
}
