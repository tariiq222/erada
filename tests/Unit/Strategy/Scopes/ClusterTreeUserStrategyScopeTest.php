<?php

namespace Tests\Unit\Strategy\Scopes;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Strategy\Models\Blocker;
use App\Modules\Strategy\Models\Portfolio;
use App\Modules\Strategy\Models\Program;
use App\Modules\Strategy\Models\Review;
use App\Modules\Strategy\Scopes\UserStrategyScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * ClusterTreeUserStrategyScopeTest - Phase 9-D-D1b: cluster_tree read widening
 * at the UserStrategyScope layer (the per-list filter that backs
 * index / list / summary / goldenChain across Portfolios, Programs, Reviews,
 * and Blockers).
 *
 * Proves (per surface):
 *   1) cluster user with STRATEGY_VIEW + CLUSTER_TREE_VIEW sees own + descendant records.
 *   2) cluster user with STRATEGY_VIEW only ⇒ strict same-org (no widening).
 *   3) cluster user with CLUSTER_TREE_VIEW only ⇒ strict same-org (both caps required).
 *   4) cluster user with both ⇒ sibling org records stay hidden.
 *   5) child user ⇒ cannot see parent cluster records (one-directional).
 *   6) null-org user ⇒ sees nothing (fail-closed).
 *   7) super_admin sees everything regardless of grants.
 */
class ClusterTreeUserStrategyScopeTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    private UserStrategyScope $scope;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scope = new UserStrategyScope;
    }

    public function test_cluster_user_with_both_grants_sees_own_and_descendant_portfolios(): void
    {
        [$cluster, $hospital, $sibling] = $this->makeClusterTree();

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, [
            Capability::STRATEGY_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        Portfolio::factory()->count(2)->create(['organization_id' => $cluster->id]);
        Portfolio::factory()->count(3)->create(['organization_id' => $hospital->id]);
        Portfolio::factory()->count(4)->create(['organization_id' => $sibling->id]);

        $query = Portfolio::query();
        $this->scope->applyToPortfolios($query, $user);

        // 2 (cluster) + 3 (hospital) = 5. Sibling excluded.
        $this->assertSame(5, (clone $query)->count());
    }

    public function test_cluster_user_with_only_strategy_view_does_not_see_descendant_portfolios(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, Capability::STRATEGY_VIEW);

        Portfolio::factory()->count(2)->create(['organization_id' => $cluster->id]);
        Portfolio::factory()->count(3)->create(['organization_id' => $hospital->id]);

        $query = Portfolio::query();
        $this->scope->applyToPortfolios($query, $user);

        $this->assertSame(2, (clone $query)->count());
    }

    public function test_cluster_user_with_only_cluster_tree_view_does_not_see_descendant_portfolios(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, Capability::CLUSTER_TREE_VIEW);

        Portfolio::factory()->count(2)->create(['organization_id' => $cluster->id]);
        Portfolio::factory()->count(3)->create(['organization_id' => $hospital->id]);

        $query = Portfolio::query();
        $this->scope->applyToPortfolios($query, $user);

        $this->assertSame(2, (clone $query)->count());
    }

    public function test_sibling_cluster_isolated_for_portfolios(): void
    {
        [$clusterA, $hospitalA] = $this->makeClusterTree('cluster A', 'hospital A');
        [$clusterB, $hospitalB] = $this->makeClusterTree('cluster B', 'hospital B');

        $user = User::factory()->create(['organization_id' => $clusterB->id, 'is_active' => true]);
        $this->grantEngineCapability($user, [
            Capability::STRATEGY_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        Portfolio::factory()->count(2)->create(['organization_id' => $clusterA->id]);
        Portfolio::factory()->count(3)->create(['organization_id' => $hospitalA->id]);
        Portfolio::factory()->count(1)->create(['organization_id' => $clusterB->id]);
        Portfolio::factory()->count(2)->create(['organization_id' => $hospitalB->id]);

        $query = Portfolio::query();
        $this->scope->applyToPortfolios($query, $user);

        // Only clusterB (1) + hospitalB (2) = 3.
        $this->assertSame(3, (clone $query)->count());
    }

    public function test_child_user_cannot_see_parent_cluster_portfolios_via_cluster_tree(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $childUser = User::factory()->create(['organization_id' => $hospital->id, 'is_active' => true]);
        $this->grantEngineCapability($childUser, [
            Capability::STRATEGY_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        Portfolio::factory()->count(2)->create(['organization_id' => $cluster->id]);
        Portfolio::factory()->count(3)->create(['organization_id' => $hospital->id]);

        $query = Portfolio::query();
        $this->scope->applyToPortfolios($query, $childUser);

        // Strict same-org (hospital): only 3.
        $this->assertSame(3, (clone $query)->count());
    }

    public function test_null_org_user_sees_nothing(): void
    {
        [, $hospital] = $this->makeClusterTree();

        $nullUser = User::factory()->create(['organization_id' => null, 'is_active' => true]);
        $this->grantEngineCapability($nullUser, [
            Capability::STRATEGY_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        Portfolio::factory()->count(2)->create(['organization_id' => $hospital->id]);

        $query = Portfolio::query();
        $this->scope->applyToPortfolios($query, $nullUser);

        $this->assertSame(0, (clone $query)->count());
    }

    public function test_super_admin_sees_everything_regardless_of_grants(): void
    {
        [$cluster, $hospital, $sibling] = $this->makeClusterTree();

        $superAdmin = User::factory()->create(['organization_id' => null, 'is_active' => true]);
        $this->grantCanonicalSuperAdmin($superAdmin);

        Portfolio::factory()->count(2)->create(['organization_id' => $cluster->id]);
        Portfolio::factory()->count(3)->create(['organization_id' => $hospital->id]);
        Portfolio::factory()->count(1)->create(['organization_id' => $sibling->id]);

        $query = Portfolio::query();
        $this->scope->applyToPortfolios($query, $superAdmin);

        $this->assertSame(6, (clone $query)->count());
    }

    public function test_programs_inherit_cluster_widening(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, [
            Capability::STRATEGY_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        Program::factory()->count(2)->create(['organization_id' => $cluster->id]);
        Program::factory()->count(3)->create(['organization_id' => $hospital->id]);

        $query = Program::query();
        $this->scope->applyToPrograms($query, $user);

        $this->assertSame(5, (clone $query)->count());
    }

    public function test_reviews_inherit_cluster_widening(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, [
            Capability::STRATEGY_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $this->makeReviewsInOrg($cluster->id, 2);
        $this->makeReviewsInOrg($hospital->id, 3);

        $query = Review::query();
        $this->scope->applyToReviews($query, $user);

        $this->assertSame(5, (clone $query)->count());
    }

    public function test_blockers_inherit_cluster_widening(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, [
            Capability::STRATEGY_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $this->makeBlockersInOrg($cluster->id, 2);
        $this->makeBlockersInOrg($hospital->id, 3);

        $query = Blocker::query();
        $this->scope->applyToBlockers($query, $user);

        $this->assertSame(5, (clone $query)->count());
    }

    public function test_unrelated_org_outside_the_cluster_is_excluded(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $unrelated = Organization::factory()->create(['name' => 'unrelated']);

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, [
            Capability::STRATEGY_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        Portfolio::factory()->count(2)->create(['organization_id' => $cluster->id]);
        Portfolio::factory()->count(3)->create(['organization_id' => $hospital->id]);
        Portfolio::factory()->count(4)->create(['organization_id' => $unrelated->id]);

        $query = Portfolio::query();
        $this->scope->applyToPortfolios($query, $user);

        $this->assertSame(5, (clone $query)->count());
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

    private function makeReviewsInOrg(int $orgId, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $r = new Review([
                'title' => 'test review '.$i,
                'reviewable_type' => 'project',
                'reviewable_id' => 0,
                'type' => 'monthly',
                'overall_status' => 'on_track',
                'review_date' => now()->toDateString(),
                'period_start' => now()->toDateString(),
                'period_end' => now()->toDateString(),
            ]);
            $r->forceFill(['organization_id' => $orgId])->save();
        }
    }

    private function makeBlockersInOrg(int $orgId, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $b = new Blocker([
                'title' => 'test '.$i,
                'description' => 'desc',
                'blockable_type' => 'project',
                'blockable_id' => 0,
                'status' => Blocker::STATUS_OPEN,
                'severity' => 'medium',
                'identified_date' => now()->toDateString(),
            ]);
            $b->forceFill(['organization_id' => $orgId])->save();
        }
    }
}
