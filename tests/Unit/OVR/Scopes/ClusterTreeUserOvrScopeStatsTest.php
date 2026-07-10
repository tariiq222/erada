<?php

namespace Tests\Unit\OVR\Scopes;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\OVR\Models\IncidentReport;
use App\Modules\OVR\Scopes\UserOvrScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * ClusterTreeUserOvrScopeStatsTest - Phase CFA-09: cluster aggregate scope widening.
 *
 * Mirrors ClusterTreeUserKpiScopeTest (Phase 9-D-D1a) and
 * ClusterTreeKpiExportScopeTest (Phase CFA-02) for the OVR stats + export
 * widening pairs.
 *
 * Proves:
 *   1) cluster user with OVR_VIEW_STATISTICS + CLUSTER_TREE_VIEW sees descendant orgs in stats scope.
 *   2) cluster user with OVR_EXPORT + CLUSTER_TREE_EXPORT sees descendant orgs in export scope.
 *   3) cluster user missing either grant on the stats pair stays strict same-org.
 *   4) cluster user missing either grant on the export pair stays strict same-org.
 *   5) cluster user with only the read pair does NOT widen the export scope (and vice versa).
 *   6) sibling cluster is isolated (one-directional).
 *   7) child user does NOT see parent cluster via the widening (one-directional).
 *   8) null-org user fail-closed (0 rows) on both scopes.
 *   9) super_admin sees all reports regardless of grants.
 *  10) confidential reports are UNCONDITIONALLY excluded from the scope result.
 */
class ClusterTreeUserOvrScopeStatsTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    private UserOvrScope $scope;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scope = new UserOvrScope;
    }

    public function test_stats_scope_widens_for_cluster_user_with_stats_pair(): void
    {
        [$cluster, $hospital, $other] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [
            Capability::OVR_VIEW_STATISTICS,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $this->makeReports($cluster, 2, 'CL');
        $this->makeReports($hospital, 3, 'HO');
        $this->makeReports($other, 5, 'SI');

        $query = IncidentReport::query();
        $this->scope->applyToIncidentReportsForStats($query, $user);

        $this->assertSame(5, (clone $query)->count(), 'stats scope must see cluster + hospital (descendants), exclude sibling');
    }

    public function test_stats_scope_stays_strict_when_only_ovr_view_statistics_granted(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, Capability::OVR_VIEW_STATISTICS);

        $this->makeReports($cluster, 2, 'CL');
        $this->makeReports($hospital, 3, 'HO');

        $query = IncidentReport::query();
        $this->scope->applyToIncidentReportsForStats($query, $user);

        $this->assertSame(2, (clone $query)->count(), 'OVR_VIEW_STATISTICS alone must NOT widen stats scope (CLUSTER_TREE_VIEW required)');
    }

    public function test_stats_scope_stays_strict_when_only_cluster_tree_view_granted(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, Capability::CLUSTER_TREE_VIEW);

        $this->makeReports($cluster, 2, 'CL');
        $this->makeReports($hospital, 3, 'HO');

        $query = IncidentReport::query();
        $this->scope->applyToIncidentReportsForStats($query, $user);

        $this->assertSame(2, (clone $query)->count(), 'CLUSTER_TREE_VIEW alone must NOT widen stats scope (OVR_VIEW_STATISTICS required)');
    }

    public function test_stats_pair_does_not_widen_export_scope(): void
    {
        // A user with only the stats pair (OVR_VIEW_STATISTICS + CLUSTER_TREE_VIEW)
        // can read aggregate stats but cannot export them.
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [
            Capability::OVR_VIEW_STATISTICS,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $this->makeReports($cluster, 2, 'CL');
        $this->makeReports($hospital, 3, 'HO');

        $query = IncidentReport::query();
        $this->scope->applyToIncidentReportsForExport($query, $user);

        $this->assertSame(2, (clone $query)->count(), 'stats pair must NOT widen the export scope');
    }

    public function test_export_pair_does_not_widen_stats_scope(): void
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

        $this->makeReports($cluster, 2, 'CL');
        $this->makeReports($hospital, 3, 'HO');

        $query = IncidentReport::query();
        $this->scope->applyToIncidentReportsForStats($query, $user);

        $this->assertSame(2, (clone $query)->count(), 'export pair alone must NOT widen the stats scope');
    }

    public function test_export_scope_widens_for_cluster_user_with_export_pair(): void
    {
        [$cluster, $hospital, $other] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [
            Capability::OVR_EXPORT,
            Capability::CLUSTER_TREE_EXPORT,
        ]);

        $this->makeReports($cluster, 2, 'CL');
        $this->makeReports($hospital, 3, 'HO');
        $this->makeReports($other, 5, 'SI');

        $query = IncidentReport::query();
        $this->scope->applyToIncidentReportsForExport($query, $user);

        $this->assertSame(5, (clone $query)->count(), 'export scope must see cluster + hospital (descendants), exclude sibling');
    }

    public function test_export_scope_stays_strict_when_only_ovr_export_granted(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, Capability::OVR_EXPORT);

        $this->makeReports($cluster, 2, 'CL');
        $this->makeReports($hospital, 3, 'HO');

        $query = IncidentReport::query();
        $this->scope->applyToIncidentReportsForExport($query, $user);

        $this->assertSame(2, (clone $query)->count(), 'OVR_EXPORT alone must NOT widen export scope (CLUSTER_TREE_EXPORT required)');
    }

    public function test_sibling_cluster_is_isolated_in_stats_scope(): void
    {
        [$clusterA, $hospitalA] = $this->makeClusterTree('cluster A', 'hospital A');
        [$clusterB, $hospitalB] = $this->makeClusterTree('cluster B', 'hospital B');

        $userA = User::factory()->create([
            'organization_id' => $clusterA->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($userA, [
            Capability::OVR_VIEW_STATISTICS,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $this->makeReports($clusterA, 2, 'CL-A');
        $this->makeReports($hospitalA, 3, 'HO-A');
        $this->makeReports($clusterB, 4, 'CL-B');
        $this->makeReports($hospitalB, 5, 'HO-B');

        $query = IncidentReport::query();
        $this->scope->applyToIncidentReportsForStats($query, $userA);

        // A sees A's subtree (5 rows). Does NOT see B's subtree (9 rows).
        $this->assertSame(5, (clone $query)->count());
    }

    public function test_child_user_cannot_see_parent_cluster_in_stats_scope(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $childUser = User::factory()->create([
            'organization_id' => $hospital->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($childUser, [
            Capability::OVR_VIEW_STATISTICS,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $this->makeReports($cluster, 4, 'CL');
        $this->makeReports($hospital, 2, 'HO');

        $query = IncidentReport::query();
        $this->scope->applyToIncidentReportsForStats($query, $childUser);

        // One-directional: child does not see parent.
        $this->assertSame(2, (clone $query)->count());
    }

    public function test_null_org_user_sees_zero_rows_in_stats_scope(): void
    {
        $orphan = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($orphan, [
            Capability::OVR_VIEW_STATISTICS,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $this->makeReports(Organization::factory()->create(['name' => 'lone']), 3, 'LO');

        $query = IncidentReport::query();
        $this->scope->applyToIncidentReportsForStats($query, $orphan);

        $this->assertSame(0, (clone $query)->count(), 'null-org actor ⇒ fail-closed');
    }

    public function test_null_org_user_sees_zero_rows_in_export_scope(): void
    {
        $orphan = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($orphan, [
            Capability::OVR_EXPORT,
            Capability::CLUSTER_TREE_EXPORT,
        ]);

        $this->makeReports(Organization::factory()->create(['name' => 'lone']), 3, 'LO');

        $query = IncidentReport::query();
        $this->scope->applyToIncidentReportsForExport($query, $orphan);

        $this->assertSame(0, (clone $query)->count());
    }

    public function test_super_admin_sees_all_reports_in_stats_scope(): void
    {
        [, $hospital] = $this->makeClusterTree();

        $super = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $super->assignRole('super_admin');

        $this->makeReports($hospital, 3, 'HO');

        $query = IncidentReport::query();
        $this->scope->applyToIncidentReportsForStats($query, $super);

        $this->assertSame(3, (clone $query)->count(), 'super_admin sees all reports');
    }

    public function test_confidential_reports_are_excluded_from_stats_scope(): void
    {
        // The is_confidential floor is UNCONDITIONAL — confidential reports
        // never surface in the cluster aggregate.
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [
            Capability::OVR_VIEW_STATISTICS,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $normal = IncidentReport::factory()->create([
            'organization_id' => $hospital->id,
            'is_confidential' => false,
        ]);
        $confidential = IncidentReport::factory()->create([
            'organization_id' => $hospital->id,
            'is_confidential' => true,
        ]);

        $query = IncidentReport::query();
        $this->scope->applyToIncidentReportsForStats($query, $user);

        $ids = $query->pluck('id')->all();
        $this->assertContains($normal->id, $ids);
        $this->assertNotContains($confidential->id, $ids);
    }

    public function test_unrelated_org_outside_cluster_excluded(): void
    {
        [$cluster, , $other] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [
            Capability::OVR_VIEW_STATISTICS,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $this->makeReports($cluster, 2, 'CL');
        $this->makeReports($other, 5, 'UN');

        $query = IncidentReport::query();
        $this->scope->applyToIncidentReportsForStats($query, $user);

        $this->assertSame(2, (clone $query)->count(), 'unrelated org (no ancestor relationship) MUST be excluded');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeReport(Organization $org, array $overrides = []): IncidentReport
    {
        return IncidentReport::factory()->create(array_merge([
            'organization_id' => $org->id,
            'is_confidential' => false,
        ], $overrides));
    }

    private function makeReports(Organization $org, int $count, string $prefix): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->makeReport($org, [
                'report_number' => "{$prefix}-".now()->timestamp."-{$i}",
            ]);
        }
    }

    /**
     * @return array{0: Organization, 1: Organization, 2: Organization}
     */
    private function makeClusterTree(string $clusterName = 'cluster', string $hospitalName = 'hospital'): array
    {
        $cluster = Organization::factory()->cluster()->create(['name' => $clusterName]);
        $hospital = Organization::factory()->hospital()->childOf($cluster)->create(['name' => $hospitalName]);
        $other = Organization::factory()->create(['name' => 'sibling of '.$hospitalName]);

        return [$cluster, $hospital, $other];
    }
}
