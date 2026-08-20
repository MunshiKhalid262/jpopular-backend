<?php

declare(strict_types=1);

/**
 * Staff accounts provisioned by StaffUserSeeder.
 *
 * Every value comes from the environment. No credential is ever committed:
 * .env.example carries blank placeholders, and a blank password makes the
 * seeder generate a strong random one and print it once, rather than falling
 * back to a predictable default.
 *
 * Run in any environment with:  php artisan db:seed
 */
return [
    'staff' => [
        [
            'key' => 'admin',
            'role' => 'admin',
            'name' => env('SEED_ADMIN_NAME', 'JPopular Admin'),
            'email' => env('SEED_ADMIN_EMAIL', 'admin@jpopular.in'),
            'password' => env('SEED_ADMIN_PASSWORD'),
        ],
        [
            'key' => 'manager',
            'role' => 'manager',
            'name' => env('SEED_MANAGER_NAME', 'JPopular Manager'),
            'email' => env('SEED_MANAGER_EMAIL', 'manager@jpopular.in'),
            'password' => env('SEED_MANAGER_PASSWORD'),
        ],
    ],
];
