<?php

namespace Tests\Feature\Authz;

use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrganizationSuperAdminUserTargetTest extends TestCase
{
    use RefreshDatabase;

    public function test_org_super_cannot_change_own_organization_id(): void
    {
        [$org, $actor] = $this->seedOrgSuper();
        $otherOrg = Organization::factory()->create(['is_active' => true]);

        Sanctum::actingAs($actor, ['*']);

        $response = $this->putJson("/api/users/{$actor->id}", [
            'organization_id' => $otherOrg->id,
            'name' => 'Hacker',
        ]);

        $this->assertContains($response->status(), [403, 422], "Unexpected {$response->status()} for self org swap.");
    }

    public function test_org_super_cannot_modify_super_admin_target(): void
    {
        [$org, $actor] = $this->seedOrgSuper();
        $super = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        AuthorizationRole::query()->updateOrCreate(
            ['name' => 'super_admin'],
            [
                'scope_type' => AuthorizationRoleAssignment::SCOPE_ALL,
                'is_admin_role' => true,
                'is_system' => true,
                'is_active' => true,
                'label' => 'Super Admin',
            ],
        );
        $superRole = AuthorizationRole::query()->where('name', 'super_admin')->firstOrFail();
        AuthorizationRoleAssignment::query()->create([
            'user_id' => $super->id,
            'authorization_role_id' => $superRole->id,
            'scope_type' => AuthorizationRoleAssignment::SCOPE_ALL,
            'scope_id' => null,
            'organization_id' => null,
            'is_active' => true,
        ]);

        Sanctum::actingAs($actor, ['*']);

        $response = $this->putJson("/api/users/{$super->id}", ['name' => 'Pwned']);

        $this->assertContains($response->status(), [403, 422], "Unexpected {$response->status()} for super_admin target.");
    }

    public function test_org_super_cannot_modify_other_org_super_target(): void
    {
        [$org, $actor] = $this->seedOrgSuper();
        $otherOrgSuper = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $otherRole = AuthorizationRole::query()->updateOrCreate(
            ['name' => 'organization_super_admin'],
            [
                'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
                'is_admin_role' => false,
                'is_system' => true,
                'is_active' => true,
                'label' => 'Organization Super Admin',
            ],
        );
        AuthorizationRoleAssignment::query()->create([
            'user_id' => $otherOrgSuper->id,
            'authorization_role_id' => $otherRole->id,
            'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
            'scope_id' => $org->id,
            'organization_id' => $org->id,
            'is_active' => true,
        ]);

        Sanctum::actingAs($actor, ['*']);

        $response = $this->putJson("/api/users/{$otherOrgSuper->id}", ['name' => 'Pwned']);

        $this->assertContains($response->status(), [403, 422], "Unexpected {$response->status()} for other Org-Super target.");
    }

    public function test_org_super_can_activate_deactivate_same_org_user(): void
    {
        // POSITIVE test — relies on the catalog pivot seed in seedOrgSuper().
        // Without seeding the role catalog, AccessDecision::can(actor,
        // Capability::USERS_ACTIVATE) returns false and this test fails.
        [$org, $actor] = $this->seedOrgSuper();
        $target = User::factory()->create(['organization_id' => $org->id, 'is_active' => false]);

        Sanctum::actingAs($actor, ['*']);

        $response = $this->putJson("/api/users/{$target->id}", ['is_active' => true]);

        $response->assertOk();
        $this->assertTrue($target->fresh()->is_active);
    }

    public function test_org_super_cannot_mutate_cross_org_user(): void
    {
        [$org, $actor] = $this->seedOrgSuper();
        $crossOrgUser = User::factory()->create([
            'organization_id' => Organization::factory()->create(['is_active' => true])->id,
            'is_active' => true,
        ]);

        Sanctum::actingAs($actor, ['*']);

        $response = $this->putJson("/api/users/{$crossOrgUser->id}", ['name' => 'Cross']);

        $this->assertContains($response->status(), [403, 404], "Unexpected {$response->status()} for cross-org user.");
    }

    // ---- DELETE surface (new in T6) ----

    public function test_org_super_can_delete_same_org_user(): void
    {
        // POSITIVE test — relies on the catalog pivot seed for users.delete.
        [$org, $actor] = $this->seedOrgSuper();
        $target = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);

        Sanctum::actingAs($actor, ['*']);

        $response = $this->deleteJson("/api/users/{$target->id}");

        $response->assertOk();
        $this->assertNull(User::query()->find($target->id), 'same-org target must be soft-deleted.');
    }

    public function test_org_super_cannot_delete_themselves(): void
    {
        // UserPolicy::delete already rejects self-delete (`$user->id === $model->id`).
        // This test pins the policy behavior so a future policy refactor does not
        // silently widen the surface.
        [$org, $actor] = $this->seedOrgSuper();

        Sanctum::actingAs($actor, ['*']);

        $response = $this->deleteJson("/api/users/{$actor->id}");

        $this->assertContains($response->status(), [403, 422], "Unexpected {$response->status()} for self-delete.");
        $this->assertNotNull(User::query()->find($actor->id), 'actor must not be deleted.');
    }

    public function test_org_super_cannot_delete_super_admin_target(): void
    {
        [$org, $actor] = $this->seedOrgSuper();
        $super = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $superRole = AuthorizationRole::query()->updateOrCreate(
            ['name' => 'super_admin'],
            [
                'scope_type' => AuthorizationRoleAssignment::SCOPE_ALL,
                'is_admin_role' => true,
                'is_system' => true,
                'is_active' => true,
                'label' => 'Super Admin',
            ],
        );
        AuthorizationRoleAssignment::query()->create([
            'user_id' => $super->id,
            'authorization_role_id' => $superRole->id,
            'scope_type' => AuthorizationRoleAssignment::SCOPE_ALL,
            'scope_id' => null,
            'organization_id' => null,
            'is_active' => true,
        ]);

        Sanctum::actingAs($actor, ['*']);

        $response = $this->deleteJson("/api/users/{$super->id}");

        $this->assertContains($response->status(), [403, 422], "Unexpected {$response->status()} for super_admin target delete.");
        $this->assertNotNull(User::query()->find($super->id), 'super_admin target must not be deleted.');
    }

    public function test_org_super_cannot_delete_other_org_super_target(): void
    {
        // Per user policy: OrgSuper cannot update OR delete
        // OrganizationSuperAdmin/PlatformSuperAdmin targets.
        [$org, $actor] = $this->seedOrgSuper();
        $otherOrgSuper = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $otherRole = AuthorizationRole::query()->updateOrCreate(
            ['name' => 'organization_super_admin'],
            [
                'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
                'is_admin_role' => false,
                'is_system' => true,
                'is_active' => true,
                'label' => 'Organization Super Admin',
            ],
        );
        AuthorizationRoleAssignment::query()->create([
            'user_id' => $otherOrgSuper->id,
            'authorization_role_id' => $otherRole->id,
            'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
            'scope_id' => $org->id,
            'organization_id' => $org->id,
            'is_active' => true,
        ]);

        Sanctum::actingAs($actor, ['*']);

        $response = $this->deleteJson("/api/users/{$otherOrgSuper->id}");

        $this->assertContains($response->status(), [403, 422], "Unexpected {$response->status()} for Org-Super target delete.");
        $this->assertNotNull(User::query()->find($otherOrgSuper->id), 'Org-Super target must not be deleted.');
    }

    public function test_org_super_cannot_delete_cross_org_user(): void
    {
        [$org, $actor] = $this->seedOrgSuper();
        $crossOrgUser = User::factory()->create([
            'organization_id' => Organization::factory()->create(['is_active' => true])->id,
            'is_active' => true,
        ]);

        Sanctum::actingAs($actor, ['*']);

        $response = $this->deleteJson("/api/users/{$crossOrgUser->id}");

        $this->assertContains($response->status(), [403, 404], "Unexpected {$response->status()} for cross-org user delete.");
        $this->assertNotNull(User::query()->find($crossOrgUser->id), 'cross-org user must not be deleted.');
    }

    /**
     * Seeds OrgSuper role + assignment AND the curated capability pivot set
     * via `RolesAndPermissionsSeeder::roleCatalog()` so positive tests can
     * resolve `Capability::USERS_ACTIVATE`, `Capability::USERS_DEACTIVATE`,
     * `Capability::USERS_DELETE`, etc. through the engine.
     *
     * @return array{0: Organization, 1: User}
     */
    private function seedOrgSuper(): array
    {
        $org = Organization::factory()->create(['is_active' => true]);
        $user = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);

        // TestCase::setUp() already seeds RolesAndPermissionsSeeder (which provisions
        // authorization_resources + the curated organization_super_admin capability
        // pivots), so AccessDecision::can() can resolve USERS_ACTIVATE /
        // USERS_DEACTIVATE / USERS_DELETE for the OrgSuper actor. Re-running the
        // seeder here causes a nested-transaction deadlock during migrate:fresh
        // teardown on the second pass.

        $role = AuthorizationRole::query()->where('name', 'organization_super_admin')->firstOrFail();

        AuthorizationRoleAssignment::query()->create([
            'user_id' => $user->id,
            'authorization_role_id' => $role->id,
            'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
            'scope_id' => $org->id,
            'organization_id' => $org->id,
            'is_active' => true,
        ]);

        return [$org, $user];
    }
}
