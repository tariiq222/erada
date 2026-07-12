<?php

namespace Tests\Feature\HR;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Department capacity-role endpoints (member/manager):
 *  - PUT writes the policy with the observer muted, then runs syncDepartment ONCE
 *    so existing members receive their auto roles.
 *  - GET returns the current member/manager role keys plus the available
 *    department-scoped definitions only.
 *  - Cross-org actor must not be able to read or mutate a department that
 *    belongs to a different organization (sharesOrganization guard).
 */
class DepartmentCapacityRoleEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected Organization $otherOrganization;

    protected Department $otherOrgDepartment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->organization = Organization::factory()->create();
        $this->otherOrganization = Organization::factory()->create();

        $this->otherOrgDepartment = Department::factory()->create([
            'organization_id' => $this->otherOrganization->id,
        ]);
    }

    /**
     * Same-org user that holds the engine capability for departments.view/edit.
     */
    private function orgAdmin(): User
    {
        $user = User::factory()->create([
            'organization_id' => $this->organization->id,
            'is_active' => true,
        ]);

        return $user;
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->grantCanonicalSuperAdmin($user);

        return $user;
    }

    public function test_put_capacity_roles_sets_policy_and_syncs_once(): void
    {
        $admin = $this->superAdmin();
        $dept = Department::factory()->create(['organization_id' => $this->organization->id]);
        $member = User::factory()->create([
            'department_id' => $dept->id,
            'organization_id' => $this->organization->id,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/hr/departments/{$dept->id}/capacity-roles", [
                'member_role_keys' => ['dept_member'],
                'manager_role_keys' => ['dept_manager'],
            ])->assertOk();

        $this->assertDatabaseHas('department_capacity_roles', [
            'department_id' => $dept->id, 'capacity' => 'member', 'role_key' => 'dept_member',
        ]);
        $this->assertDatabaseHas('department_capacity_roles', [
            'department_id' => $dept->id, 'capacity' => 'manager', 'role_key' => 'dept_manager',
        ]);
        // existing member got the auto role via the single post-batch sync
        $memberRole = AuthorizationRole::query()->where('name', 'dept_member')->firstOrFail();
        $this->assertDatabaseHas('authorization_role_assignments', [
            'user_id' => $member->id, 'authorization_role_id' => $memberRole->id,
            'scope_type' => 'department', 'scope_id' => $dept->id, 'source' => 'auto',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/hr/departments/{$dept->id}/capacity-roles")
            ->assertOk()
            ->assertJsonPath('member_role_keys', ['dept_member'])
            ->assertJsonPath('manager_role_keys', ['dept_manager']);
    }

    public function test_get_capacity_roles_lists_only_semantically_compatible_department_definitions(): void
    {
        $admin = $this->superAdmin();
        $dept = Department::factory()->create(['organization_id' => $this->organization->id]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/hr/departments/{$dept->id}/capacity-roles")
            ->assertOk()
            ->assertJsonStructure([
                'member_role_keys',
                'manager_role_keys',
                'available' => [
                    '*' => ['role_id', 'role_key', 'name', 'label', 'scope', 'capabilities'],
                ],
            ]);

        $available = collect($response->json('available'));
        $this->assertTrue($available->firstWhere('role_key', 'dept_member')['scope'] === 'department');
        $this->assertNull($available->firstWhere('role_key', 'quality_manager'));
        $this->assertNotEmpty($available->firstWhere('role_key', 'dept_manager')['capabilities']);
    }

    public function test_put_capacity_roles_replaces_previous_policy(): void
    {
        $admin = $this->superAdmin();
        $dept = Department::factory()->create(['organization_id' => $this->organization->id]);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/hr/departments/{$dept->id}/capacity-roles", [
                'member_role_keys' => ['dept_member'],
                'manager_role_keys' => ['dept_manager'],
            ])->assertOk();

        // Replace with an empty manager set and a different member set.
        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/hr/departments/{$dept->id}/capacity-roles", [
                'member_role_keys' => ['dept_manager'],
                'manager_role_keys' => [],
            ])->assertOk();

        $this->assertDatabaseHas('department_capacity_roles', [
            'department_id' => $dept->id, 'capacity' => 'member', 'role_key' => 'dept_manager',
        ]);
        $this->assertDatabaseMissing('department_capacity_roles', [
            'department_id' => $dept->id, 'capacity' => 'member', 'role_key' => 'dept_member',
        ]);
        $this->assertDatabaseMissing('department_capacity_roles', [
            'department_id' => $dept->id, 'capacity' => 'manager',
        ]);
    }

    public function test_available_endpoint_lists_definitions_without_a_department(): void
    {
        $admin = $this->superAdmin();

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/hr/departments/capacity-roles/available')
            ->assertOk()
            ->assertJsonStructure([
                'available' => [
                    '*' => ['role_id', 'role_key', 'name', 'label', 'scope', 'capabilities'],
                ],
            ]);

        $available = collect($response->json('available'))->pluck('role_key');
        $this->assertTrue($available->contains('dept_member'));
        $this->assertTrue($available->contains('dept_manager'));
        $this->assertFalse($available->contains('quality_manager'));
    }

    public function test_put_rejects_inactive_or_non_capacity_scope_canonical_roles(): void
    {
        $admin = $this->superAdmin();
        $dept = Department::factory()->create(['organization_id' => $this->organization->id]);

        AuthorizationRole::query()->where('name', 'dept_member')->update(['is_active' => false]);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/hr/departments/{$dept->id}/capacity-roles", [
                'member_role_keys' => ['dept_member', 'project_member', 'quality_manager'],
                'manager_role_keys' => [],
            ])->assertUnprocessable()
            ->assertJsonValidationErrors(['member_role_keys.0', 'member_role_keys.1', 'member_role_keys.2']);

        $this->assertDatabaseMissing('department_capacity_roles', ['department_id' => $dept->id]);
    }

    public function test_put_capacity_roles_denies_user_without_edit_departments(): void
    {
        $plain = User::factory()->create([
            'organization_id' => $this->organization->id,
        ]);
        $this->assignCanonicalRole($plain, 'viewer', 'organization', $this->organization->id, [Capability::DEPARTMENTS_VIEW]);
        $dept = Department::factory()->create(['organization_id' => $this->organization->id]);

        $this->actingAs($plain, 'sanctum')
            ->putJson("/api/hr/departments/{$dept->id}/capacity-roles", [
                'member_role_keys' => ['dept_member'],
                'manager_role_keys' => [],
            ])->assertForbidden();

        $this->assertDatabaseMissing('department_capacity_roles', [
            'department_id' => $dept->id,
        ]);
    }

    /**
     * A1 — Cross-org actor must not be able to mutate a foreign-org
     * department's capacity-role policy. The sharesOrganization guard in the
     * FormRequest's authorize() must short-circuit before any policy rows are
     * written.
     */
    public function test_cross_org_actor_cannot_put_capacity_roles_on_foreign_department(): void
    {
        $actor = $this->orgAdmin();
        // grant engine capability for departments.edit so the engine itself
        // would otherwise allow the request — proving sharesOrganization is
        // the active gate.
        $this->assignCanonicalRole($actor, 'viewer', 'organization', $this->organization->id, [Capability::DEPARTMENTS_EDIT]);

        $foreign = $this->otherOrgDepartment;

        $response = $this->actingAs($actor, 'sanctum')
            ->putJson("/api/hr/departments/{$foreign->id}/capacity-roles", [
                'member_role_keys' => ['dept_member'],
                'manager_role_keys' => ['dept_manager'],
            ]);

        // Foreign id is in the URL (not the body), so the isolation contract
        // permits either a 403 (policy/guard) or a 404 (route model binding).
        $status = $response->status();
        $this->assertContains($status, [403, 404], 'cross-org PUT should be denied (403) or hidden (404); got '.$status);

        // Nothing must be persisted on the foreign org's department.
        $this->assertDatabaseMissing('department_capacity_roles', [
            'department_id' => $foreign->id,
        ]);
    }

    /**
     * A2 — Cross-org actor must not be able to read a foreign-org
     * department's capacity-role policy. The controller's sharesOrganization
     * guard short-circuits with 403 before the policy rows are returned.
     */
    public function test_cross_org_actor_cannot_get_capacity_roles_on_foreign_department(): void
    {
        $actor = $this->orgAdmin();
        $this->assignCanonicalRole($actor, 'viewer', 'organization', $this->organization->id, [Capability::DEPARTMENTS_VIEW]);

        $foreign = $this->otherOrgDepartment;

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson("/api/hr/departments/{$foreign->id}/capacity-roles");

        $status = $response->status();
        $this->assertContains($status, [403, 404], 'cross-org GET should be denied (403) or hidden (404); got '.$status);
    }
}
