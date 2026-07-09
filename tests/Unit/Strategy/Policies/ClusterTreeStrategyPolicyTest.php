<?php

namespace Tests\Unit\Strategy\Policies;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Strategy\Models\Blocker;
use App\Modules\Strategy\Models\Portfolio;
use App\Modules\Strategy\Models\Program;
use App\Modules\Strategy\Models\Review;
use App\Modules\Strategy\Policies\BlockerPolicy;
use App\Modules\Strategy\Policies\PortfolioPolicy;
use App\Modules\Strategy\Policies\ProgramPolicy;
use App\Modules\Strategy\Policies\ReviewPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * ClusterTreeStrategyPolicyTest - Phase 9-D-D1b: cluster_tree read widening
 * at the Strategy Policy layer (Portfolio / Program / Review / Blocker).
 *
 * Proves (per surface, identical contract):
 *   1) cluster user with STRATEGY_VIEW + CLUSTER_TREE_VIEW ⇒ view() on
 *      child-org record = true.
 *   2) cluster user without CLUSTER_TREE_VIEW ⇒ view() = false.
 *   3) cluster user without STRATEGY_VIEW ⇒ view() = false.
 *   4) sibling cluster ⇒ view() = false.
 *   5) child user ⇒ cannot view parent cluster record via cluster_tree.
 *   6) update / delete stay org-strict (no widening).
 *   7) super_admin bypasses everything.
 *   8) null-org user ⇒ view() = false (fail-closed).
 *   9) unrelated org outside the cluster ⇒ no widening.
 */
class ClusterTreeStrategyPolicyTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    public function test_cluster_user_with_both_grants_can_view_child_org_portfolio(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [
            Capability::STRATEGY_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $childPortfolio = Portfolio::factory()->create(['organization_id' => $hospital->id]);

        $this->assertTrue((new PortfolioPolicy)->view($user, $childPortfolio));
    }

    public function test_cluster_user_without_cluster_tree_view_cannot_view_child_org_portfolio(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, Capability::STRATEGY_VIEW);

        $childPortfolio = Portfolio::factory()->create(['organization_id' => $hospital->id]);

        $this->assertFalse((new PortfolioPolicy)->view($user, $childPortfolio));
    }

    public function test_cluster_user_without_strategy_view_cannot_view_child_org_portfolio(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, Capability::CLUSTER_TREE_VIEW);

        $childPortfolio = Portfolio::factory()->create(['organization_id' => $hospital->id]);

        $this->assertFalse((new PortfolioPolicy)->view($user, $childPortfolio));
    }

    public function test_sibling_cluster_cannot_view_other_cluster_child_portfolio(): void
    {
        [$clusterA, $hospitalA] = $this->makeClusterTree('cluster A', 'hospital A');
        [$clusterB] = $this->makeClusterTree('cluster B', 'hospital B');

        $user = User::factory()->create([
            'organization_id' => $clusterA->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [
            Capability::STRATEGY_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $childPortfolio = Portfolio::factory()->create(['organization_id' => $hospitalA->id]);

        // Switch actor to cluster B - sibling, not ancestor of cluster A.
        $user->update(['organization_id' => $clusterB->id]);
        $this->assertFalse((new PortfolioPolicy)->view($user, $childPortfolio));
    }

    public function test_child_user_cannot_view_parent_cluster_portfolio_via_cluster_tree(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $childUser = User::factory()->create([
            'organization_id' => $hospital->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($childUser, [
            Capability::STRATEGY_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $parentPortfolio = Portfolio::factory()->create(['organization_id' => $cluster->id]);

        $this->assertFalse((new PortfolioPolicy)->view($childUser, $parentPortfolio));
    }

    public function test_super_admin_bypasses_everything(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $superAdmin = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $superAdmin->assignRole('super_admin');

        $childPortfolio = Portfolio::factory()->create(['organization_id' => $hospital->id]);

        $this->assertTrue((new PortfolioPolicy)->view($superAdmin, $childPortfolio));
    }

    public function test_null_org_user_cannot_view_anything_via_cluster_tree(): void
    {
        [, $hospital] = $this->makeClusterTree();

        $nullOrgUser = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($nullOrgUser, [
            Capability::STRATEGY_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $childPortfolio = Portfolio::factory()->create(['organization_id' => $hospital->id]);

        $this->assertFalse((new PortfolioPolicy)->view($nullOrgUser, $childPortfolio));
    }

    public function test_update_stays_org_strict_no_widening(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [
            Capability::STRATEGY_VIEW,
            Capability::STRATEGY_EDIT,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $childPortfolio = Portfolio::factory()->create(['organization_id' => $hospital->id]);

        // Even with all cluster grants, update on a child-org portfolio must
        // remain strict same-org (deny).
        $this->assertFalse((new PortfolioPolicy)->update($user, $childPortfolio));
    }

    public function test_delete_stays_org_strict_no_widening(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [
            Capability::STRATEGY_VIEW,
            Capability::STRATEGY_DELETE,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $childPortfolio = Portfolio::factory()->create(['organization_id' => $hospital->id]);

        $this->assertFalse((new PortfolioPolicy)->delete($user, $childPortfolio));
    }

    // ──────────────────────────────────────────────────────────────
    // Program surface — same contract
    // ──────────────────────────────────────────────────────────────

    public function test_cluster_user_with_both_grants_can_view_child_org_program(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [
            Capability::STRATEGY_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $childProgram = Program::factory()->create(['organization_id' => $hospital->id]);

        $this->assertTrue((new ProgramPolicy)->view($user, $childProgram));
    }

    public function test_missing_either_grant_blocks_program_view(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $childProgram = Program::factory()->create(['organization_id' => $hospital->id]);

        $userNoCluster = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($userNoCluster, Capability::STRATEGY_VIEW);
        $this->assertFalse((new ProgramPolicy)->view($userNoCluster, $childProgram));

        $userNoStrategy = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($userNoStrategy, Capability::CLUSTER_TREE_VIEW);
        $this->assertFalse((new ProgramPolicy)->view($userNoStrategy, $childProgram));
    }

    public function test_sibling_cluster_isolated_for_program(): void
    {
        [$clusterA, $hospitalA] = $this->makeClusterTree('cluster A', 'hospital A');
        [$clusterB] = $this->makeClusterTree('cluster B', 'hospital B');

        $user = User::factory()->create(['organization_id' => $clusterB->id, 'is_active' => true]);
        $this->grantEngineCapability($user, [
            Capability::STRATEGY_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $childProgram = Program::factory()->create(['organization_id' => $hospitalA->id]);

        $this->assertFalse((new ProgramPolicy)->view($user, $childProgram));
    }

    public function test_program_update_stays_org_strict(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, [
            Capability::STRATEGY_VIEW,
            Capability::STRATEGY_EDIT,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $childProgram = Program::factory()->create(['organization_id' => $hospital->id]);

        $this->assertFalse((new ProgramPolicy)->update($user, $childProgram));
    }

    // ──────────────────────────────────────────────────────────────
    // Review surface — same contract
    // ──────────────────────────────────────────────────────────────

    public function test_cluster_user_with_both_grants_can_view_child_org_review(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, [
            Capability::STRATEGY_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $childReview = $this->makeReviewWithOrg($hospital->id);

        $this->assertTrue((new ReviewPolicy)->view($user, $childReview));
    }

    public function test_sibling_cluster_isolated_for_review(): void
    {
        [$clusterA, $hospitalA] = $this->makeClusterTree('cluster A', 'hospital A');
        [$clusterB] = $this->makeClusterTree('cluster B', 'hospital B');

        $user = User::factory()->create(['organization_id' => $clusterB->id, 'is_active' => true]);
        $this->grantEngineCapability($user, [
            Capability::STRATEGY_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $childReview = $this->makeReviewWithOrg($hospitalA->id);

        $this->assertFalse((new ReviewPolicy)->view($user, $childReview));
    }

    public function test_child_user_cannot_view_parent_cluster_review(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $childUser = User::factory()->create(['organization_id' => $hospital->id, 'is_active' => true]);
        $this->grantEngineCapability($childUser, [
            Capability::STRATEGY_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $parentReview = $this->makeReviewWithOrg($cluster->id);

        $this->assertFalse((new ReviewPolicy)->view($childUser, $parentReview));
    }

    // ──────────────────────────────────────────────────────────────
    // Blocker surface — same contract
    // ──────────────────────────────────────────────────────────────

    public function test_cluster_user_with_both_grants_can_view_child_org_blocker(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, [
            Capability::STRATEGY_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $childBlocker = $this->makeBlockerWithOrg($hospital->id);

        $this->assertTrue((new BlockerPolicy)->view($user, $childBlocker));
    }

    public function test_sibling_cluster_isolated_for_blocker(): void
    {
        [$clusterA, $hospitalA] = $this->makeClusterTree('cluster A', 'hospital A');
        [$clusterB] = $this->makeClusterTree('cluster B', 'hospital B');

        $user = User::factory()->create(['organization_id' => $clusterB->id, 'is_active' => true]);
        $this->grantEngineCapability($user, [
            Capability::STRATEGY_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $childBlocker = $this->makeBlockerWithOrg($hospitalA->id);

        $this->assertFalse((new BlockerPolicy)->view($user, $childBlocker));
    }

    public function test_blocker_update_stays_org_strict(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, [
            Capability::STRATEGY_VIEW,
            Capability::STRATEGY_EDIT,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $childBlocker = $this->makeBlockerWithOrg($hospital->id);

        $this->assertFalse((new BlockerPolicy)->update($user, $childBlocker));
    }

    public function test_blocker_resolve_stays_org_strict(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, [
            Capability::STRATEGY_VIEW,
            Capability::STRATEGY_EDIT,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $childBlocker = $this->makeBlockerWithOrg($hospital->id);

        $this->assertFalse((new BlockerPolicy)->resolve($user, $childBlocker));
    }

    // ──────────────────────────────────────────────────────────────
    // helpers
    // ──────────────────────────────────────────────────────────────

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

    private function makeReviewWithOrg(int $orgId): Review
    {
        $r = new Review([
            'title' => 'test review',
            'reviewable_type' => 'project',
            'reviewable_id' => 0,
            'type' => 'monthly',
            'overall_status' => 'on_track',
            'review_date' => now()->toDateString(),
            'period_start' => now()->toDateString(),
            'period_end' => now()->toDateString(),
        ]);
        $r->forceFill(['organization_id' => $orgId])->save();

        return $r;
    }

    private function makeBlockerWithOrg(int $orgId): Blocker
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
}
