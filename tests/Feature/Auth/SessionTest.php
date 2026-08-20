<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\ApiTestCase;

class SessionTest extends ApiTestCase
{
    private const PASSWORD = 'password-for-tests-1';

    #[Test]
    public function me_requires_authentication(): void
    {
        $this->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    #[Test]
    public function me_returns_identity_roles_and_permissions(): void
    {
        $manager = $this->manager();
        Sanctum::actingAs($manager);

        $response = $this->getJson('/api/v1/auth/me')->assertOk();

        $response->assertJsonPath('data.email', $manager->email)
            ->assertJsonPath('data.roles', ['manager']);

        $permissions = $response->json('data.permissions');

        $this->assertContains('invoices.create', $permissions);
        $this->assertContains('settings.view', $permissions);
        // Admin-only and grantable-only permissions must be absent.
        $this->assertNotContains('users.manage', $permissions);
        $this->assertNotContains('invoices.cancel', $permissions);
        $this->assertNotContains('products.create', $permissions);
    }

    #[Test]
    public function me_never_exposes_the_password_hash(): void
    {
        Sanctum::actingAs($this->admin());

        $body = $this->getJson('/api/v1/auth/me')->assertOk()->getContent();

        $this->assertIsString($body);
        $this->assertStringNotContainsString('$2y$', $body);
        $this->assertArrayNotHasKey('password', $this->getJson('/api/v1/auth/me')->json('data'));
    }

    #[Test]
    public function logout_revokes_only_the_current_token(): void
    {
        $user = $this->admin();

        $first = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertOk()->json('data.token');

        $second = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertOk()->json('data.token');

        $this->assertDatabaseCount('personal_access_tokens', 2);

        $this->withHeader('Authorization', 'Bearer '.$first)
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 1);

        // The revoked token no longer authenticates...
        $this->forgetResolvedUser();
        $this->withHeader('Authorization', 'Bearer '.$first)
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401);

        // ...while the other session is untouched.
        $this->withHeader('Authorization', 'Bearer '.$second)
            ->getJson('/api/v1/auth/me')
            ->assertOk();
    }

    #[Test]
    public function a_token_belonging_to_a_deactivated_user_stops_working_immediately(): void
    {
        $user = $this->admin();

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertOk()->json('data.token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/me')
            ->assertOk();

        // Deactivated out-of-band (as another admin would).
        $user->forceFill(['is_active' => false])->save();

        $this->forgetResolvedUser();
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('code', 'ACCOUNT_INACTIVE');

        // ...and the tokens were destroyed, not merely refused.
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
