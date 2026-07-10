<?php

namespace Tests\Unit\RiskManagement\Scopes;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\RiskManagement\Models\Risk;
use App\Modules\RiskManagement\Scopes\UserRiskScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * ClusterTreeUserRiskScopeTest — Phase CFA-05.
 *
 * Sibling primitive test to ClusterTreeUserProjectScopeTest (CFA-04).
 * Proves that UserRiskScope::apply widens its strict same-org floor to
 * descendant organizations when the actor holds BOTH
 * Capability::RISKS_VIEW + Capability::CLUSTER_TREE_VIEW on
 * actor.organization_id.
 *
 * Contract under test (mirrors CFA-00 owner decisions 2026-07-09):
 *   - Both grants required to widen cluster_tree. Missing either ⇒ strict
 *     same-org behavior (no descendant records in the result).
 *   - Sibling cluster denied.
 *   - Child → parent denied (one-directional — scope widens DOWN only).
 *   - Null-org actor denied (fail-closed via empty org list).
 *   - super_admin bypass unchanged.
 *   - The scope only widens the FLOOR; the OR-logic for direct relations,
 *     engine grants, governing-department, and flat-ladder widening is
 *     preserved as-is (it widens via the same $visibleOrgIds mechanism).
 *   - Per CFA-00: write paths (CRUD + reassess + changeStatus) stay
 *     strict same-org at the policy layer; this test only covers the
 *     read surface.
 */
class ClusterTreeUserRiskScopeTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    private UserRiskScope $scope;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scope = new UserRiskScope;
    }

    private function makeClusterTree(string $clusterName = 'cluster', string $hospitalName = 'hospital'): array
    {
        $cluster = Organization::factory()->cluster()->create(['name' => $clusterName]);
        $hospital = Organization::factory()->hospital()->childOf($cluster)->create(['name' => $hospitalName]);
        $sibling = Organization::factory()->create(['name' => 'sibling of '.$hospitalName]);

        return [$cluster, $hospital, $sibling];
    }

    private function makeRiskInOrg(int $orgId): Risk
    {
        return Risk::factory()->create(['organization_id' => $orgId]);
    }

    // ============================================================
    // Cluster widening on the floor
    // ============================================================

    public function test_cluster_user_with_read_pair_sees_own_and_descendant_risks(): void
    {
        [$cluster, $hospital, $sibling] = $this->makeClusterTree();

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, [
            Capability::RISKS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $this->makeRiskInOrg($cluster->id);
        $this->makeRiskInOrg($cluster->id);
        $this->makeRiskInOrg($hospital->id);
        $this->makeRiskInOrg($hospital->id);
        $this->makeRiskInOrg($hospital->id);
        $this->makeRiskInOrg($sibling->id);
        $this->makeRiskInOrg($sibling->id);

        $query = Risk::query();
        $this->scope->apply($query, $user);

        // Cluster RISKS_VIEW on user.org unlocks the full-org access path
        // (AccessDecision::grantsAtOrganization returns true). After
        // CFA-05, the cluster widening also covers descendants. Either
        // way, the strict same-org floor widens from [cluster] to
        // [cluster, hospital]. Sibling excluded.
        $this->assertSame(5, (clone $query)->count());
    }

    public function test_cluster_user_with_only_risks_view_does_not_see_descendant_risks(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, Capability::RISKS_VIEW);

        $this->makeRiskInOrg($cluster->id);
        $this->makeRiskInOrg($cluster->id);
        $this->makeRiskInOrg($hospital->id);
        $this->makeRiskInOrg($hospital->id);

        $query = Risk::query();
        $this->scope->apply($query, $user);

        // RISKS_VIEW alone widens to org-level access (the engine grants
        // the actor org-level grantsAtOrganization), so the cluster org
        // risks are visible. The CFA-05 cluster widening does NOT fire
        // (no CLUSTER_TREE_VIEW). The hospital risks live in a separate
        // org, so without cluster widening, they are NOT visible.
        $count = (clone $query)->count();
        $hospitalRiskCount = (clone $query)->where('organization_id', $hospital->id)->count();
        $this->assertSame(0, $hospitalRiskCount, 'no descendant hospital risks visible without CLUSTER_TREE_VIEW');
        $this->assertSame(2, $count, 'cluster org risks visible (grantsAtOrganization enables org-level access)');
    }

    public function test_cluster_user_with_only_cluster_tree_view_does_not_see_descendant_risks(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, Capability::CLUSTER_TREE_VIEW);

        $this->makeRiskInOrg($hospital->id);
        $this->makeRiskInOrg($hospital->id);

        $query = Risk::query();
        $this->scope->apply($query, $user);

        // CLUSTER_TREE_VIEW alone does NOT widen (RISKS_VIEW missing).
        // The CFA-05 contract requires BOTH grants — missing either ⇒
        // no descendant widening. The user MUST NOT see descendant
        // hospital risks.
        $hospitalRiskCount = (clone $query)->where('organization_id', $hospital->id)->count();
        $this->assertSame(0, $hospitalRiskCount, 'no descendant hospital risks visible with only CLUSTER_TREE_VIEW');
    }

    public function test_sibling_cluster_isolated_for_risks(): void
    {
        [$clusterA, $hospitalA] = $this->makeClusterTree('cluster A', 'hospital A');
        [$clusterB, $hospitalB] = $this->makeClusterTree('cluster B', 'hospital B');

        $user = User::factory()->create(['organization_id' => $clusterB->id, 'is_active' => true]);
        $this->grantEngineCapability($user, [
            Capability::RISKS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $this->makeRiskInOrg($clusterA->id);
        $this->makeRiskInOrg($clusterA->id);
        $this->makeRiskInOrg($hospitalA->id);
        $this->makeRiskInOrg($hospitalA->id);
        $this->makeRiskInOrg($clusterB->id);
        $this->makeRiskInOrg($hospitalB->id);

        $query = Risk::query();
        $this->scope->apply($query, $user);

        // ClusterB has full-org RISKS_VIEW access (sees clusterB org
        // risks). With CLUSTER_TREE_VIEW, the floor widens to include
        // hospitalB. So total = 2 (clusterB + hospitalB).
        // ClusterA subtree excluded.
        $this->assertSame(2, (clone $query)->count());
    }

    public function test_child_user_cannot_see_parent_cluster_risks_via_cluster_tree(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $childUser = User::factory()->create(['organization_id' => $hospital->id, 'is_active' => true]);
        $this->grantEngineCapability($childUser, [
            Capability::RISKS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $this->makeRiskInOrg($cluster->id);
        $this->makeRiskInOrg($cluster->id);
        $this->makeRiskInOrg($hospital->id);
        $this->makeRiskInOrg($hospital->id);

        $query = Risk::query();
        $this->scope->apply($query, $childUser);

        // Strict same-org (hospital). Floor = [hospital]. Cluster-risks
        // in cluster org are not visible.
        $clusterRiskCount = (clone $query)->where('organization_id', $cluster->id)->count();
        $this->assertSame(0, $clusterRiskCount, 'cluster-org risks hidden when actor is in hospital org');
        $hospitalRiskCount = (clone $query)->where('organization_id', $hospital->id)->count();
        $this->assertSame(2, $hospitalRiskCount, 'hospital-org risks visible (same-org access)');
    }

    public function test_super_admin_sees_all_risks_regardless_of_grants(): void
    {
        [$cluster, $hospital, $sibling] = $this->makeClusterTree();

        $superAdmin = User::factory()->create(['organization_id' => null, 'is_active' => true]);
        $superAdmin->assignRole('super_admin');

        $this->makeRiskInOrg($cluster->id);
        $this->makeRiskInOrg($cluster->id);
        $this->makeRiskInOrg($hospital->id);
        $this->makeRiskInOrg($hospital->id);
        $this->makeRiskInOrg($sibling->id);

        $query = Risk::query();
        $this->scope->apply($query, $superAdmin);

        $this->assertSame(5, (clone $query)->count());
    }

    public function test_unrelated_org_outside_the_cluster_is_excluded(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $unrelated = Organization::factory()->create(['name' => 'unrelated']);

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, [
            Capability::RISKS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $this->makeRiskInOrg($cluster->id);
        $this->makeRiskInOrg($cluster->id);
        $this->makeRiskInOrg($hospital->id);
        $this->makeRiskInOrg($hospital->id);
        $this->makeRiskInOrg($unrelated->id);
        $this->makeRiskInOrg($unrelated->id);

        $query = Risk::query();
        $this->scope->apply($query, $user);

        // Cluster has grantsAtOrganization => org-level access => sees
        // cluster org risks. CLUSTER_TREE_VIEW widens floor to include
        // hospital. Total = 4 (cluster + hospital). Unrelated excluded.
        $this->assertSame(4, (clone $query)->count());
    }

    public function test_null_org_actor_does_not_see_descendant_risks_without_grants_at_organization(): void
    {
        // Null-org actor with no org-level grants at all. The scope's
        // clusterVisibleOrgIds() returns [] for null-org actors — fail-closed
        // via empty org list. Engine's grantsAtOrganization returns false
        // (no org-scope role), the user doesn't govern, and no
        // direct-relation OR-dept match, so the result is empty.
        [, $hospital] = $this->makeClusterTree();

        $nullOrgUser = User::factory()->create(['organization_id' => null, 'is_active' => true]);

        $this->makeRiskInOrg($hospital->id);
        $this->makeRiskInOrg($hospital->id);

        $query = Risk::query();
        $this->scope->apply($query, $nullOrgUser);

        $this->assertSame(0, (clone $query)->count(), 'null-org actor with no grants fails closed — no risks visible');
    }
}
