<?php

declare(strict_types=1);

namespace Tests\Feature\Users;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\ApiTestCase;

/**
 * RBAC enforcement. The frontend hiding navigation is irrelevant here -- these
 * tests call the endpoints directly, which is exactly what a Manager attempting
 * to bypass the UI would do.
 */
class UserAuthorizationTest extends ApiTestCase
{
    /**
     * @return list<array{string, string}>
     */
    public static function adminOnlyEndpoints(): array
    {
        return [
            ['get', '/api/v1/users'],
            ['post', '/api/v1/users'],
            ['get', '/api/v1/roles'],
            ['get', '/api/v1/permissions'],
        ];
    }

    #[Test]
    public function unauthenticated_requests_are_rejected_with_401(): void
    {
        foreach (self::adminOnlyEndpoints() as [$method, $uri]) {
            $this->json($method, $uri)
                ->assertStatus(401)
                ->assertJsonPath('success', false)
                ->assertJsonPath('code', 'UNAUTHENTICATED');
        }
    }

    #[Test]
    public function an_admin_can_list_users(): void
    {
        Sanctum::actingAs($this->admin());
        User::factory()->count(3)->create();

        $this->getJson('/api/v1/users')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [['id', 'name', 'email', 'is_active', 'roles']],
                'meta' => ['pagination' => ['current_page', 'per_page', 'total']],
            ]);
    }

    #[Test]
    public function a_manager_cannot_reach_any_user_administration_endpoint(): void
    {
        Sanctum::actingAs($this->manager());

        foreach (self::adminOnlyEndpoints() as [$method, $uri]) {
            $this->json($method, $uri)
                ->assertStatus(403)
                ->assertJsonPath('success', false)
                ->assertJsonPath('code', 'FORBIDDEN');
        }
    }

    #[Test]
    public function a_manager_cannot_view_update_or_delete_a_specific_user(): void
    {
        $target = $this->manager();
        Sanctum::actingAs($this->manager());

        $this->getJson("/api/v1/users/{$target->id}")->assertStatus(403);
        $this->putJson("/api/v1/users/{$target->id}", ['name' => 'Hacked'])->assertStatus(403);
        $this->putJson("/api/v1/users/{$target->id}/status", ['is_active' => false])->assertStatus(403);
        $this->putJson("/api/v1/users/{$target->id}/roles", ['roles' => ['admin']])->assertStatus(403);
        $this->deleteJson("/api/v1/users/{$target->id}")->assertStatus(403);

        // Nothing changed.
        $this->assertNotSame('Hacked', $target->fresh()->name);
        $this->assertTrue($target->fresh()->is_active);
        $this->assertFalse($target->fresh()->hasRole('admin'));
    }

    #[Test]
    public function a_manager_can_still_reach_their_own_session_endpoints(): void
    {
        Sanctum::actingAs($this->manager());

        $this->getJson('/api/v1/auth/me')->assertOk();
    }

    #[Test]
    public function a_user_with_no_roles_at_all_is_forbidden(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/users')->assertStatus(403);
    }
}
