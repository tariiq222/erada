<?php

namespace Tests\Feature\Authz;

use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Shared\Models\ActivityLog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrganizationSuperAdminRoleAllowlistTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Positive case — OrgSuper assigns an operational role to a same-org
     * ordinary user via the new dedicated route. This is the ONLY positive
     * case in the matrix; every other test is a denial.
     */
    public function test_org_super_can_assign_operational_role_to_same_org_user(): void
    {
        [$org, $actor] = $this->seedOrgSuper();
        $target = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $role = AuthorizationRole::query()->updateOrCreate(
            ['name' => 'manager'],
            [
                'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
                'is_admin_role' => false,
                'is_system' => false,
                'is_active' => true,
                'label' => 'Manager',
            ],
        );

        Sanctum::actingAs($actor, ['*']);

        $response = $this->postJson('/api/org-super/role-assignments', [
            'user_id' => $target->id,
            'replace_all' => true,
            'assignments' => [[
                'role_id' => $role->id,
                'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
                'scope_id' => $org->id,
                'inherit_to_children' => false,
            ]],
        ]);

        $response->assertOk();
    }

    public function test_org_super_cannot_assign_admin_role(): void
    {
        [$org, $actor] = $this->seedOrgSuper();
        $target = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $adminRole = AuthorizationRole::query()->updateOrCreate(
            ['name' => 'admin'],
            [
                'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
                'is_admin_role' => true,
                'is_system' => false,
                'is_active' => true,
                'label' => 'Organization Admin',
            ],
        );

        Sanctum::actingAs($actor, ['*']);

        $response = $this->postJson('/api/org-super/role-assignments', [
            'user_id' => $target->id,
            'replace_all' => true,
            'assignments' => [[
                'role_id' => $adminRole->id,
                'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
                'scope_id' => $org->id,
                'inherit_to_children' => false,
            ]],
        ]);

        $this->assertContains($response->status(), [403, 422], "Unexpected {$response->status()} for admin role assign.");
    }

    public function test_org_super_cannot_assign_super_admin_role(): void
    {
        [$org, $actor] = $this->seedOrgSuper();
        $target = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
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

        Sanctum::actingAs($actor, ['*']);

        $response = $this->postJson('/api/org-super/role-assignments', [
            'user_id' => $target->id,
            'replace_all' => true,
            'assignments' => [[
                'role_id' => $superRole->id,
                'scope_type' => AuthorizationRoleAssignment::SCOPE_ALL,
                'scope_id' => null,
                'inherit_to_children' => false,
            ]],
        ]);

        $this->assertContains($response->status(), [403, 422], "Unexpected {$response->status()} for super_admin assign.");
    }

    public function test_org_super_cannot_assign_organization_super_admin_role(): void
    {
        [$org, $actor] = $this->seedOrgSuper();
        $target = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $orgSuperRole = AuthorizationRole::query()->where('name', 'organization_super_admin')->firstOrFail();

        Sanctum::actingAs($actor, ['*']);

        $response = $this->postJson('/api/org-super/role-assignments', [
            'user_id' => $target->id,
            'replace_all' => true,
            'assignments' => [[
                'role_id' => $orgSuperRole->id,
                'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
                'scope_id' => $org->id,
                'inherit_to_children' => false,
            ]],
        ]);

        $this->assertContains($response->status(), [403, 422], "Unexpected {$response->status()} for organization_super_admin assign.");
    }

    public function test_org_super_cannot_assign_role_with_is_admin_role_flag(): void
    {
        [$org, $actor] = $this->seedOrgSuper();
        $target = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $forbidden = AuthorizationRole::query()->updateOrCreate(
            ['name' => 'cluster_auditor'],
            [
                'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
                'is_admin_role' => true,
                'is_system' => false,
                'is_active' => true,
                'label' => 'Cluster Auditor',
            ],
        );

        Sanctum::actingAs($actor, ['*']);

        $response = $this->postJson('/api/org-super/role-assignments', [
            'user_id' => $target->id,
            'replace_all' => true,
            'assignments' => [[
                'role_id' => $forbidden->id,
                'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
                'scope_id' => $org->id,
                'inherit_to_children' => false,
            ]],
        ]);

        $this->assertContains($response->status(), [403, 422], "Unexpected {$response->status()} for is_admin_role=true.");
    }

    public function test_org_super_cannot_assign_role_with_is_system_flag(): void
    {
        [$org, $actor] = $this->seedOrgSuper();
        $target = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $forbidden = AuthorizationRole::query()->updateOrCreate(
            ['name' => 'archived_role'],
            [
                'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
                'is_admin_role' => false,
                'is_system' => true,
                'is_active' => true,
                'label' => 'Archived System Role',
            ],
        );

        Sanctum::actingAs($actor, ['*']);

        $response = $this->postJson('/api/org-super/role-assignments', [
            'user_id' => $target->id,
            'replace_all' => true,
            'assignments' => [[
                'role_id' => $forbidden->id,
                'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
                'scope_id' => $org->id,
                'inherit_to_children' => false,
            ]],
        ]);

        $this->assertContains($response->status(), [403, 422], "Unexpected {$response->status()} for is_system=true.");
    }

    public function test_org_super_cannot_assign_inactive_role(): void
    {
        [$org, $actor] = $this->seedOrgSuper();
        $target = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $inactive = AuthorizationRole::query()->updateOrCreate(
            ['name' => 'retired_manager'],
            [
                'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
                'is_admin_role' => false,
                'is_system' => false,
                'is_active' => false,
                'label' => 'Retired Manager',
            ],
        );

        Sanctum::actingAs($actor, ['*']);

        $response = $this->postJson('/api/org-super/role-assignments', [
            'user_id' => $target->id,
            'replace_all' => true,
            'assignments' => [[
                'role_id' => $inactive->id,
                'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
                'scope_id' => $org->id,
                'inherit_to_children' => false,
            ]],
        ]);

        $this->assertContains($response->status(), [403, 422], "Unexpected {$response->status()} for inactive role.");
    }

    public function test_org_super_cannot_assign_to_cross_org_user(): void
    {
        [$org, $actor] = $this->seedOrgSuper();
        $crossOrg = User::factory()->create([
            'organization_id' => Organization::factory()->create(['is_active' => true])->id,
            'is_active' => true,
        ]);
        $role = AuthorizationRole::query()->where('name', 'member')->firstOrFail();

        Sanctum::actingAs($actor, ['*']);

        $response = $this->postJson('/api/org-super/role-assignments', [
            'user_id' => $crossOrg->id,
            'replace_all' => true,
            'assignments' => [[
                'role_id' => $role->id,
                'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
                'scope_id' => $org->id,
                'inherit_to_children' => false,
            ]],
        ]);

        $this->assertContains($response->status(), [403, 422], "Unexpected {$response->status()} for cross-org subject.");
    }

    public function test_org_super_cannot_assign_to_super_admin_target(): void
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
        $role = AuthorizationRole::query()->where('name', 'member')->firstOrFail();

        Sanctum::actingAs($actor, ['*']);

        $response = $this->postJson('/api/org-super/role-assignments', [
            'user_id' => $super->id,
            'replace_all' => true,
            'assignments' => [[
                'role_id' => $role->id,
                'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
                'scope_id' => $org->id,
                'inherit_to_children' => false,
            ]],
        ]);

        $this->assertContains($response->status(), [403, 422], "Unexpected {$response->status()} for super_admin target.");
    }

    public function test_org_super_cannot_assign_to_organization_super_admin_target(): void
    {
        [$org, $actor] = $this->seedOrgSuper();
        $otherOrgSuper = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $orgSuperRole = AuthorizationRole::query()->where('name', 'organization_super_admin')->firstOrFail();
        AuthorizationRoleAssignment::query()->create([
            'user_id' => $otherOrgSuper->id,
            'authorization_role_id' => $orgSuperRole->id,
            'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
            'scope_id' => $org->id,
            'organization_id' => $org->id,
            'is_active' => true,
        ]);
        $role = AuthorizationRole::query()->where('name', 'member')->firstOrFail();

        Sanctum::actingAs($actor, ['*']);

        $response = $this->postJson('/api/org-super/role-assignments', [
            'user_id' => $otherOrgSuper->id,
            'replace_all' => true,
            'assignments' => [[
                'role_id' => $role->id,
                'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
                'scope_id' => $org->id,
                'inherit_to_children' => false,
            ]],
        ]);

        $this->assertContains($response->status(), [403, 422], "Unexpected {$response->status()} for organization_super_admin target.");
    }

    public function test_org_super_cannot_assign_with_cross_org_scope_id(): void
    {
        // Client scope manipulation: client tries to write a different org's
        // scope_id. Server must reject even when subject is in actor's org.
        [$org, $actor] = $this->seedOrgSuper();
        $otherOrg = Organization::factory()->create(['is_active' => true]);
        $target = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $role = AuthorizationRole::query()->where('name', 'member')->firstOrFail();

        Sanctum::actingAs($actor, ['*']);

        $response = $this->postJson('/api/org-super/role-assignments', [
            'user_id' => $target->id,
            'replace_all' => true,
            'assignments' => [[
                'role_id' => $role->id,
                'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
                'scope_id' => $otherOrg->id,
                'inherit_to_children' => false,
            ]],
        ]);

        $this->assertContains($response->status(), [403, 422], "Unexpected {$response->status()} for cross-org scope_id.");
    }

    public function test_org_super_cannot_assign_with_non_organization_scope_type(): void
    {
        [$org, $actor] = $this->seedOrgSuper();
        $target = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $role = AuthorizationRole::query()->updateOrCreate(
            ['name' => 'dept_only_role'],
            [
                'scope_type' => 'department',
                'is_admin_role' => false,
                'is_system' => false,
                'is_active' => true,
                'label' => 'Department Only Role',
            ],
        );

        Sanctum::actingAs($actor, ['*']);

        $response = $this->postJson('/api/org-super/role-assignments', [
            'user_id' => $target->id,
            'replace_all' => true,
            'assignments' => [[
                'role_id' => $role->id,
                'scope_type' => 'department',
                'scope_id' => 1,
                'inherit_to_children' => false,
            ]],
        ]);

        $this->assertContains($response->status(), [403, 422], "Unexpected {$response->status()} for non-organization scope.");
    }

    public function test_org_super_cannot_assign_with_inherit_to_children_true(): void
    {
        [$org, $actor] = $this->seedOrgSuper();
        $target = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $role = AuthorizationRole::query()->where('name', 'member')->firstOrFail();

        Sanctum::actingAs($actor, ['*']);

        $response = $this->postJson('/api/org-super/role-assignments', [
            'user_id' => $target->id,
            'replace_all' => true,
            'assignments' => [[
                'role_id' => $role->id,
                'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
                'scope_id' => $org->id,
                'inherit_to_children' => true,
            ]],
        ]);

        $this->assertContains($response->status(), [403, 422], "Unexpected {$response->status()} for inherit_to_children=true.");
    }

    public function test_regular_user_cannot_use_org_super_route(): void
    {
        // Middleware gate: roles.assign is held by OrgSuper only.
        $org = Organization::factory()->create(['is_active' => true]);
        $regular = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $target = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $role = AuthorizationRole::query()->where('name', 'member')->firstOrFail();

        Sanctum::actingAs($regular, ['*']);

        $response = $this->postJson('/api/org-super/role-assignments', [
            'user_id' => $target->id,
            'replace_all' => true,
            'assignments' => [[
                'role_id' => $role->id,
                'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
                'scope_id' => $org->id,
                'inherit_to_children' => false,
            ]],
        ]);

        $response->assertForbidden();
    }

    public function test_super_admin_uses_canonical_route_not_org_super_route(): void
    {
        // super_admin holds core.assign_roles (canonical route) but NOT
        // roles.assign (OrgSuper route). The OrgSuper route MUST reject
        // super_admin; the canonical route is the only path for super_admin.
        $org = Organization::factory()->create(['is_active' => true]);
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
        $target = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $role = AuthorizationRole::query()->where('name', 'member')->firstOrFail();

        Sanctum::actingAs($super, ['*']);

        $response = $this->postJson('/api/org-super/role-assignments', [
            'user_id' => $target->id,
            'replace_all' => true,
            'assignments' => [[
                'role_id' => $role->id,
                'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
                'scope_id' => $org->id,
                'inherit_to_children' => false,
            ]],
        ]);

        $response->assertForbidden();
    }

    public function test_org_super_role_assignment_writes_activity_log_with_provenance(): void
    {
        [$org, $actor] = $this->seedOrgSuper();
        $target = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $role = AuthorizationRole::query()->where('name', 'member')->firstOrFail();

        Sanctum::actingAs($actor, ['*']);

        $response = $this->postJson('/api/org-super/role-assignments', [
            'user_id' => $target->id,
            'replace_all' => true,
            'assignments' => [[
                'role_id' => $role->id,
                'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
                'scope_id' => $org->id,
                'inherit_to_children' => false,
            ]],
        ]);

        $response->assertOk();

        $audit = ActivityLog::query()
            ->where('user_id', $actor->id)
            ->where('loggable_id', $target->id)
            ->where('loggable_type', User::class)
            ->latest('id')
            ->first();

        $this->assertNotNull($audit, 'audit log row must exist for the assignment');
        $this->assertSame('organization_super_admin', $audit->metadata['provenance'] ?? null);
    }

    public function test_super_admin_with_roles_assign_pivot_is_still_rejected(): void
    {
        // Edge case: a super_admin who was inadvertently seeded an OrgSuper
        // role assignment (e.g., an operator pivoted a PlatformSuperAdmin
        // to also hold organization_super_admin). The route's
        // `ensure.org_super_only` middleware MUST reject super_admin even
        // if they hold Capability::ROLES_ASSIGN. This is the "genuine
        // OrgSuper" requirement.
        $org = Organization::factory()->create(['is_active' => true]);
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
        // AND seed an OrgSuper pivot on the same user.
        $orgSuperRole = AuthorizationRole::query()->where('name', 'organization_super_admin')->firstOrFail();
        AuthorizationRoleAssignment::query()->create([
            'user_id' => $super->id,
            'authorization_role_id' => $orgSuperRole->id,
            'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
            'scope_id' => $org->id,
            'organization_id' => $org->id,
            'is_active' => true,
        ]);
        $target = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $role = AuthorizationRole::query()->where('name', 'member')->firstOrFail();

        Sanctum::actingAs($super, ['*']);

        $response = $this->postJson('/api/org-super/role-assignments', [
            'user_id' => $target->id,
            'replace_all' => true,
            'assignments' => [[
                'role_id' => $role->id,
                'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
                'scope_id' => $org->id,
                'inherit_to_children' => false,
            ]],
        ]);

        $response->assertForbidden();
    }

    public function test_org_super_with_null_organization_is_rejected(): void
    {
        // Edge case: an OrgSuper actor with no organization context cannot
        // derive scope. The route's `ensure.org_super_only` middleware
        // rejects the request before the FormRequest layer.
        //
        // The actor MUST satisfy User::isOrganizationSuperAdmin() (which
        // requires `scope_id IS NOT NULL` on the OrgSuper pivot) AND have
        // a null `organization_id` on the User row so the middleware's
        // null-org check fires. The DB-level CHECK constraint requires
        // `scope_id IS NOT NULL` when `scope_type = 'organization'`, so we
        // create a real org to satisfy the FK and use its id as scope_id;
        // the user's `organization_id = null` is independent.
        $dummyOrg = Organization::factory()->create(['is_active' => true]);
        $user = User::factory()->create(['organization_id' => null, 'is_active' => true]);
        $orgSuperRole = AuthorizationRole::query()->where('name', 'organization_super_admin')->firstOrFail();
        AuthorizationRoleAssignment::query()->create([
            'user_id' => $user->id,
            'authorization_role_id' => $orgSuperRole->id,
            'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
            'scope_id' => $dummyOrg->id,
            'organization_id' => $dummyOrg->id,
            'is_active' => true,
        ]);
        $target = User::factory()->create(['organization_id' => null, 'is_active' => true]);
        $role = AuthorizationRole::query()->where('name', 'member')->firstOrFail();

        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/org-super/role-assignments', [
            'user_id' => $target->id,
            'replace_all' => true,
            'assignments' => [[
                'role_id' => $role->id,
                'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
                'scope_id' => 1,
                'inherit_to_children' => false,
            ]],
        ]);

        $response->assertForbidden();
    }

    /**
     * @return array{0: Organization, 1: User}
     */
    private function seedOrgSuper(): array
    {
        // The TestCase parent setUp() seeds RolesAndPermissionsSeeder, which
        // provisions authorization_roles + the curated organization_super_admin
        // capability pivots. Re-running the seeder here causes a nested-
        // transaction deadlock during migrate:fresh teardown.
        $org = Organization::factory()->create(['is_active' => true]);
        $user = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
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
