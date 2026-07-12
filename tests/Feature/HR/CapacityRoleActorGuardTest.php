<?php

namespace Tests\Feature\HR;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CSD-CA23078-HR-002 — Privilege-escalation actor guard for the
 * `PUT /api/hr/departments/{id}/capacity-roles` endpoint.
 *
 * The endpoint persists the per-department capacity-role policy that drives
 * automatic canonical role assignments. The FormRequest's `authorize()` only
 * requires `departments.edit`, which is coarser than the privilege needed to
 * configure a role that grants capabilities the actor does not themselves
 * hold — letting a bare departments.edit actor persist `dept_manager` would
 * escalate the department's manager to a role with admin-grade capabilities
 * (`departments.manage_members`, `projects.delete`, `tasks.delete`,
 * `ovr.investigate`, `ovr.close`, …) without anyone with `core.assign_roles`
 * ever touching the binding.
 *
 * The controller now layers on top of `authorize()`:
 *   - canonical super_admin ⇒ always admitted;
 *   - everyone else must pass a per-capability subset check — every resolved
 *     role's capabilities must be granted to the actor at the department scope
 *     (`AccessDecision::can(actor, capability, department)`). A role that
 *     grants a capability the actor does not hold ⇒ 403, with an audit row.
 */
class CapacityRoleActorGuardTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->organization = Organization::factory()->create();
    }

    /**
     * A non-super_admin actor that holds `departments.view` + `departments.edit`
     * and nothing else — i.e. the same kind of "departments.edit" user that
     * already gets past the FormRequest's `authorize()` today. They have no
     * `core.assign_roles` and none of the capabilities that `dept_manager`
     * carries (`departments.manage_members`, `projects.delete`, …).
     */
    private function departmentsEditOnlyActor(): User
    {
        $actor = User::factory()->create([
            'organization_id' => $this->organization->id,
            'is_active' => true,
        ]);

        $this->assignCanonicalRole(
            $actor,
            'viewer',
            AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
            $this->organization->id,
            [Capability::DEPARTMENTS_VIEW, Capability::DEPARTMENTS_EDIT],
        );

        return $actor;
    }

    /**
     * Same kind of actor (departments.edit), but additionally holding every
     * capability that `dept_member` carries (the legitimate non-manager
     * department-scoped role — `project_member` is project-scoped and would
     * fail the FormRequest's `scope_type=department` validation gate before
     * reaching the actor guard). This is the "non-escalating" case: the
     * actor can persist `dept_member` because every capability the role
     * would auto-grant to members is already one the actor holds — nothing
     * gets escalated.
     */
    private function departmentsEditPlusDeptMemberCapsActor(): User
    {
        $actor = User::factory()->create([
            'organization_id' => $this->organization->id,
            'is_active' => true,
        ]);

        $this->assignCanonicalRole(
            $actor,
            'viewer',
            AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
            $this->organization->id,
            [
                Capability::DEPARTMENTS_VIEW,
                Capability::DEPARTMENTS_EDIT,
                Capability::PROJECTS_VIEW,
                Capability::PROJECTS_CREATE,
                Capability::PROJECTS_EDIT,
                Capability::TASKS_VIEW,
                Capability::TASKS_CREATE,
                Capability::TASKS_EDIT,
                Capability::TASKS_COMPLETE,
                Capability::RISKS_VIEW,
                Capability::RISKS_CREATE,
                Capability::OVR_VIEW,
                Capability::OVR_CREATE,
            ],
        );

        return $actor;
    }

    /**
     * Actor holding `core.assign_roles` against the organization (granted via
     * the `admin` role, which carries the platform-wide assignment
     * privilege). They are NOT super_admin.
     */
    private function coreAssignRolesActor(): User
    {
        $actor = User::factory()->create([
            'organization_id' => $this->organization->id,
            'is_active' => true,
        ]);

        $this->assignCanonicalRole(
            $actor,
            'admin',
            AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
            $this->organization->id,
        );

        return $actor;
    }

    /**
     * CSD-CA23078-HR-002 / test 1 — a bare departments.edit actor (no
     * `core.assign_roles`) MUST NOT be able to configure `dept_manager` as a
     * manager capacity role. The role carries admin-grade capabilities the
     * actor does not hold (`departments.manage_members`,
     * `departments.assign_roles`, `projects.delete`, …). Persisting it would
     * auto-escalate the department's manager to those capabilities.
     */
    public function test_departments_edit_actor_cannot_configure_dept_manager_for_any_member(): void
    {
        $actor = $this->departmentsEditOnlyActor();
        $dept = Department::factory()->create(['organization_id' => $this->organization->id]);

        $response = $this->actingAs($actor, 'sanctum')
            ->putJson("/api/hr/departments/{$dept->id}/capacity-roles", [
                'member_role_keys' => [],
                'manager_role_keys' => ['dept_manager'],
            ]);

        $response->assertForbidden();

        // Nothing must be persisted on the rejected department.
        $this->assertDatabaseMissing('department_capacity_roles', [
            'department_id' => $dept->id,
        ]);
        $this->assertDatabaseMissing('authorization_role_assignments', [
            'scope_type' => 'department',
            'scope_id' => $dept->id,
        ]);

        // The rejection itself is auditable.
        $this->assertDatabaseHas('authorization_assignment_audits', [
            'event' => 'canonical_assignment_capacity_role_write_rejected',
            'actor_id' => $actor->id,
            'scope_type' => 'department',
            'scope_id' => $dept->id,
        ]);
    }

    /**
     * CSD-CA23078-HR-002 / test 2 — the same kind of actor (departments.edit)
     * who ALSO already holds every capability that `dept_member` carries
     * CAN persist it as a member capacity role. The auto-grant that flows
     * from this configuration does not exceed anything the actor could have
     * granted directly — there is no escalation, just config ergonomics.
     */
    public function test_departments_edit_actor_can_configure_non_escalating_capacity_role(): void
    {
        $actor = $this->departmentsEditPlusDeptMemberCapsActor();
        $dept = Department::factory()->create(['organization_id' => $this->organization->id]);

        $response = $this->actingAs($actor, 'sanctum')
            ->putJson("/api/hr/departments/{$dept->id}/capacity-roles", [
                'member_role_keys' => ['dept_member'],
                'manager_role_keys' => [],
            ]);

        $response->assertOk();

        // The role is persisted as the member capacity.
        $this->assertDatabaseHas('department_capacity_roles', [
            'department_id' => $dept->id,
            'capacity' => 'member',
            'role_key' => 'dept_member',
        ]);

        // No rejection audit row should have been written on the success path.
        $this->assertDatabaseMissing('authorization_assignment_audits', [
            'event' => 'canonical_assignment_capacity_role_write_rejected',
            'actor_id' => $actor->id,
            'scope_id' => $dept->id,
        ]);
    }

    /**
     * CSD-CA23078-HR-002 / test 3 — an actor with `core.assign_roles` (the
     * platform-wide assignment privilege, granted via the `admin` role here)
     * CAN configure `dept_manager`. The actor holds the explicit assignment
     * privilege, so the subset check (admin's `Capability::all()` covers
     * everything `dept_manager` carries) admits the payload.
     */
    public function test_core_assign_roles_actor_can_configure_dept_manager(): void
    {
        $actor = $this->coreAssignRolesActor();
        $dept = Department::factory()->create(['organization_id' => $this->organization->id]);

        $response = $this->actingAs($actor, 'sanctum')
            ->putJson("/api/hr/departments/{$dept->id}/capacity-roles", [
                'member_role_keys' => [],
                'manager_role_keys' => ['dept_manager'],
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('department_capacity_roles', [
            'department_id' => $dept->id,
            'capacity' => 'manager',
            'role_key' => 'dept_manager',
        ]);

        $this->assertDatabaseMissing('authorization_assignment_audits', [
            'event' => 'canonical_assignment_capacity_role_write_rejected',
            'actor_id' => $actor->id,
            'scope_id' => $dept->id,
        ]);
    }
}
