<?php

namespace Tests\Unit\RiskManagement\Policies;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\RiskManagement\Models\Risk;
use App\Modules\RiskManagement\Policies\RiskPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * ClusterTreeRiskPolicyViewTest — Phase CFA-05.
 *
 * Cluster tree widening for RiskPolicy::view():
 *   - view() widens via RISKS_VIEW + CLUSTER_TREE_VIEW on actor.org
 *   - view() rescues via the engine's cluster_tree branch for cross-org
 *     reads from descendant organizations.
 *
 * Sibling test to ClusterTreeProjectsPolicyTest (CFA-04) and
 * ClusterTreeStrategyPolicyTest (CFA-03).
 */
class ClusterTreeRiskPolicyViewTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    private function makeClusterTree(string $clusterName = 'cluster', string $hospitalName = 'hospital'): array
    {
        $cluster = Organization::factory()->cluster()->create(['name' => $clusterName]);
        $hospital = Organization::factory()->hospital()->childOf($cluster)->create(['name' => $hospitalName]);
        $sibling = Organization::factory()->create(['name' => 'sibling of '.$hospitalName]);

        return [$cluster, $hospital, $sibling];
    }

    private function makeRiskInOrg(int $orgId): Risk
    {
        return Risk::factory()->forOrganization(Organization::factory()->find($orgId) ?? Organization::factory()->create(['id' => $orgId]))->create();
    }

    // ============================================================
    // view() — cluster widening via CLUSTER_TREE_VIEW
    // ============================================================

    public function test_cluster_user_with_read_pair_can_view_child_org_risk(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, [
            Capability::RISKS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $childRisk = Risk::factory()->forOrganization($hospital)->create();

        $this->assertTrue((new RiskPolicy)->view($user, $childRisk));
    }

    public function test_cluster_user_without_cluster_tree_view_cannot_view_child_org_risk(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, Capability::RISKS_VIEW);

        $childRisk = Risk::factory()->forOrganization($hospital)->create();

        $this->assertFalse((new RiskPolicy)->view($user, $childRisk));
    }

    public function test_cluster_user_without_risks_view_cannot_view_child_org_risk(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, Capability::CLUSTER_TREE_VIEW);

        $childRisk = Risk::factory()->forOrganization($hospital)->create();

        $this->assertFalse((new RiskPolicy)->view($user, $childRisk));
    }

    public function test_missing_either_grant_blocks_view(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();
        $childRisk = Risk::factory()->forOrganization($hospital)->create();

        $userNoCluster = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($userNoCluster, Capability::RISKS_VIEW);
        $this->assertFalse((new RiskPolicy)->view($userNoCluster, $childRisk));

        $userNoModule = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($userNoModule, Capability::CLUSTER_TREE_VIEW);
        $this->assertFalse((new RiskPolicy)->view($userNoModule, $childRisk));
    }

    public function test_sibling_cluster_isolated_for_view(): void
    {
        [$clusterA, $hospitalA] = $this->makeClusterTree('cluster A', 'hospital A');
        [$clusterB] = $this->makeClusterTree('cluster B', 'hospital B');

        $user = User::factory()->create(['organization_id' => $clusterA->id, 'is_active' => true]);
        $this->grantEngineCapability($user, [
            Capability::RISKS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $childRisk = Risk::factory()->forOrganization($hospitalA)->create();

        // Switch actor to cluster B (sibling, not ancestor of cluster A).
        $user->update(['organization_id' => $clusterB->id]);
        $this->assertFalse((new RiskPolicy)->view($user, $childRisk));
    }

    public function test_child_user_cannot_view_parent_cluster_risk_via_cluster_tree(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $childUser = User::factory()->create(['organization_id' => $hospital->id, 'is_active' => true]);
        $this->grantEngineCapability($childUser, [
            Capability::RISKS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $parentRisk = Risk::factory()->forOrganization($cluster)->create();

        $this->assertFalse((new RiskPolicy)->view($childUser, $parentRisk));
    }

    public function test_null_org_user_with_grants_cannot_view_child_org_risk(): void
    {
        [, $hospital] = $this->makeClusterTree();

        $nullOrgUser = User::factory()->create(['organization_id' => null, 'is_active' => true]);
        $this->grantEngineCapability($nullOrgUser, [
            Capability::RISKS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $childRisk = Risk::factory()->forOrganization($hospital)->create();

        $this->assertFalse((new RiskPolicy)->view($nullOrgUser, $childRisk));
    }

    public function test_super_admin_can_view_child_org_risk(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $superAdmin = User::factory()->create(['organization_id' => null, 'is_active' => true]);
        $superAdmin->assignRole('super_admin');

        $childRisk = Risk::factory()->forOrganization($hospital)->create();

        $this->assertTrue((new RiskPolicy)->view($superAdmin, $childRisk));
    }

    public function test_view_returns_true_for_same_org_target(): void
    {
        // For same-org targets, rescue is short-circuited; strict-equality gate wins.
        $org = Organization::factory()->create();
        $risk = Risk::factory()->forOrganization($org)->create();

        $user = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $this->grantEngineCapability($user, [
            Capability::RISKS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        // Same-org path: AccessDecision::can(RISKS_VIEW, risk) returns true.
        // The two-path helper also accepts this — true on Path 1.
        $this->assertTrue((new RiskPolicy)->view($user, $risk));
    }
}
