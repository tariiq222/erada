<?php

namespace Tests\Unit\Tasks\Scopes;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Scopes\UserTaskScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * ClusterTreeUserTaskScopeTest — Phase CFA-08.
 *
 * Proves the cluster floor widening for Tasks reads:
 *   - cluster user with TASKS_VIEW + CLUSTER_TREE_VIEW sees own + descendant tasks.
 *   - cluster user with TASKS_VIEW only ⇒ strict same-org (no widening).
 *   - cluster user with CLUSTER_TREE_VIEW only ⇒ strict same-org (both caps required).
 *   - cluster user with both ⇒ sibling org tasks stay hidden.
 *   - child user ⇒ cannot see parent cluster tasks (one-directional).
 *   - null-org user ⇒ sees nothing (fail-closed).
 *   - super_admin sees everything regardless of grants.
 *   - Personal tasks (type = personal) NEVER widen cross-org.
 *   - Unrelated org outside the cluster is excluded.
 *
 * Sibling test to ClusterTreeUserProjectScopeTest (CFA-04) and
 * ClusterTreeUserStrategyScopeTest (9-D-D1b).
 */
class ClusterTreeUserTaskScopeTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    private UserTaskScope $scope;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scope = new UserTaskScope;
    }

    private function makeClusterTree(string $clusterName = 'cluster', string $hospitalName = 'hospital'): array
    {
        $cluster = Organization::factory()->cluster()->create(['name' => $clusterName]);
        $hospital = Organization::factory()->hospital()->childOf($cluster)->create(['name' => $hospitalName]);
        $sibling = Organization::factory()->create(['name' => 'sibling of '.$hospitalName]);

        return [$cluster, $hospital, $sibling];
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
    // Cluster widening on the floor
    // ============================================================

    public function test_cluster_user_with_both_grants_sees_own_and_descendant_tasks(): void
    {
        [$cluster, $hospital, $sibling] = $this->makeClusterTree();

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, [
            Capability::TASKS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $this->makeTaskInOrg($cluster->id);
        $this->makeTaskInOrg($cluster->id);
        $this->makeTaskInOrg($hospital->id);
        $this->makeTaskInOrg($hospital->id);
        $this->makeTaskInOrg($hospital->id);
        $this->makeTaskInOrg($sibling->id);
        $this->makeTaskInOrg($sibling->id);

        $query = Task::query();
        $this->scope->applyClusterListFilter($query, $user);

        // 2 (cluster) + 3 (hospital) = 5 visible. Sibling excluded.
        $this->assertSame(5, (clone $query)->count());
    }

    public function test_cluster_user_with_only_tasks_view_does_not_see_descendant_tasks(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, Capability::TASKS_VIEW);

        $this->makeTaskInOrg($cluster->id);
        $this->makeTaskInOrg($cluster->id);
        $this->makeTaskInOrg($hospital->id);
        $this->makeTaskInOrg($hospital->id);

        $query = Task::query();
        $this->scope->applyClusterListFilter($query, $user);

        // TASKS_VIEW alone: scope returns [actor.org] only. The hospital
        // tasks are not in the actor.org strict floor. CFA-08 floor widens
        // only when CLUSTER_TREE_VIEW is ALSO held.
        $hospitalCount = (clone $query)->whereHas('project', fn ($q) => $q->where('organization_id', $hospital->id))->count();
        $this->assertSame(0, $hospitalCount, 'no descendant hospital tasks visible without CLUSTER_TREE_VIEW');
    }

    public function test_cluster_user_with_only_cluster_tree_view_does_not_see_descendant_tasks(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, Capability::CLUSTER_TREE_VIEW);

        $this->makeTaskInOrg($hospital->id);
        $this->makeTaskInOrg($hospital->id);

        $query = Task::query();
        $this->scope->applyClusterListFilter($query, $user);

        // CLUSTER_TREE_VIEW alone does NOT widen (TASKS_VIEW missing). The
        // CFA-08 contract requires BOTH grants — missing either ⇒ no
        // descendant widening.
        $hospitalCount = (clone $query)->whereHas('project', fn ($q) => $q->where('organization_id', $hospital->id))->count();
        $this->assertSame(0, $hospitalCount, 'no descendant hospital tasks visible with only CLUSTER_TREE_VIEW');
    }

    public function test_sibling_cluster_isolated_for_tasks(): void
    {
        [$clusterA, $hospitalA] = $this->makeClusterTree('cluster A', 'hospital A');
        [$clusterB, $hospitalB] = $this->makeClusterTree('cluster B', 'hospital B');

        $user = User::factory()->create(['organization_id' => $clusterB->id, 'is_active' => true]);
        $this->grantEngineCapability($user, [
            Capability::TASKS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $this->makeTaskInOrg($clusterA->id);
        $this->makeTaskInOrg($clusterA->id);
        $this->makeTaskInOrg($hospitalA->id);
        $this->makeTaskInOrg($hospitalA->id);
        $this->makeTaskInOrg($clusterB->id);
        $this->makeTaskInOrg($hospitalB->id);

        $query = Task::query();
        $this->scope->applyClusterListFilter($query, $user);

        // Only clusterB (1) + hospitalB (1) = 2. ClusterA subtree excluded.
        $this->assertSame(2, (clone $query)->count());
    }

    public function test_child_user_cannot_see_parent_cluster_tasks_via_cluster_tree(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $childUser = User::factory()->create(['organization_id' => $hospital->id, 'is_active' => true]);
        $this->grantEngineCapability($childUser, [
            Capability::TASKS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $this->makeTaskInOrg($cluster->id);
        $this->makeTaskInOrg($cluster->id);
        $this->makeTaskInOrg($hospital->id);
        $this->makeTaskInOrg($hospital->id);

        $query = Task::query();
        $this->scope->applyClusterListFilter($query, $childUser);

        // Strict same-org (hospital): only 2.
        $this->assertSame(2, (clone $query)->count());
    }

    public function test_super_admin_sees_all_tasks_regardless_of_grants(): void
    {
        [$cluster, $hospital, $sibling] = $this->makeClusterTree();

        $superAdmin = User::factory()->create(['organization_id' => null, 'is_active' => true]);
        $this->grantCanonicalSuperAdmin($superAdmin);

        $this->makeTaskInOrg($cluster->id);
        $this->makeTaskInOrg($cluster->id);
        $this->makeTaskInOrg($hospital->id);
        $this->makeTaskInOrg($hospital->id);
        $this->makeTaskInOrg($sibling->id);

        $query = Task::query();
        $this->scope->applyClusterListFilter($query, $superAdmin);

        $this->assertSame(5, (clone $query)->count());
    }

    public function test_null_org_user_sees_no_tasks(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $nullOrgUser = User::factory()->create(['organization_id' => null, 'is_active' => true]);
        $this->grantEngineCapability($nullOrgUser, [
            Capability::TASKS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $this->makeTaskInOrg($cluster->id);
        $this->makeTaskInOrg($hospital->id);

        $query = Task::query();
        $this->scope->applyClusterListFilter($query, $nullOrgUser);

        // Fail-closed: null-org actor sees 0 rows.
        $this->assertSame(0, (clone $query)->count());
    }

    public function test_unrelated_org_outside_the_cluster_is_excluded(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $unrelated = Organization::factory()->create(['name' => 'unrelated']);

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, [
            Capability::TASKS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $this->makeTaskInOrg($cluster->id);
        $this->makeTaskInOrg($cluster->id);
        $this->makeTaskInOrg($hospital->id);
        $this->makeTaskInOrg($hospital->id);
        $this->makeTaskInOrg($unrelated->id);
        $this->makeTaskInOrg($unrelated->id);

        $query = Task::query();
        $this->scope->applyClusterListFilter($query, $user);

        // 2 (cluster) + 2 (hospital) = 4. Unrelated excluded.
        $this->assertSame(4, (clone $query)->count());
    }

    // ============================================================
    // Personal tasks — NEVER widen (CFA-00 owner decision)
    // ============================================================

    public function test_personal_task_does_not_widen_to_other_user_via_cluster(): void
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

        // Hospital-owned cluster user with both grants — must NOT see the
        // personal task via cluster widening. Personal task floor is owner_id.
        $clusterUser = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($clusterUser, [
            Capability::TASKS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $query = Task::query();
        $this->scope->applyClusterListFilter($query, $clusterUser);

        $this->assertSame(0, (clone $query)->where('id', $personalTask->id)->count());
    }

    // ============================================================
    // clusterVisibleOrgIds() — direct helper contract
    // ============================================================

    public function test_cluster_visible_org_ids_returns_strict_same_org_when_missing_grants(): void
    {
        $cluster = Organization::factory()->cluster()->create();
        $hospital = Organization::factory()->hospital()->childOf($cluster)->create();

        $userNoGrants = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);

        $ids = $this->scope->clusterVisibleOrgIds($userNoGrants);

        // No TASKS_VIEW, no CLUSTER_TREE_VIEW — strict same-org.
        $this->assertSame([(int) $cluster->id], $ids);
    }

    public function test_cluster_visible_org_ids_returns_strict_same_org_when_only_one_grant(): void
    {
        $cluster = Organization::factory()->cluster()->create();
        $hospital = Organization::factory()->hospital()->childOf($cluster)->create();

        $userOnlyTasksView = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($userOnlyTasksView, Capability::TASKS_VIEW);

        $this->assertSame([(int) $cluster->id], $this->scope->clusterVisibleOrgIds($userOnlyTasksView));

        $userOnlyCluster = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($userOnlyCluster, Capability::CLUSTER_TREE_VIEW);

        $this->assertSame([(int) $cluster->id], $this->scope->clusterVisibleOrgIds($userOnlyCluster));
    }

    public function test_cluster_visible_org_ids_returns_descendants_when_both_grants_held(): void
    {
        $cluster = Organization::factory()->cluster()->create();
        $hospital = Organization::factory()->hospital()->childOf($cluster)->create();

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, [
            Capability::TASKS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $ids = $this->scope->clusterVisibleOrgIds($user);

        // cluster + hospital (descendant).
        $this->assertContains((int) $cluster->id, $ids);
        $this->assertContains((int) $hospital->id, $ids);
    }

    public function test_cluster_visible_org_ids_returns_empty_for_null_org_actor(): void
    {
        $user = User::factory()->create(['organization_id' => null, 'is_active' => true]);
        $this->grantEngineCapability($user, [
            Capability::TASKS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $this->assertSame([], $this->scope->clusterVisibleOrgIds($user));
    }
}
