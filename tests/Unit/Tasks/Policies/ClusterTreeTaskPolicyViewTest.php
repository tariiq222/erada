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
 * ClusterTreeTaskPolicyViewTest — Phase CFA-08.
 *
 * Cluster tree widening for the Tasks module (read path):
 *   - view() widens via TASKS_VIEW + CLUSTER_TREE_VIEW on actor.org
 *   - Personal task (owner_id floor) NEVER widens (CFA-00 owner decision)
 *   - Sensitive tasks (sensitivity / OVR-confidential source) NEVER widen
 *     via cluster rescue (SensitivelyScoped contract + engine pre-flight)
 *   - Missing either grant ⇒ strict same-org
 *
 * Sibling test to ClusterTreeProjectsPolicyTest (CFA-04) and
 * ClusterTreeStrategyPolicyTest (9-D-D1b).
 */
class ClusterTreeTaskPolicyViewTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    private function makeClusterTree(string $clusterName = 'cluster', string $hospitalName = 'hospital'): array
    {
        $cluster = Organization::factory()->cluster()->create(['name' => $clusterName]);
        $hospital = Organization::factory()->hospital()->childOf($cluster)->create(['name' => $hospitalName]);
        $sibling = Organization::factory()->create(['name' => 'sibling of '.$hospitalName]);

        return [$cluster, $hospital, $sibling];
    }

    private function makeProjectInOrg(int $orgId): Project
    {
        return Project::factory()->create([
            'organization_id' => $orgId,
            'department_id' => Department::factory()->create(['organization_id' => $orgId])->id,
        ]);
    }

    private function makeTaskInOrg(int $orgId, string $type = 'project'): Task
    {
        $project = $this->makeProjectInOrg($orgId);

        return Task::factory()->create([
            'project_id' => $project->id,
            'type' => $type,
            'source_type' => null,
            'source_id' => null,
            'source_sensitivity' => null,
        ]);
    }

    // ============================================================
    // view() — cluster widening via CLUSTER_TREE_VIEW
    // ============================================================

    public function test_cluster_user_with_read_pair_can_view_child_org_task(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, [
            Capability::TASKS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $childTask = $this->makeTaskInOrg($hospital->id);

        $this->assertTrue((new TaskPolicy)->view($user, $childTask));
    }

    public function test_cluster_user_without_cluster_tree_view_cannot_view_child_org_task(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, Capability::TASKS_VIEW);

        $childTask = $this->makeTaskInOrg($hospital->id);

        $this->assertFalse((new TaskPolicy)->view($user, $childTask));
    }

    public function test_cluster_user_without_tasks_view_cannot_view_child_org_task(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, Capability::CLUSTER_TREE_VIEW);

        $childTask = $this->makeTaskInOrg($hospital->id);

        $this->assertFalse((new TaskPolicy)->view($user, $childTask));
    }

    public function test_missing_either_grant_blocks_view(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();
        $childTask = $this->makeTaskInOrg($hospital->id);

        $userNoCluster = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($userNoCluster, Capability::TASKS_VIEW);
        $this->assertFalse((new TaskPolicy)->view($userNoCluster, $childTask));

        $userNoModule = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($userNoModule, Capability::CLUSTER_TREE_VIEW);
        $this->assertFalse((new TaskPolicy)->view($userNoModule, $childTask));
    }

    public function test_sibling_cluster_isolated_for_view(): void
    {
        [$clusterA, $hospitalA] = $this->makeClusterTree('cluster A', 'hospital A');
        [$clusterB] = $this->makeClusterTree('cluster B', 'hospital B');

        $user = User::factory()->create(['organization_id' => $clusterA->id, 'is_active' => true]);
        $this->grantEngineCapability($user, [
            Capability::TASKS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $childTask = $this->makeTaskInOrg($hospitalA->id);

        // Switch actor to cluster B (sibling, not ancestor of cluster A).
        $user->update(['organization_id' => $clusterB->id]);
        $this->assertFalse((new TaskPolicy)->view($user, $childTask));
    }

    public function test_child_user_cannot_view_parent_cluster_task_via_cluster_tree(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $childUser = User::factory()->create(['organization_id' => $hospital->id, 'is_active' => true]);
        $this->grantEngineCapability($childUser, [
            Capability::TASKS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $parentTask = $this->makeTaskInOrg($cluster->id);

        $this->assertFalse((new TaskPolicy)->view($childUser, $parentTask));
    }

    public function test_null_org_user_with_grants_cannot_view_child_org_task(): void
    {
        [, $hospital] = $this->makeClusterTree();

        $nullOrgUser = User::factory()->create(['organization_id' => null, 'is_active' => true]);
        $this->grantEngineCapability($nullOrgUser, [
            Capability::TASKS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $childTask = $this->makeTaskInOrg($hospital->id);

        $this->assertFalse((new TaskPolicy)->view($nullOrgUser, $childTask));
    }

    public function test_super_admin_can_view_child_org_task(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $superAdmin = User::factory()->create(['organization_id' => null, 'is_active' => true]);
        $this->grantCanonicalSuperAdmin($superAdmin);

        $childTask = $this->makeTaskInOrg($hospital->id);

        $this->assertTrue((new TaskPolicy)->view($superAdmin, $childTask));
    }

    public function test_view_does_not_fire_for_same_org_target(): void
    {
        $org = Organization::factory()->create();
        $task = $this->makeTaskInOrg($org->id);

        $user = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $this->grantEngineCapability($user, [
            Capability::TASKS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        // Same-org path: AccessDecision::can(TASKS_VIEW, task) returns true.
        $this->assertTrue((new TaskPolicy)->view($user, $task));
    }

    // ============================================================
    // Personal task floor — NEVER widens (CFA-00 owner decision)
    // ============================================================

    public function test_personal_task_does_not_widen_to_other_user_via_cluster_tree(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $owner = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);

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

        // A cluster user — even with both grants — must NOT see another
        // user's personal task. The personal-task owner floor never widens.
        $clusterUser = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($clusterUser, [
            Capability::TASKS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $this->assertFalse((new TaskPolicy)->view($clusterUser, $personalTask));
    }

    public function test_personal_task_owner_can_view_own_personal_task(): void
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

        $this->assertTrue((new TaskPolicy)->view($owner, $personalTask));
    }
}
