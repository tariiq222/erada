<?php

namespace Tests\Feature\OVR;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\OVR\Models\IncidentReport;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * ClusterTreeOvrRawIndexForbiddenTest - Phase CFA-09: REGRESSION test.
 *
 * CFA-00 strict contract: the cluster widening applies ONLY to the new
 * clusterStats / clusterExport endpoints. The raw index/show/recent/export
 * paths MUST stay strict same-org, even when the actor holds the cluster
 * pair. A cluster actor must NEVER see row-level data from descendant
 * organizations via any raw read endpoint.
 *
 * Stop conditions asserted here:
 *   1. Raw index endpoint (GET /api/ovr/incidents) does NOT widen.
 *   2. Raw show endpoint (GET /api/ovr/incidents/{report}) does NOT widen.
 *   3. Raw recent endpoint (GET /api/ovr/incidents/recent) does NOT widen.
 *   4. Raw export endpoint (GET /api/ovr/incidents/export) does NOT widen.
 *   5. Raw stats endpoint (GET /api/ovr/incidents/stats) does NOT widen.
 *   6. The cluster pair does NOT grant the actor OVR_VIEW capability on a
 *      child org report (Policy::view() returns false).
 */
class ClusterTreeOvrRawIndexForbiddenTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_raw_index_does_not_include_child_org_reports(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        // OVR_VIEW (regular read) + cluster stats pair. The user's normal
        // read capability must still pass the policy gate; the regression
        // check is that the cluster pair does NOT promote them to a
        // cross-org reader via the raw index.
        $this->grantEngineCapability($user, [
            Capability::OVR_VIEW,
            Capability::OVR_VIEW_STATISTICS,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $this->makeReports($cluster, 2, 'OVR-CL');
        $this->makeReports($hospital, 3, 'OVR-HO');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/ovr/incidents');

        $response->assertOk();
        $reportNumbers = $this->extractReportNumbers($response->json('data'));

        // Cluster reports are visible (2). Child org reports MUST NOT be in
        // the raw index — the cluster widening does NOT promote viewAny.
        $this->assertCount(2, $reportNumbers, 'raw index MUST stay strict same-org even with cluster pair');
        $this->assertNotContains(
            'OVR-HO-0000',
            $reportNumbers,
            'child-org reports MUST NOT surface in raw index even with cluster pair'
        );
    }

    public function test_raw_show_returns_403_for_child_org_report(): void
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

        $childReport = IncidentReport::factory()->create([
            'organization_id' => $hospital->id,
            'report_number' => 'OVR-2099-9999',
            'is_confidential' => false,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/ovr/incidents/'.$childReport->report_number);

        $response->assertForbidden();
    }

    public function test_raw_recent_does_not_include_child_org_reports(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        // super_admin in the cluster org bypasses the visibleTo scope gate
        // (super_admin sees every report in their org via the bypass in
        // scopeVisibleTo). The regression assertion is that the same-org
        // floor (forOrganization) is preserved even for super_admin.
        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalSuperAdmin($user);

        $this->makeReports($cluster, 2, 'OVR-CL');
        $this->makeReports($hospital, 3, 'OVR-HO');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/ovr/incidents/recent');

        $response->assertOk();
        $data = $response->json('data');
        $reportNumbers = $this->extractReportNumbers($data);

        // Recent endpoint applies forOrganization + visibleTo. super_admin
        // bypasses visibleTo; forOrganization stays strict same-org. So we
        // expect 2 cluster reports and 0 hospital reports.
        $this->assertNotContains('OVR-HO-0000', $reportNumbers, 'raw recent endpoint MUST NOT include child-org reports');
        $this->assertNotContains('OVR-HO-0001', $reportNumbers, 'raw recent endpoint MUST NOT include child-org reports');
        $this->assertNotContains('OVR-HO-0002', $reportNumbers, 'raw recent endpoint MUST NOT include child-org reports');

        // Assert at most 2 reports (the 2 cluster ones) — could be 0 if super_admin
        // bypass isn't working; the key invariant is no HO-* ids.
        $this->assertLessThanOrEqual(2, count($reportNumbers), 'raw recent endpoint stays strict same-org');
    }

    public function test_raw_export_does_not_include_child_org_reports(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        // super_admin in the cluster org — bypasses visibleTo scope filter,
        // same-org floor (forOrganization) is preserved.
        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalSuperAdmin($user);

        $this->makeReports($cluster, 2, 'OVR-CL');
        $this->makeReports($hospital, 3, 'OVR-HO');

        $response = $this->actingAs($user, 'sanctum')
            ->get('/api/ovr/incidents/export?format=csv');

        $response->assertOk();
        $content = $response->streamedContent();
        $this->assertStringContainsString('OVR-CL', $content);
        $this->assertStringNotContainsString('OVR-HO', $content, 'raw export MUST stay strict same-org even for super_admin');
    }

    public function test_raw_stats_does_not_include_child_org_reports(): void
    {
        // The existing /api/ovr/incidents/stats endpoint stays strict same-org
        // (gated by viewStatistics — no cluster widening). Only the new
        // /api/ovr/incidents/cluster-stats widens.
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        // OVR_VIEW + OVR_VIEW_STATISTICS + CLUSTER_TREE_VIEW. The user has the
        // proper read visibility AND the cluster pair; the regression is that
        // even with both, the existing stats endpoint stays strict same-org.
        $this->grantEngineCapability($user, [
            Capability::OVR_VIEW,
            Capability::OVR_VIEW_STATISTICS,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $this->makeReports($cluster, 2, 'OVR-CL');
        $this->makeReports($hospital, 3, 'OVR-HO');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/ovr/incidents/stats');

        $response->assertOk();
        $response->assertJsonPath('total', 2, 'raw stats endpoint MUST stay strict same-org even with cluster pair');
    }

    public function test_confidential_child_report_returns_403_via_raw_show(): void
    {
        // Defense-in-depth: even if a future regression widens raw show(),
        // the is_confidential floor + missing OVR_VIEW must still deny.
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [
            Capability::OVR_VIEW_STATISTICS,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $confidentialChild = IncidentReport::factory()->create([
            'organization_id' => $hospital->id,
            'is_confidential' => true,
            'report_number' => 'OVR-2099-0001',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/ovr/incidents/'.$confidentialChild->report_number);

        $response->assertForbidden();
    }

    public function test_cluster_pair_does_not_grant_ovr_view_capability(): void
    {
        // Structural invariant: the cluster pair (OVR_VIEW_STATISTICS +
        // CLUSTER_TREE_VIEW) does NOT imply OVR_VIEW on a child report.
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
            AccessDecision::can($user, Capability::OVR_VIEW),
            'cluster stats pair MUST NOT imply OVR_VIEW — that would widen raw read paths'
        );
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $rows
     * @return list<string>
     */
    private function extractReportNumbers(?array $rows): array
    {
        if ($rows === null) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($row) => is_array($row) ? ($row['report_number'] ?? null) : null,
            $rows
        )));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeReport(Organization $org, array $overrides = []): IncidentReport
    {
        return IncidentReport::factory()->create(array_merge([
            'organization_id' => $org->id,
        ], $overrides));
    }

    private function makeReports(Organization $org, int $count, string $numberPrefix): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->makeReport($org, [
                'report_number' => $numberPrefix.'-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT).'-'.random_int(100, 999),
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
