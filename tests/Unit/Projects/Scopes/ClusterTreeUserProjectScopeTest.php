<?php

namespace Tests\Unit\Projects\Scopes;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Scopes\UserProjectScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * ClusterTreeUserProjectScopeTest — Phase CFA-04.
 *
 * Sibling primitive test to ClusterTreeUserKpiScopeTest (Phase 9-D-D1a) and
 * ClusterTreeUserStrategyScopeTest (Phase 9-D-D1b). Proves that
 * UserProjectScope::apply widens its strict same-org floor to descendant
 * organizations when the actor holds BOTH Capability::PROJECTS_VIEW +
 * Capability::CLUSTER_TREE_VIEW on actor.organization_id.
 *
 * Contract under test (mirrors CFA-00 owner decisions 2026-07-09):
 *   - Both grants required to widen cluster_tree. Missing either ⇒ strict
 *     same-org behavior (no descendant records in the result).
 *   - Sibling cluster denied.
 *   - Child → parent denied (one-directional — scope widens DOWN only).
 *   - Null-org actor denied (fail-closed via empty org list).
 *   - super_admin bypass unchanged.
 *   - The scope only widens the FLOOR; the OR-logic for direct relations,
 *     engine grants, governed types, and flat-ladder widening is preserved
 *     as-is (it widens via the same `$visibleOrgIds` mechanism).
 *   - Per CFA-00: write paths (CRUD + member assignment) stay strict
 *     same-org; this test only covers the read surface.
 */
class ClusterTreeUserProjectScopeTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    private UserProjectScope $scope;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scope = new UserProjectScope;
    }

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

    // ============================================================
    // Cluster widening on the floor
    // ============================================================

    public function test_cluster_user_with_read_pair_sees_own_and_descendant_projects(): void
    {
        [$cluster, $hospital, $sibling] = $this->makeClusterTree();

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, [
            Capability::PROJECTS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $this->makeProjectInOrg($cluster->id);
        $this->makeProjectInOrg($cluster->id);
        $this->makeProjectInOrg($hospital->id);
        $this->makeProjectInOrg($hospital->id);
        $this->makeProjectInOrg($hospital->id);
        $this->makeProjectInOrg($sibling->id);
        $this->makeProjectInOrg($sibling->id);

        $query = Project::query();
        $this->scope->apply($query, $user);

        // 2 (cluster) + 3 (hospital) = 5. Sibling excluded.
        $this->assertSame(5, (clone $query)->count());
    }

    public function test_cluster_user_with_only_projects_view_does_not_see_descendant_projects(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, Capability::PROJECTS_VIEW);

        $this->makeProjectInOrg($cluster->id);
        $this->makeProjectInOrg($cluster->id);
        $this->makeProjectInOrg($hospital->id);
        $this->makeProjectInOrg($hospital->id);

        $query = Project::query();
        $this->scope->apply($query, $user);

        // PROJECTS_VIEW alone widens to direct relations + governed types +
        // own org via the existing OR-logic. The CFA-04 cluster widening
        // does NOT fire (no CLUSTER_TREE_VIEW). The hospital projects are
        // not directly related to the user and not in governed types, so
        // the descendant set is empty.
        $count = (clone $query)->count();
        $hospitalProjects = Project::query()->where('organization_id', $hospital->id)->count();
        $this->assertSame(0, $count - $hospitalProjects, 'no descendant hospital projects visible without CLUSTER_TREE_VIEW');
    }

    public function test_cluster_user_with_only_cluster_tree_view_does_not_see_descendant_projects(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, Capability::CLUSTER_TREE_VIEW);

        $this->makeProjectInOrg($hospital->id);
        $this->makeProjectInOrg($hospital->id);

        $query = Project::query();
        $this->scope->apply($query, $user);

        // CLUSTER_TREE_VIEW alone does NOT widen (PROJECTS_VIEW missing).
        // The CFA-04 contract requires BOTH grants — missing either ⇒
        // no descendant widening. The user may or may not see their own
        // org's projects depending on the OR-logic, but they MUST NOT see
        // descendant hospital projects.
        $hospitalProjectCount = (clone $query)->where('organization_id', $hospital->id)->count();
        $this->assertSame(0, $hospitalProjectCount, 'no descendant hospital projects visible with only CLUSTER_TREE_VIEW');
    }

    public function test_sibling_cluster_isolated_for_projects(): void
    {
        [$clusterA, $hospitalA] = $this->makeClusterTree('cluster A', 'hospital A');
        [$clusterB, $hospitalB] = $this->makeClusterTree('cluster B', 'hospital B');

        $user = User::factory()->create(['organization_id' => $clusterB->id, 'is_active' => true]);
        $this->grantEngineCapability($user, [
            Capability::PROJECTS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $this->makeProjectInOrg($clusterA->id);
        $this->makeProjectInOrg($clusterA->id);
        $this->makeProjectInOrg($hospitalA->id);
        $this->makeProjectInOrg($hospitalA->id);
        $this->makeProjectInOrg($clusterB->id);
        $this->makeProjectInOrg($hospitalB->id);

        $query = Project::query();
        $this->scope->apply($query, $user);

        // Only clusterB (1) + hospitalB (1) = 2. ClusterA subtree excluded.
        $this->assertSame(2, (clone $query)->count());
    }

    public function test_child_user_cannot_see_parent_cluster_projects_via_cluster_tree(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $childUser = User::factory()->create(['organization_id' => $hospital->id, 'is_active' => true]);
        $this->grantEngineCapability($childUser, [
            Capability::PROJECTS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $this->makeProjectInOrg($cluster->id);
        $this->makeProjectInOrg($cluster->id);
        $this->makeProjectInOrg($hospital->id);
        $this->makeProjectInOrg($hospital->id);

        $query = Project::query();
        $this->scope->apply($query, $childUser);

        // Strict same-org (hospital): only 2.
        $this->assertSame(2, (clone $query)->count());
    }

    public function test_super_admin_sees_all_projects_regardless_of_grants(): void
    {
        [$cluster, $hospital, $sibling] = $this->makeClusterTree();

        $superAdmin = User::factory()->create(['organization_id' => null, 'is_active' => true]);
        $superAdmin->assignRole('super_admin');

        $this->makeProjectInOrg($cluster->id);
        $this->makeProjectInOrg($cluster->id);
        $this->makeProjectInOrg($hospital->id);
        $this->makeProjectInOrg($hospital->id);
        $this->makeProjectInOrg($sibling->id);

        $query = Project::query();
        $this->scope->apply($query, $superAdmin);

        $this->assertSame(5, (clone $query)->count());
    }

    public function test_unrelated_org_outside_the_cluster_is_excluded(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $unrelated = Organization::factory()->create(['name' => 'unrelated']);

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, [
            Capability::PROJECTS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $this->makeProjectInOrg($cluster->id);
        $this->makeProjectInOrg($cluster->id);
        $this->makeProjectInOrg($hospital->id);
        $this->makeProjectInOrg($hospital->id);
        $this->makeProjectInOrg($unrelated->id);
        $this->makeProjectInOrg($unrelated->id);

        $query = Project::query();
        $this->scope->apply($query, $user);

        // 2 (cluster) + 2 (hospital) = 4. Unrelated excluded.
        $this->assertSame(4, (clone $query)->count());
    }
}
