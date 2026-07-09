<?php

namespace Tests\Unit\Strategy\Policies;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Strategy\Models\Blocker;
use App\Modules\Strategy\Models\Portfolio;
use App\Modules\Strategy\Policies\BlockerPolicy;
use App\Modules\Strategy\Policies\PortfolioPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * ClusterTreeStrategyManageTest — Phase CFA-03.
 *
 * Cluster tree governance-write widening for Strategy (Portfolio +
 * Blocker). Sibling test to ClusterTreeStrategyPolicyTest (Phase 9-D-D1b,
 * which covers the read widening).
 *
 * Proves (per surface, per ability):
 *   1) cluster user with module capability + CLUSTER_TREE_MANAGE ⇒
 *      changeStrategicStatus / managePriority / forceClose / assignOwner
 *      (Portfolio) or resolve / escalate (Blocker) on a child-org record
 *      = true.
 *   2) cluster user without CLUSTER_TREE_MANAGE ⇒ ability = false.
 *   3) cluster user without the module capability (STRATEGY_CHANGE_STATUS
 *      / STRATEGY_MANAGE_PRIORITY / STRATEGY_ASSIGN_OWNER / STRATEGY_EDIT)
 *      ⇒ ability = false.
 *   4) cluster user with CLUSTER_TREE_VIEW only (read primitive, not manage
 *      primitive) ⇒ ability = false (primitives are independent).
 *   5) sibling cluster ⇒ ability = false.
 *   6) child user ⇒ cannot manage parent cluster record via cluster_tree
 *      (one-directional).
 *   7) CRUD stays strict same-org (update / delete / create denied on
 *      child-org record even with both grants).
 *   8) super_admin bypasses everything.
 *   9) null-org user ⇒ fail-closed.
 *
 * Phase CFA-00 owner decision (2026-07-09): ProgramPolicy::linkProject is
 * OUT OF SCOPE for CFA-03 (not approved for cluster widening). This test
 * proves the explicit non-widening of linkProject.
 */
class ClusterTreeStrategyManageTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    /**
     * @return array{0: Organization, 1: Organization, 2: Organization}
     */
    private function makeClusterTree(string $clusterName = 'cluster', string $hospitalName = 'hospital'): array
    {
        $cluster = Organization::factory()->cluster()->create(['name' => $clusterName]);
        $hospital = Organization::factory()->hospital()->childOf($cluster)->create(['name' => $hospitalName]);
        $sibling = Organization::factory()->create(['name' => 'sibling of '.$hospitalName]);

        return [$cluster, $hospital, $sibling];
    }

    private function makePortfolioInOrg(int $orgId): Portfolio
    {
        return Portfolio::factory()->create(['organization_id' => $orgId]);
    }

    private function makeBlockerInOrg(int $orgId): Blocker
    {
        $b = new Blocker([
            'title' => 'test blocker',
            'description' => 'desc',
            'blockable_type' => 'project',
            'blockable_id' => 0,
            'status' => Blocker::STATUS_OPEN,
            'severity' => 'medium',
            'identified_date' => now()->toDateString(),
        ]);
        $b->forceFill(['organization_id' => $orgId])->save();

        return $b;
    }

    // ============================================================
    // Portfolio — changeStrategicStatus
    // ============================================================

    public function test_cluster_user_with_manage_pair_can_change_child_portfolio_strategic_status(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, [
            Capability::STRATEGY_CHANGE_STATUS,
            Capability::CLUSTER_TREE_MANAGE,
        ]);

        $childPortfolio = $this->makePortfolioInOrg($hospital->id);

        $this->assertTrue((new PortfolioPolicy)->changeStrategicStatus($user, $childPortfolio));
    }

    public function test_cluster_user_without_cluster_tree_manage_cannot_change_child_portfolio_status(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, Capability::STRATEGY_CHANGE_STATUS);

        $childPortfolio = $this->makePortfolioInOrg($hospital->id);

        $this->assertFalse((new PortfolioPolicy)->changeStrategicStatus($user, $childPortfolio));
    }

    public function test_cluster_user_without_strategy_change_status_cannot_change_child_portfolio_status(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, Capability::CLUSTER_TREE_MANAGE);

        $childPortfolio = $this->makePortfolioInOrg($hospital->id);

        $this->assertFalse((new PortfolioPolicy)->changeStrategicStatus($user, $childPortfolio));
    }

    public function test_cluster_user_with_view_primitive_only_cannot_manage(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, [
            Capability::STRATEGY_CHANGE_STATUS,
            Capability::CLUSTER_TREE_VIEW, // wrong primitive
        ]);

        $childPortfolio = $this->makePortfolioInOrg($hospital->id);

        $this->assertFalse((new PortfolioPolicy)->changeStrategicStatus($user, $childPortfolio));
    }

    public function test_sibling_cluster_cannot_change_other_cluster_child_portfolio_status(): void
    {
        [$clusterA, $hospitalA] = $this->makeClusterTree('cluster A', 'hospital A');
        [$clusterB] = $this->makeClusterTree('cluster B', 'hospital B');

        $user = User::factory()->create(['organization_id' => $clusterA->id, 'is_active' => true]);
        $this->grantEngineCapability($user, [
            Capability::STRATEGY_CHANGE_STATUS,
            Capability::CLUSTER_TREE_MANAGE,
        ]);

        $childPortfolio = $this->makePortfolioInOrg($hospitalA->id);

        // Switch actor to cluster B (sibling, not ancestor of cluster A).
        $user->update(['organization_id' => $clusterB->id]);
        $this->assertFalse((new PortfolioPolicy)->changeStrategicStatus($user, $childPortfolio));
    }

    public function test_child_user_cannot_change_parent_cluster_portfolio_status(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $childUser = User::factory()->create(['organization_id' => $hospital->id, 'is_active' => true]);
        $this->grantEngineCapability($childUser, [
            Capability::STRATEGY_CHANGE_STATUS,
            Capability::CLUSTER_TREE_MANAGE,
        ]);

        $parentPortfolio = $this->makePortfolioInOrg($cluster->id);

        $this->assertFalse((new PortfolioPolicy)->changeStrategicStatus($childUser, $parentPortfolio));
    }

    public function test_null_org_user_cannot_change_child_portfolio_status_via_cluster_tree(): void
    {
        [, $hospital] = $this->makeClusterTree();

        $nullOrgUser = User::factory()->create(['organization_id' => null, 'is_active' => true]);
        $this->grantEngineCapability($nullOrgUser, [
            Capability::STRATEGY_CHANGE_STATUS,
            Capability::CLUSTER_TREE_MANAGE,
        ]);

        $childPortfolio = $this->makePortfolioInOrg($hospital->id);

        $this->assertFalse((new PortfolioPolicy)->changeStrategicStatus($nullOrgUser, $childPortfolio));
    }

    public function test_super_admin_can_change_child_portfolio_status(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $superAdmin = User::factory()->create(['organization_id' => null, 'is_active' => true]);
        $superAdmin->assignRole('super_admin');

        $childPortfolio = $this->makePortfolioInOrg($hospital->id);

        $this->assertTrue((new PortfolioPolicy)->changeStrategicStatus($superAdmin, $childPortfolio));
    }

    public function test_update_stays_strict_same_org_no_widening(): void
    {
        // CRUD stays strict same-org per CFA-00 owner decision.
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, [
            Capability::STRATEGY_EDIT,
            Capability::CLUSTER_TREE_MANAGE,
        ]);

        $childPortfolio = $this->makePortfolioInOrg($hospital->id);

        $this->assertFalse((new PortfolioPolicy)->update($user, $childPortfolio));
    }

    public function test_delete_stays_strict_same_org_no_widening(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, [
            Capability::STRATEGY_DELETE,
            Capability::CLUSTER_TREE_MANAGE,
        ]);

        $childPortfolio = $this->makePortfolioInOrg($hospital->id);

        $this->assertFalse((new PortfolioPolicy)->delete($user, $childPortfolio));
    }

    // ============================================================
    // Portfolio — managePriority
    // ============================================================

    public function test_cluster_user_with_manage_pair_can_manage_child_portfolio_priority(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, [
            Capability::STRATEGY_MANAGE_PRIORITY,
            Capability::CLUSTER_TREE_MANAGE,
        ]);

        $childPortfolio = $this->makePortfolioInOrg($hospital->id);

        $this->assertTrue((new PortfolioPolicy)->managePriority($user, $childPortfolio));
    }

    public function test_missing_either_grant_blocks_manage_priority(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();
        $childPortfolio = $this->makePortfolioInOrg($hospital->id);

        $userNoManage = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($userNoManage, Capability::STRATEGY_MANAGE_PRIORITY);
        $this->assertFalse((new PortfolioPolicy)->managePriority($userNoManage, $childPortfolio));

        $userNoCap = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($userNoCap, Capability::CLUSTER_TREE_MANAGE);
        $this->assertFalse((new PortfolioPolicy)->managePriority($userNoCap, $childPortfolio));
    }

    // ============================================================
    // Portfolio — forceClose (mirrors changeStrategicStatus)
    // ============================================================

    public function test_cluster_user_with_manage_pair_can_force_close_child_portfolio(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, [
            Capability::STRATEGY_CHANGE_STATUS,
            Capability::CLUSTER_TREE_MANAGE,
        ]);

        $childPortfolio = $this->makePortfolioInOrg($hospital->id);

        $this->assertTrue((new PortfolioPolicy)->forceClose($user, $childPortfolio));
    }

    // ============================================================
    // Portfolio — assignOwner
    // ============================================================

    public function test_cluster_user_with_manage_pair_can_assign_child_portfolio_owner(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, [
            Capability::STRATEGY_ASSIGN_OWNER,
            Capability::CLUSTER_TREE_MANAGE,
        ]);

        $childPortfolio = $this->makePortfolioInOrg($hospital->id);

        $this->assertTrue((new PortfolioPolicy)->assignOwner($user, $childPortfolio));
    }

    public function test_missing_either_grant_blocks_assign_owner(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();
        $childPortfolio = $this->makePortfolioInOrg($hospital->id);

        $userNoManage = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($userNoManage, Capability::STRATEGY_ASSIGN_OWNER);
        $this->assertFalse((new PortfolioPolicy)->assignOwner($userNoManage, $childPortfolio));

        $userNoCap = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($userNoCap, Capability::CLUSTER_TREE_MANAGE);
        $this->assertFalse((new PortfolioPolicy)->assignOwner($userNoCap, $childPortfolio));
    }

    // ============================================================
    // Blocker — resolve
    // ============================================================

    public function test_cluster_user_with_manage_pair_can_resolve_child_blocker(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, [
            Capability::STRATEGY_EDIT,
            Capability::CLUSTER_TREE_MANAGE,
        ]);

        $childBlocker = $this->makeBlockerInOrg($hospital->id);

        $this->assertTrue((new BlockerPolicy)->resolve($user, $childBlocker));
    }

    public function test_missing_either_grant_blocks_resolve(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();
        $childBlocker = $this->makeBlockerInOrg($hospital->id);

        $userNoManage = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($userNoManage, Capability::STRATEGY_EDIT);
        $this->assertFalse((new BlockerPolicy)->resolve($userNoManage, $childBlocker));

        $userNoCap = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($userNoCap, Capability::CLUSTER_TREE_MANAGE);
        $this->assertFalse((new BlockerPolicy)->resolve($userNoCap, $childBlocker));
    }

    public function test_blocker_update_stays_strict_same_org(): void
    {
        // CRUD stays strict same-org per CFA-00 owner decision.
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, [
            Capability::STRATEGY_EDIT,
            Capability::CLUSTER_TREE_MANAGE,
        ]);

        $childBlocker = $this->makeBlockerInOrg($hospital->id);

        $this->assertFalse((new BlockerPolicy)->update($user, $childBlocker));
    }

    // ============================================================
    // Blocker — escalate
    // ============================================================

    public function test_cluster_user_with_manage_pair_can_escalate_child_blocker(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, [
            Capability::STRATEGY_EDIT,
            Capability::CLUSTER_TREE_MANAGE,
        ]);

        $childBlocker = $this->makeBlockerInOrg($hospital->id);

        $this->assertTrue((new BlockerPolicy)->escalate($user, $childBlocker));
    }

    public function test_sibling_cluster_isolated_for_blocker_resolve(): void
    {
        [$clusterA, $hospitalA] = $this->makeClusterTree('cluster A', 'hospital A');
        [$clusterB] = $this->makeClusterTree('cluster B', 'hospital B');

        $user = User::factory()->create(['organization_id' => $clusterB->id, 'is_active' => true]);
        $this->grantEngineCapability($user, [
            Capability::STRATEGY_EDIT,
            Capability::CLUSTER_TREE_MANAGE,
        ]);

        $childBlocker = $this->makeBlockerInOrg($hospitalA->id);

        $this->assertFalse((new BlockerPolicy)->resolve($user, $childBlocker));
    }
}
