<?php

namespace Tests\Unit\RiskManagement\Policies;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\RiskManagement\Models\Risk;
use App\Modules\RiskManagement\Models\RiskAction;
use App\Modules\RiskManagement\Policies\RiskActionPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * ClusterTreeRiskActionPolicyViewTest — Phase CFA-05.
 *
 * Cluster tree widening for RiskActionPolicy::view() (read). Mirrors the
 * CFA-04 RiskPolicy::view two-path rescue, but on the action target.
 *
 * Sibling test to ClusterTreeRiskPolicyViewTest (CFA-05) and
 * ClusterTreeProjectsPolicyTest::view (CFA-04).
 */
class ClusterTreeRiskActionPolicyViewTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    private function makeClusterTree(string $clusterName = 'cluster', string $hospitalName = 'hospital'): array
    {
        $cluster = Organization::factory()->cluster()->create(['name' => $clusterName]);
        $hospital = Organization::factory()->hospital()->childOf($cluster)->create(['name' => $hospitalName]);

        return [$cluster, $hospital];
    }

    private function makeActionInExistingRisk(Risk $risk): RiskAction
    {
        return RiskAction::factory()->create([
            'organization_id' => $risk->organization_id,
            'risk_id' => $risk->id,
        ]);
    }

    public function test_cluster_user_with_read_pair_can_view_child_org_action(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, [
            Capability::RISKS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $risk = Risk::factory()->forOrganization($hospital)->create();
        $childAction = $this->makeActionInExistingRisk($risk);

        $this->assertTrue((new RiskActionPolicy)->view($user, $childAction));
    }

    public function test_cluster_user_without_cluster_tree_view_cannot_view_child_org_action(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, Capability::RISKS_VIEW);

        $risk = Risk::factory()->forOrganization($hospital)->create();
        $childAction = $this->makeActionInExistingRisk($risk);

        $this->assertFalse((new RiskActionPolicy)->view($user, $childAction));
    }

    public function test_cluster_user_without_risks_view_cannot_view_child_org_action(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, Capability::CLUSTER_TREE_VIEW);

        $risk = Risk::factory()->forOrganization($hospital)->create();
        $childAction = $this->makeActionInExistingRisk($risk);

        $this->assertFalse((new RiskActionPolicy)->view($user, $childAction));
    }

    public function test_sibling_cluster_isolated_for_action_view(): void
    {
        [$clusterA, $hospitalA] = $this->makeClusterTree('cluster A', 'hospital A');
        [$clusterB] = $this->makeClusterTree('cluster B', 'hospital B');

        $user = User::factory()->create(['organization_id' => $clusterA->id, 'is_active' => true]);
        $this->grantEngineCapability($user, [
            Capability::RISKS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $risk = Risk::factory()->forOrganization($hospitalA)->create();
        $childAction = $this->makeActionInExistingRisk($risk);

        $user->update(['organization_id' => $clusterB->id]);
        $this->assertFalse((new RiskActionPolicy)->view($user, $childAction));
    }

    public function test_child_user_cannot_view_parent_cluster_action_via_cluster_tree(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $childUser = User::factory()->create(['organization_id' => $hospital->id, 'is_active' => true]);
        $this->grantEngineCapability($childUser, [
            Capability::RISKS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $parentRisk = Risk::factory()->forOrganization($cluster)->create();
        $parentAction = $this->makeActionInExistingRisk($parentRisk);

        $this->assertFalse((new RiskActionPolicy)->view($childUser, $parentAction));
    }

    public function test_super_admin_can_view_child_org_action(): void
    {
        [, $hospital] = $this->makeClusterTree();

        $superAdmin = User::factory()->create(['organization_id' => null, 'is_active' => true]);
        $this->grantCanonicalSuperAdmin($superAdmin);

        $risk = Risk::factory()->forOrganization($hospital)->create();
        $childAction = $this->makeActionInExistingRisk($risk);

        $this->assertTrue((new RiskActionPolicy)->view($superAdmin, $childAction));
    }

    public function test_action_view_returns_true_for_same_org_target(): void
    {
        $org = Organization::factory()->create();
        $risk = Risk::factory()->forOrganization($org)->create();
        $action = $this->makeActionInExistingRisk($risk);

        $user = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $this->grantEngineCapability($user, [
            Capability::RISKS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $this->assertTrue((new RiskActionPolicy)->view($user, $action));
    }
}
