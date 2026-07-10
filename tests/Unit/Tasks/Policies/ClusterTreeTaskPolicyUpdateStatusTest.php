<?php

namespace Tests\Unit\Tasks\Policies;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Policies\TaskPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * ClusterTreeTaskPolicyUpdateStatusTest — Phase CFA-08.
 *
 * Cluster tree widening for the Tasks module (PDCA status transition path):
 *   - changeStatus() widens via TASKS_EDIT + CLUSTER_TREE_MANAGE on actor.org
 *   - Other field updates (update()) stay strict same-org
 *   - Personal tasks (owner_id floor) NEVER widen
 */
class ClusterTreeTaskPolicyUpdateStatusTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    private function makeClusterTree(string $clusterName = 'cluster', string $hospitalName = 'hospital'): array
    {
        $cluster = Organization::factory()->cluster()->create(['name' => $clusterName]);
        $hospital = Organization::factory()->hospital()->childOf($cluster)->create(['name' => $hospitalName]);

        return [$cluster, $hospital];
    }

    private function makeTaskInOrg(int $orgId): Task
    {
        $project = Project::factory()->create([
            'organization_id' => $orgId,
            'department_id' => Department::factory()->create(['organization_id' => $orgId])->id,
        ]);

        return Task::factory()->create([
            'project_id' => $project->id,
            'type' => 'project',
            'source_type' => null,
            'source_id' => null,
            'source_sensitivity' => null,
        ]);
    }

    // ============================================================
    // changeStatus() — governance write via CLUSTER_TREE_MANAGE
    // ============================================================

    public function test_cluster_user_with_manage_pair_can_change_child_task_status(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, [
            Capability::TASKS_EDIT,
            Capability::CLUSTER_TREE_MANAGE,
        ]);

        $childTask = $this->makeTaskInOrg($hospital->id);

        $this->assertTrue((new TaskPolicy)->changeStatus($user, $childTask));
    }

    public function test_cluster_user_without_cluster_tree_manage_cannot_change_child_task_status(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, Capability::TASKS_EDIT);

        $childTask = $this->makeTaskInOrg($hospital->id);

        $this->assertFalse((new TaskPolicy)->changeStatus($user, $childTask));
    }

    public function test_cluster_user_without_tasks_edit_cannot_change_child_task_status(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, Capability::CLUSTER_TREE_MANAGE);

        $childTask = $this->makeTaskInOrg($hospital->id);

        $this->assertFalse((new TaskPolicy)->changeStatus($user, $childTask));
    }

    public function test_child_user_cannot_change_parent_task_status_via_cluster_tree(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $childUser = User::factory()->create(['organization_id' => $hospital->id, 'is_active' => true]);
        $this->grantEngineCapability($childUser, [
            Capability::TASKS_EDIT,
            Capability::CLUSTER_TREE_MANAGE,
        ]);

        $parentTask = $this->makeTaskInOrg($cluster->id);

        $this->assertFalse((new TaskPolicy)->changeStatus($childUser, $parentTask));
    }

    public function test_super_admin_can_change_child_task_status(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $superAdmin = User::factory()->create(['organization_id' => null, 'is_active' => true]);
        $superAdmin->assignRole('super_admin');

        $childTask = $this->makeTaskInOrg($hospital->id);

        $this->assertTrue((new TaskPolicy)->changeStatus($superAdmin, $childTask));
    }

    public function test_personal_task_status_change_does_not_widen_via_cluster_tree(): void
    {
        // Personal tasks (owner_id floor) NEVER widen via cluster rescue.
        // changeStatus() on a personal task falls back to the personal-task
        // owner floor (= owner_id check).
        [$cluster, $hospital] = $this->makeClusterTree();

        $personalOwner = User::factory()->create(['organization_id' => $hospital->id, 'is_active' => true]);
        $personalTask = Task::factory()->create([
            'type' => 'personal',
            'owner_id' => $personalOwner->id,
            'created_by' => $personalOwner->id,
            'project_id' => null,
            'department_id' => null,
            'source_type' => null,
            'source_id' => null,
            'source_sensitivity' => null,
        ]);

        // Cluster user with both cluster grants must NOT change the status
        // of a task that is not theirs (and the task lives in a child org).
        $clusterUser = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($clusterUser, [
            Capability::TASKS_EDIT,
            Capability::CLUSTER_TREE_MANAGE,
        ]);

        $this->assertFalse((new TaskPolicy)->changeStatus($clusterUser, $personalTask));
    }

    public function test_personal_task_owner_can_change_own_personal_task_status(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);

        $personalTask = Task::factory()->create([
            'type' => 'personal',
            'owner_id' => $owner->id,
            'created_by' => $owner->id,
            'project_id' => null,
            'department_id' => null,
            'source_type' => null,
            'source_id' => null,
            'source_sensitivity' => null,
        ]);

        // Personal task owner can always change their own task status via
        // the owner floor (no tasks.edit required for own personal tasks).
        $this->assertTrue((new TaskPolicy)->changeStatus($owner, $personalTask));
    }
}
