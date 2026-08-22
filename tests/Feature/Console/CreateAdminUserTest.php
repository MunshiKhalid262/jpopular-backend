<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `app:create-admin` is the only sanctioned way to create a production
 * administrator, so its guarantees are tested.
 */
class CreateAdminUserTest extends TestCase
{
    use RefreshDatabase;

    private const STRONG_PASSWORD = 'a-strong-admin-password-1';

    #[Test]
    public function it_creates_an_active_administrator(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $this->artisan('app:create-admin')
            ->expectsQuestion('Full name', 'Real Administrator')
            ->expectsQuestion('Email address', 'owner@jpopular.in')
            ->expectsQuestion('Password (input hidden)', self::STRONG_PASSWORD)
            ->expectsQuestion('Confirm password', self::STRONG_PASSWORD)
            ->assertExitCode(0);

        $user = User::where('email', 'owner@jpopular.in')->firstOrFail();

        $this->assertTrue($user->hasRole('admin'));
        $this->assertTrue($user->is_active);
        $this->assertTrue(Hash::check(self::STRONG_PASSWORD, $user->password));
        $this->assertSame(37, $user->getAllPermissions()->count());
    }

    #[Test]
    public function it_never_echoes_the_password(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $this->artisan('app:create-admin')
            ->expectsQuestion('Full name', 'Real Administrator')
            ->expectsQuestion('Email address', 'owner@jpopular.in')
            ->expectsQuestion('Password (input hidden)', self::STRONG_PASSWORD)
            ->expectsQuestion('Confirm password', self::STRONG_PASSWORD)
            ->doesntExpectOutputToContain(self::STRONG_PASSWORD)
            ->assertExitCode(0);
    }

    #[Test]
    public function it_rejects_a_password_that_fails_the_policy(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $this->artisan('app:create-admin')
            ->expectsQuestion('Full name', 'Real Administrator')
            ->expectsQuestion('Email address', 'owner@jpopular.in')
            ->expectsQuestion('Password (input hidden)', 'short1')
            ->expectsQuestion('Confirm password', 'short1')
            ->assertExitCode(1);

        $this->assertDatabaseMissing('users', ['email' => 'owner@jpopular.in']);
    }

    #[Test]
    public function it_rejects_a_mismatched_confirmation(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $this->artisan('app:create-admin')
            ->expectsQuestion('Full name', 'Real Administrator')
            ->expectsQuestion('Email address', 'owner@jpopular.in')
            ->expectsQuestion('Password (input hidden)', self::STRONG_PASSWORD)
            ->expectsQuestion('Confirm password', 'something-else-entirely-2')
            ->assertExitCode(1);

        $this->assertDatabaseMissing('users', ['email' => 'owner@jpopular.in']);
    }

    #[Test]
    public function it_rejects_an_invalid_email(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $this->artisan('app:create-admin')
            ->expectsQuestion('Full name', 'Real Administrator')
            ->expectsQuestion('Email address', 'not-an-email')
            ->expectsQuestion('Password (input hidden)', self::STRONG_PASSWORD)
            ->expectsQuestion('Confirm password', self::STRONG_PASSWORD)
            ->assertExitCode(1);

        $this->assertSame(0, User::count());
    }

    #[Test]
    public function it_refuses_to_run_before_roles_are_seeded(): void
    {
        // No RolePermissionSeeder: assigning a non-existent role would blow up
        // half-way, so the command stops with a clear instruction instead.
        $this->artisan('app:create-admin')->assertExitCode(1);

        $this->assertSame(0, User::count());
    }

    #[Test]
    public function it_promotes_an_existing_user_only_after_confirmation(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $existing = User::factory()->create(['email' => 'staff@jpopular.in']);
        $existing->assignRole('manager');

        $this->artisan('app:create-admin')
            ->expectsQuestion('Full name', 'Promoted Person')
            ->expectsQuestion('Email address', 'staff@jpopular.in')
            ->expectsQuestion('Password (input hidden)', self::STRONG_PASSWORD)
            ->expectsQuestion('Confirm password', self::STRONG_PASSWORD)
            ->expectsConfirmation(
                'staff@jpopular.in already exists. Reset its password and grant the Admin role?',
                'yes'
            )
            ->assertExitCode(0);

        $existing->refresh();

        $this->assertTrue($existing->hasRole('admin'));
        $this->assertTrue(Hash::check(self::STRONG_PASSWORD, $existing->password));
    }

    #[Test]
    public function declining_the_confirmation_changes_nothing(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $existing = User::factory()->create(['email' => 'staff@jpopular.in']);
        $existing->assignRole('manager');
        $originalHash = $existing->password;

        $this->artisan('app:create-admin')
            ->expectsQuestion('Full name', 'Promoted Person')
            ->expectsQuestion('Email address', 'staff@jpopular.in')
            ->expectsQuestion('Password (input hidden)', self::STRONG_PASSWORD)
            ->expectsQuestion('Confirm password', self::STRONG_PASSWORD)
            ->expectsConfirmation(
                'staff@jpopular.in already exists. Reset its password and grant the Admin role?',
                'no'
            )
            ->assertExitCode(1);

        $existing->refresh();

        $this->assertFalse($existing->hasRole('admin'));
        $this->assertSame($originalHash, $existing->password);
    }

    #[Test]
    public function promoting_a_user_revokes_their_existing_tokens(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $existing = User::factory()->create(['email' => 'staff@jpopular.in']);
        $existing->assignRole('manager');
        $existing->createToken('old-session');

        $this->assertDatabaseCount('personal_access_tokens', 1);

        $this->artisan('app:create-admin')
            ->expectsQuestion('Full name', 'Promoted Person')
            ->expectsQuestion('Email address', 'staff@jpopular.in')
            ->expectsQuestion('Password (input hidden)', self::STRONG_PASSWORD)
            ->expectsQuestion('Confirm password', self::STRONG_PASSWORD)
            ->expectsConfirmation(
                'staff@jpopular.in already exists. Reset its password and grant the Admin role?',
                'yes'
            )
            ->assertExitCode(0);

        // A session issued under the old password must not survive.
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
