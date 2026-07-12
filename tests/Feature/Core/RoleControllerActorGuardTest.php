<?php

namespace Tests\Feature\Core;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Authorization\Models\AuthorizationResource;
use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Authorization\Models\AuthorizationRolePermission;
use App\Modules\Core\Authorization\Support\CapabilityToAuthorizationRolePermission;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CSD-CA23078-CORE-006 (High) — privilege escalation guard around role
 * DEFINITION mutations.
 *
 * A canonical super_admin is the only actor authorized to inject a
 * capability they do not already hold into a role that has existing
 * assignees, and the only actor authorized to mutate a role whose
 * `is_admin_role` flag is set or whose payload grants
 * `core.assign_roles`. Every other mutation must be rejected with 403,
 * the `authorization_role_permissions` pivot must remain untouched, and
 * the `authorization_assignment_audits` audit table must NOT receive an
 * event row.
 */
class RoleControllerActorGuardTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Department $department;

    private User $adminActor;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->organization = Organization::factory()->create();
        $this->department = Department::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        // Admin actor: holds ROLES_EDIT + CORE_ASSIGN_ROLES via a CUSTOM role
        // that explicitly OMITS OVR_CLOSE, so the security check must reject
        // any attempt to inject OVR_CLOSE on their behalf.
        $this->adminActor = User::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
        ]);
        $this->assignCustomRoleWithCapabilities(
            $this->adminActor,
            'csd_admin_limited',
            'organization',
            $this->organization->id,
            [
                Capability::ROLES_VIEW,
                Capability::ROLES_EDIT,
                Capability::ROLES_CREATE,
                Capability::CORE_ASSIGN_ROLES,
            ],
        );

        // Canonical super_admin: a real super_admin assignment that the
        // Engine's `isSuperAdmin()` predicate will resolve truthfully.
        $this->superAdmin = User::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
        ]);
        $this->grantCanonicalSuperAdmin($this->superAdmin);

        AccessDecision::flushCache();
    }

    /**
     * Verified-regression (CSD-CA23078-CORE-006): an admin user that holds
     * `roles.edit` + `core.assign_roles` but does NOT hold `ovr.close` MUST
     * not be able to inject `ovr.close` into a role already assigned to
     * themselves via `PUT /api/roles/{roleDefinition}`. The 403 must short-
     * circuit before any DB write reaches `authorization_role_permissions`
     * or `authorization_assignment_audits`.
     */
    public function test_non_super_admin_with_roles_edit_cannot_inject_ovr_close_into_role_already_assigned_to_them(): void
    {
        // A scoped role assigned to the admin actor. The role's declared
        // capability set does NOT include `ovr.close`.
        $role = $this->createRoleWithCapabilities('project_reviewer', 'organization', [
            Capability::PROJECTS_VIEW,
            Capability::ROLES_VIEW,
        ]);

        AuthorizationRoleAssignment::query()->create([
            'authorization_role_id' => $role->id,
            'user_id' => $this->adminActor->id,
            'scope_type' => 'organization',
            'scope_id' => $this->organization->id,
            'organization_id' => $this->organization->id,
            'inherit_to_children' => false,
            'source' => 'manual',
            'granted_by' => $this->superAdmin->id,
        ]);

        $pivotCountBefore = (int) \DB::table('authorization_role_permissions')
            ->where('authorization_role_id', $role->id)
            ->count();
        $auditCountBefore = (int) \DB::table('authorization_assignment_audits')->count();

        $response = $this->actingAs($this->adminActor, 'sanctum')
            ->putJson("/api/roles/{$role->id}", [
                'capabilities' => [
                    Capability::PROJECTS_VIEW,
                    Capability::ROLES_VIEW,
                    Capability::OVR_CLOSE,
                ],
            ]);

        $response->assertStatus(403);

        // Pivot must NOT receive a new (role, ovr.close) row.
        $this->assertDatabaseMissing('authorization_role_permissions', [
            'authorization_role_id' => $role->id,
            'action' => 'close',
        ]);

        // No additional audit event must have been written for this role.
        $auditCountAfter = (int) \DB::table('authorization_assignment_audits')->count();
        $this->assertSame($auditCountBefore, $auditCountAfter);

        // The pre-existing pivot rows must still be intact.
        $pivotCountAfter = (int) \DB::table('authorization_role_permissions')
            ->where('authorization_role_id', $role->id)
            ->count();
        $this->assertSame($pivotCountBefore, $pivotCountAfter);
    }

    /**
     * Companion check (positive control): a canonical super_admin IS allowed
     * to inject the same `ovr.close` capability into the same role. The
     * pivot must gain the new row and an audit event must be written.
     */
    public function test_super_admin_can_inject_new_capability_into_custom_role(): void
    {
        $role = $this->createRoleWithCapabilities('project_reviewer_super', 'organization', [
            Capability::PROJECTS_VIEW,
            Capability::ROLES_VIEW,
        ]);

        AuthorizationRoleAssignment::query()->create([
            'authorization_role_id' => $role->id,
            'user_id' => $this->adminActor->id,
            'scope_type' => 'organization',
            'scope_id' => $this->organization->id,
            'organization_id' => $this->organization->id,
            'inherit_to_children' => false,
            'source' => 'manual',
            'granted_by' => $this->superAdmin->id,
        ]);

        $auditCountBefore = (int) \DB::table('authorization_assignment_audits')->count();

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson("/api/roles/{$role->id}", [
                'capabilities' => [
                    Capability::PROJECTS_VIEW,
                    Capability::ROLES_VIEW,
                    Capability::OVR_CLOSE,
                ],
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('authorization_role_permissions', [
            'authorization_role_id' => $role->id,
            'action' => 'close',
        ]);

        $auditCountAfter = (int) \DB::table('authorization_assignment_audits')->count();
        $this->assertGreaterThan($auditCountBefore, $auditCountAfter);
    }

    /**
     * Verified-regression (CSD-CA23078-CORE-006): even an actor that holds
     * `roles.edit` + `core.assign_roles` MUST NOT be permitted to mutate a
     * role whose `is_admin_role` flag is set, unless the actor is a
     * canonical super_admin. We exercise the guard via a custom role with
     * `is_admin_role=true` that the existing `isSystemRole()` filter does
     * not catch, isolating the new admin-role gate.
     */
    public function test_roles_edit_alone_is_rejected_for_admin_role(): void
    {
        // Custom admin-flagged role: name distinct from 'admin' / 'super_admin'
        // / 'viewer' so the existing `isSystemRole()` does NOT short-circuit
        // before the actor guard runs.
        $role = AuthorizationRole::query()->create([
            'name' => 'legacy_admin_alter_ego',
            'label' => 'Legacy Admin (test-only)',
            'scope_type' => 'organization',
            'is_admin_role' => true,
            'is_system' => false,
            'is_active' => true,
        ]);

        $pivotCountBefore = (int) \DB::table('authorization_role_permissions')
            ->where('authorization_role_id', $role->id)
            ->count();
        $auditCountBefore = (int) \DB::table('authorization_assignment_audits')->count();

        $response = $this->actingAs($this->adminActor, 'sanctum')
            ->putJson("/api/roles/{$role->id}", [
                'label' => 'Edited by limited admin',
            ]);

        $response->assertStatus(403);

        // The role's `label` must remain untouched because the actor guard
        // short-circuited before the role row was updated.
        $this->assertDatabaseHas('authorization_roles', [
            'id' => $role->id,
            'label' => 'Legacy Admin (test-only)',
        ]);

        // No audit event must have been written.
        $auditCountAfter = (int) \DB::table('authorization_assignment_audits')->count();
        $this->assertSame($auditCountBefore, $auditCountAfter);

        // The pivot must still be empty (no permission rows).
        $pivotCountAfter = (int) \DB::table('authorization_role_permissions')
            ->where('authorization_role_id', $role->id)
            ->count();
        $this->assertSame($pivotCountBefore, $pivotCountAfter);
    }

    /**
     * Build a freshly-named role definition with the requested capability
     * set and materialise one `authorization_role_permissions` row per
     * capability. Returns the persisted AuthorizationRole.
     *
     * @param  list<string>  $capabilities
     */
    private function createRoleWithCapabilities(string $name, string $scopeType, array $capabilities): AuthorizationRole
    {
        $role = AuthorizationRole::query()->create([
            'name' => $name,
            'label' => $name,
            'scope_type' => $scopeType,
            'is_admin_role' => false,
            'is_system' => false,
            'is_active' => true,
        ]);

        foreach ($capabilities as $capability) {
            $this->attachCapability($role->id, $capability);
        }

        AccessDecision::flushCache();

        return $role;
    }

    /**
     * Assign the named (custom) role to a user with an explicit capability
     * set, registered through the canonical AuthorizationRole /
     * AuthorizationRolePermission pivot.
     *
     * @param  list<string>  $capabilities
     */
    private function assignCustomRoleWithCapabilities(
        User $user,
        string $roleName,
        string $scopeType,
        ?int $scopeId,
        array $capabilities,
    ): AuthorizationRoleAssignment {
        $role = $this->createRoleWithCapabilities($roleName, $scopeType, $capabilities);

        $assignment = AuthorizationRoleAssignment::query()->create([
            'authorization_role_id' => $role->id,
            'user_id' => $user->id,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'organization_id' => $scopeType === 'organization' ? $scopeId : null,
            'inherit_to_children' => false,
            'source' => 'manual',
            'granted_by' => null,
        ]);

        AccessDecision::flushCache();

        return $assignment;
    }

    private function attachCapability(int $roleId, string $capability): void
    {
        $mapping = CapabilityToAuthorizationRolePermission::map($capability);
        if ($mapping === null) {
            return;
        }

        $resource = AuthorizationResource::query()->firstOrCreate(
            ['key' => $mapping['resource']],
            ['label' => class_basename($mapping['resource'])],
        );

        AuthorizationRolePermission::query()->updateOrCreate(
            [
                'authorization_role_id' => $roleId,
                'authorization_resource_id' => $resource->id,
                'action' => $mapping['action'],
            ],
            ['reach' => null],
        );
    }
}
