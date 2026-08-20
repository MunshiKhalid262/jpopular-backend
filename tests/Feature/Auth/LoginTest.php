<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use PHPUnit\Framework\Attributes\Test;
use Tests\ApiTestCase;

class LoginTest extends ApiTestCase
{
    private const PASSWORD = 'password-for-tests-1';

    #[Test]
    public function valid_credentials_return_a_token_and_the_user(): void
    {
        $user = $this->admin(['email' => 'admin@example.test']);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@example.test',
            'password' => self::PASSWORD,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', 'admin@example.test')
            ->assertJsonPath('data.user.roles', ['admin'])
            ->assertJsonStructure([
                'success',
                'data' => ['token', 'user' => ['id', 'name', 'email', 'roles', 'permissions']],
            ]);

        $this->assertNotEmpty($response->json('data.token'));
        $this->assertDatabaseCount('personal_access_tokens', 1);

        // The admin permission set must arrive in full.
        $this->assertContains('users.manage', $response->json('data.user.permissions'));
    }

    #[Test]
    public function login_updates_last_login_at(): void
    {
        $user = $this->admin();
        $this->assertNull($user->last_login_at);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertOk();

        $this->assertNotNull($user->fresh()->last_login_at);
    }

    #[Test]
    public function invalid_password_is_rejected(): void
    {
        $user = $this->admin();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'not-the-password',
        ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['errors' => ['email']]);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    #[Test]
    public function unknown_email_is_rejected_with_the_same_message_as_a_wrong_password(): void
    {
        $user = $this->admin();

        $wrongPassword = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'not-the-password',
        ])->assertStatus(422);

        $unknownEmail = $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody@example.test',
            'password' => 'not-the-password',
        ])->assertStatus(422);

        // Identical response bodies: the endpoint must not reveal which
        // emails exist.
        $this->assertSame(
            $wrongPassword->json('errors.email'),
            $unknownEmail->json('errors.email'),
        );
    }

    #[Test]
    public function inactive_user_cannot_log_in(): void
    {
        $user = $this->admin(['is_active' => false]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertStatus(422);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    #[Test]
    public function soft_deleted_user_cannot_log_in(): void
    {
        $user = $this->admin();
        $email = $user->email;
        $user->delete();

        $this->assertSoftDeleted('users', ['email' => $email]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => self::PASSWORD,
        ])->assertStatus(422);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    #[Test]
    public function login_requires_email_and_password(): void
    {
        $this->postJson('/api/v1/auth/login', [])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['email', 'password']]);
    }

    #[Test]
    public function login_response_never_contains_the_password_hash(): void
    {
        $user = $this->admin();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertOk();

        $body = $response->getContent();

        $this->assertIsString($body);
        $this->assertStringNotContainsString('password', mb_strtolower(
            // strip the legitimate token field before scanning
            str_replace($response->json('data.token'), '', $body)
        ));
        $this->assertStringNotContainsString('$2y$', $body);
    }

    #[Test]
    public function login_is_rate_limited(): void
    {
        $user = $this->admin();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => $user->email,
                'password' => 'wrong',
            ])->assertStatus(422);
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'wrong',
        ])
            ->assertStatus(429)
            ->assertJsonPath('code', 'TOO_MANY_ATTEMPTS');
    }
}
