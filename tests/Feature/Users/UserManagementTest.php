<?php

declare(strict_types=1);

namespace Tests\Feature\Users;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\ApiTestCase;

class UserManagementTest extends ApiTestCase
{
    #[Test]
    public function an_admin_can_create_a_manager(): void
    {
        Sanctum::actingAs($this->admin());

        $response = $this->postJson('/api/v1/users', [
            'name' => 'Priya Sharma',
            'email' => 'priya@jpopular.test',
            'phone' => '9876543210',
            'password' => 'a-strong-password-1',
            'password_confirmation' => 'a-strong-password-1',
            'roles' => ['manager'],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.email', 'priya@jpopular.test')
            ->assertJsonPath('data.roles', ['manager'])
            ->assertJsonPath('data.is_active', true);

        $created = User::where('email', 'priya@jpopular.test')->firstOrFail();

        $this->assertTrue($created->hasRole('manager'));
        $this->assertFalse($created->hasRole('admin'));
        $this->assertTrue(Hash::check('a-strong-password-1', $created->password));

        // The new manager gets exactly the seeded Manager defaults.
        $this->assertTrue($created->can('invoices.create'));
        $this->assertFalse($created->can('users.manage'));
        $this->assertFalse($created->can('products.create')); // grantable, not default
    }

    #[Test]
    public function a_created_user_response_never_contains_a_password(): void
    {
        Sanctum::actingAs($this->admin());

        $response = $this->postJson('/api/v1/users', [
            'name' => 'Priya Sharma',
            'email' => 'priya@jpopular.test',
            'password' => 'a-strong-password-1',
            'password_confirmation' => 'a-strong-password-1',
            'roles' => ['manager'],
        ])->assertStatus(201);

        $this->assertArrayNotHasKey('password', $response->json('data'));
        $body = $response->getContent();
        $this->assertIsString($body);
        $this->assertStringNotContainsString('$2y$', $body);
    }

    #[Test]
    public function creating_a_user_validates_input(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/v1/users', [])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['errors' => ['name', 'email', 'password', 'roles']]);
    }

    #[Test]
    public function a_duplicate_email_is_rejected_including_against_soft_deleted_users(): void
    {
        Sanctum::actingAs($this->admin());

        $existing = User::factory()->create(['email' => 'taken@jpopular.test']);

        $payload = [
            'name' => 'Someone Else',
            'email' => 'taken@jpopular.test',
            'password' => 'a-strong-password-1',
            'password_confirmation' => 'a-strong-password-1',
            'roles' => ['manager'],
        ];

        $this->postJson('/api/v1/users', $payload)
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['email']]);

        // Still rejected after a soft delete, because the unique index on the
        // column is still occupied by the trashed row.
        $existing->delete();

        $this->postJson('/api/v1/users', $payload)
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['email']]);
    }

    #[Test]
    public function an_unknown_role_is_rejected(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/v1/users', [
            'name' => 'Priya Sharma',
            'email' => 'priya@jpopular.test',
            'password' => 'a-strong-password-1',
            'password_confirmation' => 'a-strong-password-1',
            'roles' => ['superuser'],
        ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['roles.0']]);
    }

    #[Test]
    public function an_admin_can_update_a_manager(): void
    {
        Sanctum::actingAs($this->admin());
        $manager = $this->manager(['name' => 'Old Name']);

        $this->putJson("/api/v1/users/{$manager->id}", [
            'name' => 'New Name',
            'phone' => '9000000000',
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.phone', '9000000000');

        $this->assertSame('New Name', $manager->fresh()->name);
    }

    #[Test]
    public function an_admin_can_deactivate_and_reactivate_a_manager(): void
    {
        Sanctum::actingAs($this->admin());
        $manager = $this->manager();

        $this->putJson("/api/v1/users/{$manager->id}/status", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertFalse($manager->fresh()->is_active);

        $this->putJson("/api/v1/users/{$manager->id}/status", ['is_active' => true])
            ->assertOk()
            ->assertJsonPath('data.is_active', true);
    }

    #[Test]
    public function deactivating_a_user_destroys_their_tokens(): void
    {
        Sanctum::actingAs($this->admin());
        $manager = $this->manager();
        $manager->createToken('device-a');
        $manager->createToken('device-b');

        $this->assertDatabaseCount('personal_access_tokens', 2);

        $this->putJson("/api/v1/users/{$manager->id}/status", ['is_active' => false])
            ->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    #[Test]
    public function an_admin_can_assign_roles(): void
    {
        Sanctum::actingAs($this->admin());
        $manager = $this->manager();

        $this->putJson("/api/v1/users/{$manager->id}/roles", ['roles' => ['admin']])
            ->assertOk()
            ->assertJsonPath('data.roles', ['admin']);

        $this->assertTrue($manager->fresh()->hasRole('admin'));
        $this->assertFalse($manager->fresh()->hasRole('manager'));
    }

    #[Test]
    public function deleting_a_user_soft_deletes_them(): void
    {
        Sanctum::actingAs($this->admin());
        $manager = $this->manager();

        $this->deleteJson("/api/v1/users/{$manager->id}")->assertOk();

        $this->assertSoftDeleted('users', ['id' => $manager->id]);
        $this->assertDatabaseHas('users', ['id' => $manager->id]); // row retained
    }

    #[Test]
    public function the_user_list_can_be_filtered(): void
    {
        Sanctum::actingAs($this->admin());
        $this->manager(['name' => 'Findable Person', 'email' => 'findable@jpopular.test']);
        User::factory()->count(2)->create(['is_active' => false]);

        $this->getJson('/api/v1/users?search=Findable')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'findable@jpopular.test');

        $this->getJson('/api/v1/users?is_active=0')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/v1/users?role=manager')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }
}
