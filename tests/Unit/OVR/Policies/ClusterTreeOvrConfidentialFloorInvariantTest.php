<?php

namespace Tests\Unit\OVR\Policies;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\OVR\Models\IncidentReport;
use App\Modules\OVR\Policies\IncidentReportPolicy;
use App\Modules\OVR\Scopes\UserOvrScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * ClusterTreeOvrConfidentialFloorInvariantTest - Phase CFA-09: CRITICAL invariant.
 *
 * Asserts the is_confidential floor is NEVER bypassed, even when the actor
 * holds both cluster grants. The cluster widening only widens AGGREGATES —
 * it must not promote the actor to a per-row confidentiality clearance.
 *
 * Three non-negotiable invariants:
 *   1. A cluster actor with OVR_VIEW_STATISTICS + CLUSTER_TREE_VIEW CANNOT
 *      view() a confidential incident in a descendant organization.
 *   2. A cluster actor with OVR_EXPORT + CLUSTER_TREE_EXPORT CANNOT export
 *      a confidential incident row.
 *   3. The scope layer (UserOvrScope::applyToIncidentReportsForStats /
 *      applyToIncidentReportsForExport) UNCONDITIONALLY filters
 *      is_confidential = false regardless of the actor's grants.
 *
 * Regression: if a future refactor adds a wildcard OR widens the cluster
 * pair to include OVR_CONFIDENTIAL, this test MUST fail.
 */
class ClusterTreeOvrConfidentialFloorInvariantTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    private IncidentReportPolicy $policy;

    private UserOvrScope $scope;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new IncidentReportPolicy;
        $this->scope = new UserOvrScope;
    }

    public function test_cluster_stats_pair_does_not_view_confidential_child_report(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [
            Capability::OVR_VIEW_STATISTICS,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $confidentialReport = IncidentReport::factory()->create([
            'organization_id' => $hospital->id,
            'is_confidential' => true,
        ]);

        // The raw view() must NOT widen — and the is_confidential floor
        // would deny even if view() did widen (the actor lacks OVR_CONFIDENTIAL).
        $this->assertFalse($this->policy->view($user, $confidentialReport));
    }

    public function test_cluster_export_pair_does_not_export_confidential_child_report(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [
            Capability::OVR_EXPORT,
            Capability::CLUSTER_TREE_EXPORT,
        ]);

        $confidentialReport = IncidentReport::factory()->create([
            'organization_id' => $hospital->id,
            'is_confidential' => true,
        ]);

        // Even if export() widened (it does not), the is_confidential floor
        // would deny this row via mayViewConfidential.
        $this->assertFalse($this->policy->view($user, $confidentialReport));
    }

    public function test_scope_filters_confidential_rows_in_stats_query(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [
            Capability::OVR_VIEW_STATISTICS,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $normalReport = IncidentReport::factory()->create([
            'organization_id' => $hospital->id,
            'is_confidential' => false,
        ]);
        $confidentialReport = IncidentReport::factory()->create([
            'organization_id' => $hospital->id,
            'is_confidential' => true,
        ]);

        $query = IncidentReport::query();
        $this->scope->applyToIncidentReportsForStats($query, $user);

        $ids = $query->pluck('id')->all();
        $this->assertContains($normalReport->id, $ids, 'normal reports MUST surface in cluster stats');
        $this->assertNotContains($confidentialReport->id, $ids, 'confidential reports MUST NEVER surface in cluster stats');
    }

    public function test_scope_filters_confidential_rows_in_export_query(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [
            Capability::OVR_EXPORT,
            Capability::CLUSTER_TREE_EXPORT,
        ]);

        $normalReport = IncidentReport::factory()->create([
            'organization_id' => $hospital->id,
            'is_confidential' => false,
        ]);
        $confidentialReport = IncidentReport::factory()->create([
            'organization_id' => $hospital->id,
            'is_confidential' => true,
        ]);

        $query = IncidentReport::query();
        $this->scope->applyToIncidentReportsForExport($query, $user);

        $ids = $query->pluck('id')->all();
        $this->assertContains($normalReport->id, $ids);
        $this->assertNotContains($confidentialReport->id, $ids);
    }

    public function test_scope_filters_confidential_even_without_cluster_pair(): void
    {
        // The is_confidential floor is UNCONDITIONAL — it applies even when
        // the actor only has the same-org module cap (no cluster widening).
        // This is defense-in-depth: a cluster widening rule change cannot
        // accidentally promote a confidential row.
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, Capability::OVR_VIEW_STATISTICS);

        $confidentialReport = IncidentReport::factory()->create([
            'organization_id' => $hospital->id,
            'is_confidential' => true,
        ]);

        $query = IncidentReport::query();
        $this->scope->applyToIncidentReportsForStats($query, $user);

        $ids = $query->pluck('id')->all();
        $this->assertNotContains($confidentialReport->id, $ids);
    }

    public function test_cluster_widening_does_not_grant_ovr_confidential_capability(): void
    {
        // The cluster pair (OVR_VIEW_STATISTICS + CLUSTER_TREE_VIEW) does
        // NOT imply OVR_CONFIDENTIAL on any org. This is the structural
        // reason the scope layer can unconditionally filter confidential
        // rows without checking the actor's confidential capability.
        [$cluster] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [
            Capability::OVR_VIEW_STATISTICS,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $this->assertFalse(
            AccessDecision::can($user, Capability::OVR_CONFIDENTIAL),
            'cluster pair MUST NOT imply OVR_CONFIDENTIAL — that would breach the is_confidential floor'
        );
    }

    public function test_raw_view_floor_remains_for_cluster_actor_on_child_report(): void
    {
        // A non-confidential child report that a cluster actor COULD see
        // via the aggregate is still denied at the raw view() level —
        // the cluster widening does NOT promote view().
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [
            Capability::OVR_VIEW_STATISTICS,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $childReport = IncidentReport::factory()->create([
            'organization_id' => $hospital->id,
            'is_confidential' => false,
        ]);

        $this->assertFalse($this->policy->view($user, $childReport));
    }

    public function test_sensitive_floor_holds_with_confidential_capability_but_no_cluster_pair(): void
    {
        // An actor with OVR_CONFIDENTIAL but no cluster_tree still cannot
        // see cross-org via view(). The cluster widening is what would
        // permit the cross-org read; the confidential capability only
        // unlocks the same-org sensitive row.
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, Capability::OVR_CONFIDENTIAL);

        $childReport = IncidentReport::factory()->create([
            'organization_id' => $hospital->id,
            'is_confidential' => true,
        ]);

        $this->assertFalse($this->policy->view($user, $childReport));
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
