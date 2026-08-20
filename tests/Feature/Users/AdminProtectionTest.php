<?php

declare(strict_types=1);

namespace Tests\Feature\Users;

use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\ApiTestCase;

/**
 * The three business invariants from ARCHITECTURE-V1.md section 11 (rules 28-29):
 * a user cannot lock themselves out, and the system must never be left without
 * an active administrator.
 */
class AdminProtectionTest extends ApiTestCase
{
    #[Test]
    public function a_user_cannot_deactivate_themselves(): void
    {
        $admin = $this->admin();
        // A second admin exists, so this is blocked by the self-check alone,
        // not by the last-admin rule.
        $this->admin();

        Sanctum::actingAs($admin);

        $this->putJson("/api/v1/users/{$admin->id}/status", ['is_active' => false])
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'CANNOT_DEACTIVATE_SELF');

        $this->assertTrue($admin->fresh()->is_active);
    }

    #[Test]
    public function a_user_cannot_delete_themselves(): void
    {
        $admin = $this->admin();
        $this->admin();

        Sanctum::actingAs($admin);

        $this->deleteJson("/api/v1/users/{$admin->id}")
            ->assertStatus(409)
            ->assertJsonPath('code', 'CANNOT_DELETE_SELF');

        $this->assertNotSoftDeleted('users', ['id' => $admin->id]);
    }

    #[Test]
    public function the_sole_admin_deactivating_themselves_is_blocked(): void
    {
        $lastAdmin = $this->admin();
        $this->manager(); // managers do not count towards the admin quorum

        Sanctum::actingAs($lastAdmin);

        // The self-check fires before the quorum check, so this is the code we
        // expect -- but either way the account must survive.
        $this->putJson("/api/v1/users/{$lastAdmin->id}/status", ['is_active' => false])
            ->assertStatus(409)
            ->assertJsonPath('code', 'CANNOT_DEACTIVATE_SELF');

        $this->assertTrue($lastAdmin->fresh()->is_active);
    }

    #[Test]
    public function a_non_admin_holding_users_manage_cannot_deactivate_the_last_active_admin(): void
    {
        // The only route by which the quorum check is reachable for someone
        // else's account: an actor with users.manage granted directly, who is
        // not themselves an admin. Guards against a future third role.
        $lastAdmin = $this->admin();

        $operator = $this->manager();
        $operator->givePermissionTo('users.manage');
        $operator->givePermissionTo('users.view');

        Sanctum::actingAs($operator->fresh());

        $this->putJson("/api/v1/users/{$lastAdmin->id}/status", ['is_active' => false])
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'LAST_ACTIVE_ADMIN');

        $this->assertTrue($lastAdmin->fresh()->is_active);
    }

    #[Test]
    public function an_admin_can_deactivate_another_admin_while_one_remains_active(): void
    {
        $target = $this->admin();
        $actor = $this->admin();

        Sanctum::actingAs($actor);

        $this->putJson("/api/v1/users/{$target->id}/status", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertFalse($target->fresh()->is_active);
        $this->assertTrue($actor->fresh()->is_active);
    }

    #[Test]
    public function the_last_active_admin_cannot_lose_the_admin_role(): void
    {
        $lastAdmin = $this->admin();
        $this->manager(); // does not count

        Sanctum::actingAs($lastAdmin);

        $this->putJson("/api/v1/users/{$lastAdmin->id}/roles", ['roles' => ['manager']])
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'LAST_ACTIVE_ADMIN');

        $this->assertTrue($lastAdmin->fresh()->hasRole('admin'));
    }

    #[Test]
    public function an_admin_can_lose_the_admin_role_once_another_active_admin_exists(): void
    {
        $demotable = $this->admin();
        $other = $this->admin();

        Sanctum::actingAs($other);

        $this->putJson("/api/v1/users/{$demotable->id}/roles", ['roles' => ['manager']])
            ->assertOk()
            ->assertJsonPath('data.roles', ['manager']);

        $this->assertFalse($demotable->fresh()->hasRole('admin'));
        $this->assertTrue($demotable->fresh()->hasRole('manager'));
    }

    #[Test]
    public function an_inactive_admin_does_not_count_towards_the_admin_quorum(): void
    {
        $activeAdmin = $this->admin();
        $inactiveAdmin = $this->admin(['is_active' => false]);

        $this->assertFalse($inactiveAdmin->fresh()->is_active);

        Sanctum::actingAs($activeAdmin);

        // Demoting the only ACTIVE admin must fail even though a second admin
        // row exists, because that one cannot log in.
        $this->putJson("/api/v1/users/{$activeAdmin->id}/roles", ['roles' => ['manager']])
            ->assertStatus(409)
            ->assertJsonPath('code', 'LAST_ACTIVE_ADMIN');

        $this->assertTrue($activeAdmin->fresh()->hasRole('admin'));
    }

    #[Test]
    public function the_last_active_admin_cannot_be_soft_deleted(): void
    {
        $lastAdmin = $this->admin();
        $otherAdmin = $this->admin();

        Sanctum::actingAs($otherAdmin);

        // Deleting one of two admins is fine.
        $this->deleteJson("/api/v1/users/{$lastAdmin->id}")->assertOk();
        $this->assertSoftDeleted('users', ['id' => $lastAdmin->id]);

        // $otherAdmin is now the last one, and cannot delete itself.
        $this->deleteJson("/api/v1/users/{$otherAdmin->id}")
            ->assertStatus(409)
            ->assertJsonPath('code', 'CANNOT_DELETE_SELF');
    }
}
