<?php

namespace Tests\Feature\OVR;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\OVR\Models\IncidentReport;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * ClusterTreeOvrStatsTest - Phase CFA-09: HTTP-level cluster aggregate reporting.
 *
 * Exercises the new GET /api/ovr/incidents/cluster-stats endpoint. Asserts:
 *   1) cluster admin (OVR_VIEW_STATISTICS + CLUSTER_TREE_VIEW) sees aggregates
 *      across descendant orgs.
 *   2) non-cluster admin (only OVR_VIEW_STATISTICS) endpoint stays strict same-org.
 *   3) sibling cluster user denied descendant counts from sibling subtree.
 *   4) child user denied parent cluster counts.
 *   5) null-org user gets 403 (fail-closed).
 *   6) super_admin sees all aggregates.
 *   7) confidential reports NEVER surface in the response.
 *   8) the response contains aggregate-only fields — NO row-level data.
 */
class ClusterTreeOvrStatsTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_cluster_admin_with_stats_pair_sees_descendant_org_aggregates(): void
    {
        [$cluster, $hospital, $other] = $this->makeClusterTree();

        $admin = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($admin, [
            Capability::OVR_VIEW_STATISTICS,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        // Cluster has 2 reports, hospital has 3, sibling has 5.
        $this->makeReports($cluster, 2, 'CL', false);
        $this->makeReports($hospital, 3, 'HO', false);
        $this->makeReports($other, 5, 'SI', false);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/ovr/incidents/cluster-stats');

        $response->assertOk();
        $response->assertJsonPath('total', 5); // cluster + hospital (excludes sibling)
        $response->assertJsonPath('scope.mode', 'cluster_aggregate');
    }

    public function test_cluster_admin_aggregates_segregated_by_org(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $admin = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($admin, [
            Capability::OVR_VIEW_STATISTICS,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $this->makeReports($cluster, 2, 'CL', false);
        $this->makeReports($hospital, 3, 'HO', false);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/ovr/incidents/cluster-stats');

        $response->assertOk();
        $payload = $response->json('per_org');
        $this->assertIsArray($payload);
        $this->assertCount(2, $payload, 'per_org must contain one row per visible organization');

        $clusterRow = collect($payload)->firstWhere('organization_id', $cluster->id);
        $hospitalRow = collect($payload)->firstWhere('organization_id', $hospital->id);

        $this->assertNotNull($clusterRow);
        $this->assertNotNull($hospitalRow);
        $this->assertSame(2, $clusterRow['total']);
        $this->assertSame(3, $hospitalRow['total']);
    }

    public function test_non_cluster_admin_endpoint_stays_strict_same_org(): void
    {
        // A user with OVR_VIEW_STATISTICS only (no CLUSTER_TREE_VIEW) must see
        // only their own org's stats — the cluster widening is off.
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, Capability::OVR_VIEW_STATISTICS);

        $this->makeReports($cluster, 2, 'CL', false);
        $this->makeReports($hospital, 3, 'HO', false);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/ovr/incidents/cluster-stats');

        $response->assertOk();
        $response->assertJsonPath('total', 2, 'without CLUSTER_TREE_VIEW, endpoint stays strict same-org');
    }

    public function test_sibling_cluster_excluded_from_aggregates(): void
    {
        [$clusterA, $hospitalA] = $this->makeClusterTree('cluster A', 'hospital A');
        [, $hospitalB] = $this->makeClusterTree('cluster B', 'hospital B');

        $userA = User::factory()->create([
            'organization_id' => $clusterA->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($userA, [
            Capability::OVR_VIEW_STATISTICS,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $this->makeReports($clusterA, 2, 'CL-A', false);
        $this->makeReports($hospitalA, 3, 'HO-A', false);
        $this->makeReports($hospitalB, 5, 'HO-B', false);

        $response = $this->actingAs($userA, 'sanctum')
            ->getJson('/api/ovr/incidents/cluster-stats');

        $response->assertOk();
        $response->assertJsonPath('total', 5, 'A sees only A subtree (cluster + hospital A), NOT hospital B');

        $orgIds = array_column($response->json('per_org'), 'organization_id');
        $this->assertNotContains($hospitalB->id, $orgIds, 'sibling subtree MUST NOT appear');
    }

    public function test_child_user_does_not_see_parent_cluster_counts(): void
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

        $this->makeReports($cluster, 4, 'CL', false);
        $this->makeReports($hospital, 2, 'HO', false);

        $response = $this->actingAs($childUser, 'sanctum')
            ->getJson('/api/ovr/incidents/cluster-stats');

        $response->assertOk();
        $response->assertJsonPath('total', 2, 'one-directional: child does NOT see parent');

        $orgIds = array_column($response->json('per_org'), 'organization_id');
        $this->assertNotContains($cluster->id, $orgIds);
        $this->assertContains($hospital->id, $orgIds);
    }

    public function test_endpoint_returns_403_without_ovr_view_statistics(): void
    {
        [$cluster] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        // No grants at all — must be 403.
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/ovr/incidents/cluster-stats');

        $response->assertForbidden();
    }

    public function test_confidential_reports_excluded_from_aggregates(): void
    {
        // CFA-09 stop condition: confidential reports NEVER surface in the
        // aggregate even when the cluster actor holds both grants.
        [$cluster, $hospital] = $this->makeClusterTree();

        $admin = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($admin, [
            Capability::OVR_VIEW_STATISTICS,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $this->makeReports($cluster, 2, 'CL', false);
        $this->makeReports($hospital, 3, 'HO', false);
        $this->makeReports($hospital, 2, 'HC', true); // 2 confidential in hospital

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/ovr/incidents/cluster-stats');

        $response->assertOk();
        // cluster (2) + hospital normal (3) = 5. Hospital confidential (2) EXCLUDED.
        $response->assertJsonPath('total', 5);

        // Per-org check: hospital row must reflect 3, not 5.
        $payload = $response->json('per_org');
        $hospitalRow = collect($payload)->firstWhere('organization_id', $hospital->id);
        $this->assertSame(3, $hospitalRow['total']);
    }

    public function test_response_does_not_contain_row_level_fields(): void
    {
        // The aggregate response shape MUST NOT include row-level data fields
        // that would leak individual incidents (e.g. report_number, patient_name).
        [$cluster] = $this->makeClusterTree();

        $admin = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($admin, [
            Capability::OVR_VIEW_STATISTICS,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $this->makeReports($cluster, 3, 'CL', false);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/ovr/incidents/cluster-stats');

        $response->assertOk();
        $payload = $response->json();
        $this->assertArrayNotHasKey('reports', $payload);
        $this->assertArrayNotHasKey('incidents', $payload);
        $this->assertArrayNotHasKey('data', $payload);
        $this->assertArrayNotHasKey('rows', $payload);

        // Per-org rows must also be aggregate-only.
        foreach ($payload['per_org'] as $row) {
            $this->assertArrayNotHasKey('report_number', $row);
            $this->assertArrayNotHasKey('patient_name', $row);
            $this->assertArrayNotHasKey('patient_file_number', $row);
            $this->assertArrayNotHasKey('reporter_name', $row);
            $this->assertArrayNotHasKey('incident_description', $row);
        }
    }

    public function test_super_admin_sees_all_aggregates(): void
    {
        [, $hospital] = $this->makeClusterTree();

        $super = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $super->assignRole('super_admin');

        $this->makeReports($hospital, 4, 'HO', false);

        $response = $this->actingAs($super, 'sanctum')
            ->getJson('/api/ovr/incidents/cluster-stats');

        $response->assertOk();
        $response->assertJsonPath('total', 4);
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

    private function makeReports(Organization $org, int $count, string $prefix, bool $confidential = false): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->makeReport($org, [
                'report_number' => "{$prefix}-".now()->timestamp."-{$i}-".random_int(100, 999),
                'is_confidential' => $confidential,
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
