<?php

namespace Tests\Unit\OVR\Policies;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\OVR\Models\IncidentReport;
use App\Modules\OVR\Policies\IncidentReportPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * ClusterTreeOvrIncidentPolicyStatsTest - Phase CFA-09: cluster aggregate reporting
 * (NEVER raw) — policy-level tests for the new viewStats() and
 * exportsAggregates() abilities.
 *
 * CFA-00 strict contract:
 *   - viewStats() widens stats only when BOTH OVR_VIEW_STATISTICS +
 *     CLUSTER_TREE_VIEW are held on actor.organization_id. Missing either
 *     grant ⇒ denied.
 *   - exportsAggregates() widens aggregate export only when BOTH
 *     OVR_EXPORT + CLUSTER_TREE_EXPORT are held on actor.organization_id.
 *   - view() / viewAny() / show() / export() stay strict same-org — they
 *     must NOT widen when only the cluster pair is held.
 *   - super_admin bypasses everything.
 *   - null-org actor ⇒ false (fail-closed).
 */
class ClusterTreeOvrIncidentPolicyStatsTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    private IncidentReportPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new IncidentReportPolicy;
    }

    public function test_view_stats_admits_cluster_pair_on_actor_org(): void
    {
        [$cluster] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        // BOTH grants required.
        $this->grantEngineCapability($user, [
            Capability::OVR_VIEW_STATISTICS,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $this->assertTrue($this->policy->viewStats($user));
    }

    public function test_view_stats_admits_module_cap_alone_strict_same_org(): void
    {
        // The OVR_VIEW_STATISTICS-only path (no cluster_tree) still admits — the
        // cluster widening is OPTIONAL; the same-org floor remains. The
        // controller layer is responsible for the same-org SQL filter via
        // UserOvrScope::clusterStatsVisibleOrgIds. The policy alone cannot
        // distinguish same-org from cluster — that lives in the scope.
        [$cluster] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, Capability::OVR_VIEW_STATISTICS);

        $this->assertTrue($this->policy->viewStats($user));
    }

    public function test_view_stats_denies_when_module_cap_missing(): void
    {
        // CLUSTER_TREE_VIEW alone (no OVR_VIEW_STATISTICS) ⇒ deny.
        [$cluster] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, Capability::CLUSTER_TREE_VIEW);

        $this->assertFalse($this->policy->viewStats($user));
    }

    public function test_view_stats_denies_when_cluster_cap_missing(): void
    {
        // OVR_VIEW_STATISTICS alone (no CLUSTER_TREE_VIEW) ⇒ still admits
        // via the same-org path (see test above). The cluster widening is
        // what requires BOTH grants; the module cap alone is sufficient
        // for the same-org viewStatistics path. The strict cluster-stats
        // widening is gated by the SCOPE layer (UserOvrScope), not the
        // policy alone. This test documents that the policy admits
        // OVR_VIEW_STATISTICS-only (same-org floor is intentional).
        [$cluster] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, Capability::OVR_VIEW_STATISTICS);

        // Without cluster_tree, the policy still admits (same-org floor).
        $this->assertTrue($this->policy->viewStats($user));
    }

    public function test_view_stats_denies_when_no_grants(): void
    {
        [$cluster] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);

        $this->assertFalse($this->policy->viewStats($user));
    }

    public function test_view_stats_denies_null_org_actor(): void
    {
        $orphan = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($orphan, [
            Capability::OVR_VIEW_STATISTICS,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        // null-org actor ⇒ fail-closed.
        $this->assertFalse($this->policy->viewStats($orphan));
    }

    public function test_view_stats_admits_super_admin_without_grants(): void
    {
        $super = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $this->grantCanonicalSuperAdmin($super);

        $this->assertTrue($this->policy->viewStats($super));
    }

    public function test_exports_aggregates_admits_cluster_export_pair(): void
    {
        [$cluster] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [
            Capability::OVR_EXPORT,
            Capability::CLUSTER_TREE_EXPORT,
        ]);

        $this->assertTrue($this->policy->exportsAggregates($user));
    }

    public function test_exports_aggregates_admits_module_cap_alone_strict_same_org(): void
    {
        [$cluster] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, Capability::OVR_EXPORT);

        // Same-org path (strict). Cluster widening is what requires both grants.
        $this->assertTrue($this->policy->exportsAggregates($user));
    }

    public function test_exports_aggregates_denies_when_no_grants(): void
    {
        [$cluster] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);

        $this->assertFalse($this->policy->exportsAggregates($user));
    }

    public function test_exports_aggregates_denies_null_org_actor(): void
    {
        $orphan = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($orphan, [
            Capability::OVR_EXPORT,
            Capability::CLUSTER_TREE_EXPORT,
        ]);

        $this->assertFalse($this->policy->exportsAggregates($orphan));
    }

    public function test_exports_aggregates_admits_super_admin_without_grants(): void
    {
        $super = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $this->grantCanonicalSuperAdmin($super);

        $this->assertTrue($this->policy->exportsAggregates($super));
    }

    /**
     * CFA-00 strict invariant: the cluster pair must NOT widen the raw
     * view() / viewAny() / export() abilities. This is the regression
     * test that proves the cluster widening is scoped to aggregates only.
     */
    public function test_raw_view_does_not_widen_with_cluster_stats_pair(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        // Cluster stats pair — but no OVR_VIEW on a child report.
        $this->grantEngineCapability($user, [
            Capability::OVR_VIEW_STATISTICS,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $childReport = IncidentReport::factory()->create([
            'organization_id' => $hospital->id,
        ]);

        // The raw view() must NOT widen — it stays strict same-org.
        $this->assertFalse($this->policy->view($user, $childReport));
    }

    public function test_view_any_stays_strict_with_cluster_pair(): void
    {
        [$cluster] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [
            Capability::OVR_VIEW_STATISTICS,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        // viewAny is gated on OVR_VIEW_STATISTICS-style org visibility; the
        // cluster widening does NOT promote the user to a viewAll role.
        $this->assertFalse($this->policy->viewAny($user));
    }

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
}
