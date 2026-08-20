<?php

declare(strict_types=1);

namespace Tests\Feature\Seeding;

use App\Models\User;
use Database\Seeders\StaffUserSeeder;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\ApiTestCase;

/**
 * StaffUserSeeder is the production provisioning path, so its branches are
 * tested rather than assumed.
 */
class StaffUserSeederTest extends ApiTestCase
{
    /**
     * @param  list<array<string, mixed>>  $staff
     */
    private function withStaffConfig(array $staff): void
    {
        config()->set('seeding.staff', $staff);
    }

    #[Test]
    public function it_creates_the_configured_admin_and_manager(): void
    {
        $this->withStaffConfig([
            [
                'key' => 'admin',
                'role' => 'admin',
                'name' => 'Config Admin',
                'email' => 'cfg-admin@jpopular.test',
                'password' => 'a-strong-seed-password-1',
            ],
            [
                'key' => 'manager',
                'role' => 'manager',
                'name' => 'Config Manager',
                'email' => 'cfg-manager@jpopular.test',
                'password' => 'a-strong-seed-password-2',
            ],
        ]);

        $this->seed(StaffUserSeeder::class);

        $admin = User::where('email', 'cfg-admin@jpopular.test')->firstOrFail();
        $manager = User::where('email', 'cfg-manager@jpopular.test')->firstOrFail();

        $this->assertTrue($admin->hasRole('admin'));
        $this->assertTrue($manager->hasRole('manager'));
        $this->assertTrue($admin->is_active);
        $this->assertTrue($manager->is_active);

        // Passwords are hashed, never stored in plain text.
        $this->assertNotSame('a-strong-seed-password-1', $admin->password);
        $this->assertTrue(Hash::check('a-strong-seed-password-1', $admin->password));

        // The manager gets exactly the seeded defaults, not admin rights.
        $this->assertTrue($manager->can('invoices.create'));
        $this->assertFalse($manager->can('users.manage'));
    }

    #[Test]
    public function a_blank_password_generates_a_strong_one_instead_of_a_default(): void
    {
        $this->withStaffConfig([[
            'key' => 'admin',
            'role' => 'admin',
            'name' => 'Generated Admin',
            'email' => 'generated@jpopular.test',
            'password' => null,
        ]]);

        $this->seed(StaffUserSeeder::class);

        $user = User::where('email', 'generated@jpopular.test')->firstOrFail();

        $this->assertTrue($user->hasRole('admin'));

        // Crucially, it must NOT fall back to a guessable constant.
        foreach (['password', 'secret', 'admin', 'Admin@1234', 'changeme'] as $guess) {
            $this->assertFalse(
                Hash::check($guess, $user->password),
                "Seeder fell back to the predictable password [{$guess}]."
            );
        }
    }

    #[Test]
    public function a_password_failing_the_policy_is_refused_and_no_account_is_created(): void
    {
        $this->withStaffConfig([[
            'key' => 'admin',
            'role' => 'admin',
            'name' => 'Weak Admin',
            'email' => 'weak@jpopular.test',
            'password' => 'short1', // below the 12-character minimum
        ]]);

        $this->seed(StaffUserSeeder::class);

        // Failing loudly beats creating an account that cannot later change its
        // own password through the API.
        $this->assertDatabaseMissing('users', ['email' => 'weak@jpopular.test']);
    }

    #[Test]
    public function re_running_never_overwrites_an_existing_password(): void
    {
        $this->withStaffConfig([[
            'key' => 'admin',
            'role' => 'admin',
            'name' => 'Idempotent Admin',
            'email' => 'idempotent@jpopular.test',
            'password' => 'the-original-password-1',
        ]]);

        $this->seed(StaffUserSeeder::class);

        // Operator later changes the password out of band.
        $user = User::where('email', 'idempotent@jpopular.test')->firstOrFail();
        $user->password = 'operator-changed-it-9';
        $user->save();

        // A redeploy runs the seeder again with the ORIGINAL env value.
        $this->seed(StaffUserSeeder::class);

        $user->refresh();

        $this->assertTrue(
            Hash::check('operator-changed-it-9', $user->password),
            'Re-seeding reset a password the operator had changed.'
        );
        $this->assertFalse(Hash::check('the-original-password-1', $user->password));
        $this->assertSame(1, User::where('email', 'idempotent@jpopular.test')->count());
    }

    #[Test]
    public function re_running_restores_and_reactivates_a_disabled_account(): void
    {
        $this->withStaffConfig([[
            'key' => 'admin',
            'role' => 'admin',
            'name' => 'Restorable Admin',
            'email' => 'restore@jpopular.test',
            'password' => 'the-original-password-1',
        ]]);

        $this->seed(StaffUserSeeder::class);

        $user = User::where('email', 'restore@jpopular.test')->firstOrFail();
        $user->forceFill(['is_active' => false])->save();
        $user->delete();

        $this->assertSoftDeleted('users', ['email' => 'restore@jpopular.test']);

        $this->seed(StaffUserSeeder::class);

        $restored = User::where('email', 'restore@jpopular.test')->firstOrFail();

        $this->assertTrue($restored->is_active);
        $this->assertTrue($restored->hasRole('admin'));
    }
}
