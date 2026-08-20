<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds the V1 role/permission matrix from ARCHITECTURE-V1.md section 8.
 *
 * Fully idempotent: safe to re-run after adding a permission to the enum. It
 * syncs role permissions so the database always converges on the enum, which
 * stays the single source of truth.
 */
class RolePermissionSeeder extends Seeder
{
    private const GUARD = 'web';

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (PermissionName::values() as $permission) {
            Permission::findOrCreate($permission, self::GUARD);
        }

        foreach (RoleName::cases() as $roleName) {
            $role = Role::findOrCreate($roleName->value, self::GUARD);
            $role->syncPermissions($roleName->defaultPermissions());
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info(sprintf(
            'Seeded %d permissions across %d roles.',
            count(PermissionName::values()),
            count(RoleName::cases()),
        ));
    }
}
