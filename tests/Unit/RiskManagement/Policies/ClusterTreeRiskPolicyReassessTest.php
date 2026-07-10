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
 * ClusterTreeRiskPolicyReassessTest — Phase CFA-05.
 *
 * Cluster tree widening for RiskPolicy::reassess() (governance write):
 *   - reassess() widens via RISKS_REASSESS + CLUSTER_TREE_MANAGE on actor.org
 *   - reassess() is a governance write, NOT arbitrary CRUD — only reassess /
 *     changeStatus widen via CLUSTER_TREE_MANAGE per CFA-00 owner decision.
 *
 * Sibling test to ClusterTreeProjectsPolicyTest::updateStatus tests (CFA-04).
 */
class ClusterTreeRiskPolicyReassessTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    private function makeClusterTree(string $clusterName = 'cluster', string $hospitalName = 'hospital'): array
    {
        $cluster = Organization::factory()->cluster()->create(['name' => $clusterName]);
        $hospital = Organization::factory()->hospital()->childOf($cluster)->create(['name' => $hospitalName]);

        return [$cluster, $hospital];
    }

    public function test_cluster_user_with_manage_pair_can_reassess_child_org_risk(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, [
            Capability::RISKS_REASSESS,
            Capability::CLUSTER_TREE_MANAGE,
        ]);

        $childRisk = Risk::factory()->forOrganization($hospital)->create();

        $this->assertTrue((new RiskPolicy)->reassess($user, $childRisk));
    }

    public function test_cluster_user_without_cluster_tree_manage_cannot_reassess_child_org_risk(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, Capability::RISKS_REASSESS);

        $childRisk = Risk::factory()->forOrganization($hospital)->create();

        $this->assertFalse((new RiskPolicy)->reassess($user, $childRisk));
    }

    public function test_cluster_user_without_risks_reassess_cannot_reassess_child_org_risk(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, Capability::CLUSTER_TREE_MANAGE);

        $childRisk = Risk::factory()->forOrganization($hospital)->create();

        $this->assertFalse((new RiskPolicy)->reassess($user, $childRisk));
    }

    public function test_sibling_cluster_isolated_for_reassess(): void
    {
        [$clusterA, $hospitalA] = $this->makeClusterTree('cluster A', 'hospital A');
        [$clusterB] = $this->makeClusterTree('cluster B', 'hospital B');

        $user = User::factory()->create(['organization_id' => $clusterA->id, 'is_active' => true]);
        $this->grantEngineCapability($user, [
            Capability::RISKS_REASSESS,
            Capability::CLUSTER_TREE_MANAGE,
        ]);

        $childRisk = Risk::factory()->forOrganization($hospitalA)->create();

        $user->update(['organization_id' => $clusterB->id]);
        $this->assertFalse((new RiskPolicy)->reassess($user, $childRisk));
    }

    public function test_child_user_cannot_reassess_parent_cluster_risk_via_cluster_tree(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $childUser = User::factory()->create(['organization_id' => $hospital->id, 'is_active' => true]);
        $this->grantEngineCapability($childUser, [
            Capability::RISKS_REASSESS,
            Capability::CLUSTER_TREE_MANAGE,
        ]);

        $parentRisk = Risk::factory()->forOrganization($cluster)->create();

        $this->assertFalse((new RiskPolicy)->reassess($childUser, $parentRisk));
    }

    public function test_reassess_returns_true_for_same_org_target(): void
    {
        $org = Organization::factory()->create();
        $risk = Risk::factory()->forOrganization($org)->create();

        $user = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $this->grantEngineCapability($user, [
            Capability::RISKS_REASSESS,
            Capability::CLUSTER_TREE_MANAGE,
        ]);

        $this->assertTrue((new RiskPolicy)->reassess($user, $risk));
    }

    public function test_super_admin_can_reassess_child_org_risk(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $superAdmin = User::factory()->create(['organization_id' => null, 'is_active' => true]);
        $superAdmin->assignRole('super_admin');

        $childRisk = Risk::factory()->forOrganization($hospital)->create();

        $this->assertTrue((new RiskPolicy)->reassess($superAdmin, $childRisk));
    }
}
