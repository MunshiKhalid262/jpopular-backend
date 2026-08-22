<?php

declare(strict_types=1);

namespace Database\Seeders\Development;

use App\Models\User;
use Database\Seeders\DevelopmentSeeder;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Local demo logins.
 *
 * These passwords are intentionally weak and publicly documented: they are
 * fixtures for a developer's machine, in the same spirit as a factory default.
 * They deliberately do NOT satisfy the API's password policy (min 12 chars,
 * not breached), which is why they are written through the model rather than
 * the validated API path.
 *
 * They can only ever exist in a development database:
 *   - DatabaseSeeder calls DevelopmentSeeder only in a safe environment;
 *   - DevelopmentSeeder throws outside one;
 *   - this seeder re-checks as a third barrier.
 *
 * Production administrators are created with `php artisan app:create-admin`.
 */
class DemoUserSeeder extends Seeder
{
    /**
     * @var list<array{name: string, email: string, password: string, role: string}>
     */
    private const DEMO_USERS = [
        [
            'name' => 'JPopular Admin',
            'email' => 'admin@jpopular.in',
            'password' => 'Admin@1234',
            'role' => 'admin',
        ],
        [
            'name' => 'JPopular Manager',
            'email' => 'manager@jpopular.in',
            'password' => 'Manager@1234',
            'role' => 'manager',
        ],
    ];

    public function run(): void
    {
        if (! DevelopmentSeeder::isSafeEnvironment()) {
            throw new RuntimeException(
                'DemoUserSeeder refused to run outside a development environment.'
            );
        }

        foreach (self::DEMO_USERS as $demo) {
            $user = User::withTrashed()->where('email', $demo['email'])->first();
            $isNew = $user === null;

            if ($isNew) {
                $user = new User;
                $user->email = $demo['email'];
            } elseif ($user->trashed()) {
                $user->restore();
            }

            $user->name = $demo['name'];
            // Reset every run: these are fixtures, so a developer who changed
            // one locally still gets the documented password back.
            $user->password = $demo['password'];
            $user->is_active = true;
            $user->email_verified_at = now();
            $user->save();

            $user->syncRoles([$demo['role']]);

            $this->command?->info(sprintf(
                '  %s demo %s: %s',
                $isNew ? 'created' : 'refreshed',
                $demo['role'],
                $demo['email'],
            ));
        }

        $this->command?->warn('  Demo passwords are weak by design and valid only locally.');
    }

    /**
     * Exposed for tests, so they assert against the same list the seeder uses.
     *
     * @return list<array{name: string, email: string, password: string, role: string}>
     */
    public static function demoUsers(): array
    {
        return self::DEMO_USERS;
    }
}
