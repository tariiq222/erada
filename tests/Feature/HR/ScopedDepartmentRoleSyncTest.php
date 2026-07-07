<?php

namespace Tests\Feature\HR;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\DepartmentCapacityRole;
use App\Modules\HR\Services\ScopedDepartmentRoleSyncService;
use App\Modules\Projects\Models\Project;
use Database\Seeders\ScopedDepartmentRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Behaviour suite for the capacity-aware scoped department role automation:
 * source-aware auto helpers, the sync service, the observers, the end-to-end
 * vertical visibility claim, and the reconcile convergence path.
 */
class ScopedDepartmentRoleSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_auto_roles_adds_missing_and_preserves_manual(): void
    {
        $this->seed(ScopedDepartmentRolesSeeder::class);
        $user = User::factory()->create();

        // a manual delegation on the same scope must survive
        $user->scopedRoles()->create([
            'role' => 'dept_manager', 'scope_type' => 'department', 'scope_id' => 7,
            'inherit_to_children' => true, 'source' => 'manual',
        ]);

        $user->syncAutoScopedRolesForScope('department', 7, ['dept_member']);

        $this->assertDatabaseHas('model_has_scoped_roles', [
            'user_id' => $user->id, 'role' => 'dept_member',
            'scope_type' => 'department', 'scope_id' => 7, 'source' => 'auto',
        ]);
        $this->assertDatabaseHas('model_has_scoped_roles', [
            'user_id' => $user->id, 'role' => 'dept_manager',
            'scope_type' => 'department', 'scope_id' => 7, 'source' => 'manual',
        ]);
    }

    public function test_auto_sync_never_clobbers_a_manual_row_for_same_role_and_scope(): void
    {
        $this->seed(ScopedDepartmentRolesSeeder::class);
        $user = User::factory()->create();

        // manual grant of dept_member on scope 9
        $user->scopedRoles()->create([
            'role' => 'dept_member', 'scope_type' => 'department', 'scope_id' => 9,
            'inherit_to_children' => true, 'source' => 'manual',
        ]);

        // auto sync expects the same role on the same scope
        $user->syncAutoScopedRolesForScope('department', 9, ['dept_member']);

        // exactly one row, still manual (protected) -- no duplicate, no flip to auto
        $rows = $user->scopedRoles()
            ->where('role', 'dept_member')->where('scope_type', 'department')->where('scope_id', 9)->get();
        $this->assertCount(1, $rows);
        $this->assertSame('manual', $rows->first()->source);
    }

    public function test_member_gets_member_role_on_their_department(): void
    {
        $this->seed(ScopedDepartmentRolesSeeder::class);
        $dept = Department::factory()->create();
        DepartmentCapacityRole::create(['department_id' => $dept->id, 'capacity' => 'member', 'role_key' => 'dept_member']);

        $user = User::factory()->create(['department_id' => $dept->id]);
        app(ScopedDepartmentRoleSyncService::class)->syncUser($user);

        $this->assertDatabaseHas('model_has_scoped_roles', [
            'user_id' => $user->id, 'role' => 'dept_member',
            'scope_type' => 'department', 'scope_id' => $dept->id, 'source' => 'auto',
        ]);
    }

    public function test_manager_gets_manager_role_and_loses_it_when_replaced(): void
    {
        $this->seed(ScopedDepartmentRolesSeeder::class);
        $dept = Department::factory()->create();
        DepartmentCapacityRole::create(['department_id' => $dept->id, 'capacity' => 'manager', 'role_key' => 'dept_manager']);

        $oldManager = User::factory()->create();
        $dept->update(['manager_id' => $oldManager->id]);
        app(ScopedDepartmentRoleSyncService::class)->syncDepartment($dept->fresh());

        $this->assertDatabaseHas('model_has_scoped_roles', [
            'user_id' => $oldManager->id, 'role' => 'dept_manager',
            'scope_type' => 'department', 'scope_id' => $dept->id, 'source' => 'auto',
        ]);

        $newManager = User::factory()->create();
        $dept->update(['manager_id' => $newManager->id]);
        app(ScopedDepartmentRoleSyncService::class)->syncUser($oldManager->fresh());
        app(ScopedDepartmentRoleSyncService::class)->syncUser($newManager->fresh());

        $this->assertDatabaseMissing('model_has_scoped_roles', [
            'user_id' => $oldManager->id, 'scope_id' => $dept->id, 'role' => 'dept_manager',
        ]);
        $this->assertDatabaseHas('model_has_scoped_roles', [
            'user_id' => $newManager->id, 'role' => 'dept_manager',
            'scope_type' => 'department', 'scope_id' => $dept->id, 'source' => 'auto',
        ]);
    }

    public function test_moving_member_between_departments_cleans_up_old_membership_role(): void
    {
        $this->seed(ScopedDepartmentRolesSeeder::class);
        $deptA = Department::factory()->create();
        $deptB = Department::factory()->create();
        DepartmentCapacityRole::create(['department_id' => $deptA->id, 'capacity' => 'member', 'role_key' => 'dept_member']);
        DepartmentCapacityRole::create(['department_id' => $deptB->id, 'capacity' => 'member', 'role_key' => 'dept_member']);

        $user = User::factory()->create(['department_id' => $deptA->id]);
        app(ScopedDepartmentRoleSyncService::class)->syncUser($user);
        $this->assertDatabaseHas('model_has_scoped_roles', [
            'user_id' => $user->id, 'scope_id' => $deptA->id, 'role' => 'dept_member', 'source' => 'auto',
        ]);

        // move to B and resync
        $user->department_id = $deptB->id;
        $user->save();
        app(ScopedDepartmentRoleSyncService::class)->syncUser($user->fresh());

        $this->assertDatabaseMissing('model_has_scoped_roles', [
            'user_id' => $user->id, 'scope_id' => $deptA->id, 'role' => 'dept_member',
        ]);
        $this->assertDatabaseHas('model_has_scoped_roles', [
            'user_id' => $user->id, 'scope_id' => $deptB->id, 'role' => 'dept_member', 'source' => 'auto',
        ]);
    }

    public function test_member_and_manager_of_same_department_gets_both_role_sets(): void
    {
        $this->seed(ScopedDepartmentRolesSeeder::class);
        $dept = Department::factory()->create();
        DepartmentCapacityRole::create(['department_id' => $dept->id, 'capacity' => 'member', 'role_key' => 'dept_member']);
        DepartmentCapacityRole::create(['department_id' => $dept->id, 'capacity' => 'manager', 'role_key' => 'dept_manager']);

        // user is BOTH a member (department_id) AND the manager (manager_id) of the same dept
        $user = User::factory()->create(['department_id' => $dept->id]);
        $dept->update(['manager_id' => $user->id]);
        app(ScopedDepartmentRoleSyncService::class)->syncUser($user->fresh());

        $this->assertDatabaseHas('model_has_scoped_roles', [
            'user_id' => $user->id, 'scope_id' => $dept->id, 'role' => 'dept_member', 'source' => 'auto',
        ]);
        $this->assertDatabaseHas('model_has_scoped_roles', [
            'user_id' => $user->id, 'scope_id' => $dept->id, 'role' => 'dept_manager', 'source' => 'auto',
        ]);
    }

    public function test_changing_department_manager_moves_manager_role(): void
    {
        $this->seed(ScopedDepartmentRolesSeeder::class);
        $dept = Department::factory()->create();
        DepartmentCapacityRole::create(['department_id' => $dept->id, 'capacity' => 'manager', 'role_key' => 'dept_manager']);

        $a = User::factory()->create();
        $b = User::factory()->create();

        $dept->update(['manager_id' => $a->id]); // observer fires
        $this->assertDatabaseHas('model_has_scoped_roles', [
            'user_id' => $a->id, 'role' => 'dept_manager', 'scope_id' => $dept->id, 'source' => 'auto',
        ]);

        $dept->update(['manager_id' => $b->id]); // observer fires
        $this->assertDatabaseMissing('model_has_scoped_roles', [
            'user_id' => $a->id, 'role' => 'dept_manager', 'scope_id' => $dept->id,
        ]);
        $this->assertDatabaseHas('model_has_scoped_roles', [
            'user_id' => $b->id, 'role' => 'dept_manager', 'scope_id' => $dept->id, 'source' => 'auto',
        ]);
    }

    public function test_editing_department_policy_resyncs_current_members(): void
    {
        $this->seed(ScopedDepartmentRolesSeeder::class);
        $dept = Department::factory()->create();
        $user = User::factory()->create(['department_id' => $dept->id]); // observer runs, but no policy yet

        $this->assertDatabaseMissing('model_has_scoped_roles', [
            'user_id' => $user->id, 'scope_id' => $dept->id, 'role' => 'dept_member',
        ]);

        // creating the policy fires DepartmentCapacityRoleObserver -> syncDepartment
        DepartmentCapacityRole::create(['department_id' => $dept->id, 'capacity' => 'member', 'role_key' => 'dept_member']);

        $this->assertDatabaseHas('model_has_scoped_roles', [
            'user_id' => $user->id, 'scope_id' => $dept->id, 'role' => 'dept_member', 'source' => 'auto',
        ]);
    }

    public function test_deleting_department_removes_orphan_scoped_role_rows(): void
    {
        $this->seed(ScopedDepartmentRolesSeeder::class);
        $dept = Department::factory()->create();
        DepartmentCapacityRole::create(['department_id' => $dept->id, 'capacity' => 'member', 'role_key' => 'dept_member']);

        // A MANUAL grant on this department's scope -- nothing but the deleted handler
        // cleans this up (detaching the user only clears auto rows via membership sync).
        $deputy = User::factory()->create();
        $deputy->scopedRoles()->create([
            'role' => 'dept_manager', 'scope_type' => 'department', 'scope_id' => $dept->id,
            'inherit_to_children' => true, 'source' => 'manual',
        ]);

        $this->assertDatabaseHas('model_has_scoped_roles', [
            'scope_type' => 'department', 'scope_id' => $dept->id, 'source' => 'manual',
        ]);

        $dept->delete(); // DepartmentObserver::deleted must drop the orphaned manual row

        $this->assertDatabaseMissing('model_has_scoped_roles', ['scope_type' => 'department', 'scope_id' => $dept->id]);
    }

    public function test_parent_department_manager_can_manage_child_department_project(): void
    {
        $this->seed(ScopedDepartmentRolesSeeder::class);

        $org = Organization::factory()->create();
        $sector = Department::factory()->create(['organization_id' => $org->id, 'parent_id' => null]);
        $childDept = Department::factory()->create(['organization_id' => $org->id, 'parent_id' => $sector->id]);

        DepartmentCapacityRole::create(['department_id' => $sector->id, 'capacity' => 'manager', 'role_key' => 'dept_manager']);

        $opsManager = User::factory()->create(['organization_id' => $org->id]);
        $sector->update(['manager_id' => $opsManager->id]); // observer assigns dept_manager on sector scope

        $project = Project::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $childDept->id,
        ]);

        // Vertical visibility + management: project chain ascends child -> sector, where the role sits.
        $this->assertTrue(AccessDecision::can($opsManager->fresh(), Capability::PROJECTS_VIEW, $project));
        $this->assertTrue(AccessDecision::can($opsManager->fresh(), Capability::PROJECTS_EDIT, $project));

        // Negative isolation INSIDE the org (review point 5c): a sibling-branch manager
        // must NOT see the child-department project. The org invariant only covers
        // cross-org; this proves intra-org branch isolation.
        $siblingDept = Department::factory()->create(['organization_id' => $org->id, 'parent_id' => $sector->id]);
        DepartmentCapacityRole::create(['department_id' => $siblingDept->id, 'capacity' => 'manager', 'role_key' => 'dept_manager']);
        $siblingManager = User::factory()->create(['organization_id' => $org->id]);
        $siblingDept->update(['manager_id' => $siblingManager->id]);

        $this->assertFalse(AccessDecision::can($siblingManager->fresh(), Capability::PROJECTS_VIEW, $project));
        $this->assertFalse(AccessDecision::can($siblingManager->fresh(), Capability::PROJECTS_EDIT, $project));
    }
}
