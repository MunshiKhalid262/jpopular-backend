<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\ApiTestCase;

class ChangePasswordTest extends ApiTestCase
{
    private const PASSWORD = 'password-for-tests-1';

    #[Test]
    public function password_change_requires_authentication(): void
    {
        $this->putJson('/api/v1/auth/password', [])
            ->assertStatus(401);
    }

    #[Test]
    public function a_user_can_change_their_password(): void
    {
        $user = $this->admin();

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertOk()->json('data.token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/auth/password', [
                'current_password' => self::PASSWORD,
                'password' => 'brand-new-secret-42',
                'password_confirmation' => 'brand-new-secret-42',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertTrue(Hash::check('brand-new-secret-42', $user->fresh()->password));
    }

    #[Test]
    public function an_incorrect_current_password_is_rejected(): void
    {
        $user = $this->admin();
        $this->actingAsToken($user);

        $this->putJson('/api/v1/auth/password', [
            'current_password' => 'wrong-current-password',
            'password' => 'brand-new-secret-42',
            'password_confirmation' => 'brand-new-secret-42',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'CURRENT_PASSWORD_INCORRECT');

        // Unchanged.
        $this->assertTrue(Hash::check(self::PASSWORD, $user->fresh()->password));
    }

    #[Test]
    public function a_short_new_password_is_rejected(): void
    {
        $this->actingAsToken($this->admin());

        $this->putJson('/api/v1/auth/password', [
            'current_password' => self::PASSWORD,
            'password' => 'short1',
            'password_confirmation' => 'short1',
        ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['password']]);
    }

    #[Test]
    public function an_unconfirmed_new_password_is_rejected(): void
    {
        $this->actingAsToken($this->admin());

        $this->putJson('/api/v1/auth/password', [
            'current_password' => self::PASSWORD,
            'password' => 'brand-new-secret-42',
            'password_confirmation' => 'something-else-entirely-1',
        ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['password']]);
    }

    #[Test]
    public function the_new_password_must_differ_from_the_current_one(): void
    {
        $this->actingAsToken($this->admin());

        $this->putJson('/api/v1/auth/password', [
            'current_password' => self::PASSWORD,
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['password']]);
    }

    #[Test]
    public function changing_the_password_revokes_other_sessions_but_keeps_the_current_one(): void
    {
        $user = $this->admin();

        $other = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertOk()->json('data.token');

        $current = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertOk()->json('data.token');

        $this->assertDatabaseCount('personal_access_tokens', 2);

        $this->withHeader('Authorization', 'Bearer '.$current)
            ->putJson('/api/v1/auth/password', [
                'current_password' => self::PASSWORD,
                'password' => 'brand-new-secret-42',
                'password_confirmation' => 'brand-new-secret-42',
            ])
            ->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 1);

        // The other device is signed out...
        $this->forgetResolvedUser();
        $this->withHeader('Authorization', 'Bearer '.$other)
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401);

        // ...but the caller is not logged out of the tab they are using.
        $this->withHeader('Authorization', 'Bearer '.$current)
            ->getJson('/api/v1/auth/me')
            ->assertOk();
    }

    private function actingAsToken(User $user): void
    {
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->json('data.token');

        $this->withHeader('Authorization', 'Bearer '.$token);
    }
}
