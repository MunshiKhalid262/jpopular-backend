<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Production-safe by construction.
 *
 * Only system reference data (roles and permissions) is seeded unconditionally.
 * Demo users and demo catalog data are reachable only through
 * DevelopmentSeeder, which is called here solely in a safe environment AND
 * refuses to run outside one.
 *
 * Production setup is therefore two explicit steps:
 *   1. php artisan db:seed --force        (roles and permissions only)
 *   2. php artisan app:create-admin       (interactive, hidden password)
 *
 * No user account is ever created automatically in production.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // System reference data: required in every environment.
        $this->call(RolePermissionSeeder::class);

        if (DevelopmentSeeder::isSafeEnvironment()) {
            $this->call(DevelopmentSeeder::class);

            return;
        }

        $this->command?->info('Skipped demo data: not a development environment.');
        $this->command?->line('Create the first administrator with: php artisan app:create-admin');
    }
}
