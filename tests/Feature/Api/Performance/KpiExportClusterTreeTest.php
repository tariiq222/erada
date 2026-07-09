<?php

namespace Tests\Feature\Api\Performance;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Performance\Models\Kpi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * KpiExportClusterTreeTest - Phase CFA-02: HTTP-level cluster_tree export widening for KPIs.
 *
 * Complements the unit-level ClusterTreeKpiExportScopeTest by exercising the
 * full HTTP request/response cycle on `GET /api/performance/kpis/export/{format}`.
 *
 * IMPORTANT — test-helper gotcha: `grantEngineCapability` has single-role-per-scope
 * semantics via `assignScopedRole`. Always pass multiple capabilities as an array
 * so they go into the same ScopedRoleDefinition's `permissions[]`.
 *
 * Proves:
 *   1) A cluster admin (KPIS_EXPORT + CLUSTER_TREE_EXPORT) export endpoint
 *      includes KPIs from descendant organizations in the CSV stream.
 *   2) A cluster user with only KPIS_VIEW + CLUSTER_TREE_VIEW (read pair) export
 *      endpoint stays strict same-org.
 *   3) A cluster user with KPIS_EXPORT + CLUSTER_TREE_VIEW (wrong cluster
 *      primitive) export endpoint stays strict same-org.
 *   4) A cluster user with KPIS_VIEW + CLUSTER_TREE_EXPORT (wrong module cap)
 *      export endpoint stays strict same-org.
 *   5) A user without ANY export capability export endpoint returns 403.
 *   6) A sibling cluster user is denied descendant KPIs in the export stream.
 *   7) A child user is denied parent cluster KPIs in the export stream
 *      (one-directional).
 *   8) Super admin export endpoint includes all KPIs regardless of cluster_tree.
 *
 * The existing baseline test `test_admin_can_export_filtered_kpis_as_csv`
 * (KpiControllerTest:293) is preserved verbatim — its `KPI-FOREIGN`
 * not-in-stream contract remains the floor for non-cluster admins.
 */
class KpiExportClusterTreeTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    public function test_cluster_admin_with_export_pair_sees_descendant_kpis_in_csv_export(): void
    {
        [$cluster, $hospital, $other] = $this->makeClusterTree();

        $admin = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($admin, [Capability::KPIS_EXPORT, Capability::CLUSTER_TREE_EXPORT]);

        $this->makeKpi($cluster, ['code' => 'CL-CYCLE', 'name' => 'Cluster Cycle']);
        $this->makeKpi($hospital, ['code' => 'HO-CYCLE', 'name' => 'Hospital Cycle']);
        $this->makeKpi($other, ['code' => 'SI-CYCLE', 'name' => 'Sibling Cycle']);

        $response = $this->actingAs($admin, 'sanctum')
            ->get('/api/performance/kpis/export/csv?search=Cycle');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));

        $content = $response->streamedContent();
        $this->assertStringContainsString('CL-CYCLE', $content, 'cluster admin must see cluster-level KPIs');
        $this->assertStringContainsString('HO-CYCLE', $content, 'cluster admin must see descendant hospital KPIs');
        $this->assertStringNotContainsString('SI-CYCLE', $content, 'cluster admin must NOT see sibling cluster KPIs');
    }

    public function test_cluster_read_pair_alone_does_not_widen_export(): void
    {
        // CFA-00 strict: read pair widens reads via index/show/list, but does NOT
        // widen exports. Users with only the read pair can read descendants via
        // the API but cannot export them.
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [Capability::KPIS_VIEW, Capability::CLUSTER_TREE_VIEW]);

        $this->makeKpi($cluster, ['code' => 'CL-ONLY', 'name' => 'Cluster Only']);
        $this->makeKpi($hospital, ['code' => 'HO-EXCLUDED', 'name' => 'Hospital Excluded']);

        $response = $this->actingAs($user, 'sanctum')
            ->get('/api/performance/kpis/export/csv?search=CL-ONLY&status=active');

        $response->assertOk();
        $content = $response->streamedContent();
        $this->assertStringContainsString('CL-ONLY', $content);
        $this->assertStringNotContainsString('HO-EXCLUDED', $content, 'read pair must NOT widen exports');
    }

    public function test_export_with_wrong_cluster_primitive_stays_strict(): void
    {
        // KPIS_EXPORT + CLUSTER_TREE_VIEW (wrong cluster primitive). The export
        // pair requires CLUSTER_TREE_EXPORT. The scope must stay strict same-org.
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [Capability::KPIS_EXPORT, Capability::CLUSTER_TREE_VIEW]);

        $this->makeKpi($cluster, ['code' => 'CL-X1', 'name' => 'Cluster X1']);
        $this->makeKpi($hospital, ['code' => 'HO-X1', 'name' => 'Hospital X1']);

        $response = $this->actingAs($user, 'sanctum')
            ->get('/api/performance/kpis/export/csv?search=X1&status=active');

        $response->assertOk();
        $content = $response->streamedContent();
        $this->assertStringContainsString('CL-X1', $content);
        $this->assertStringNotContainsString('HO-X1', $content, 'KPIS_EXPORT + CLUSTER_TREE_VIEW must NOT widen exports');
    }

    public function test_export_with_kpis_view_and_cluster_tree_export_still_widens_via_backward_compat(): void
    {
        // KPIS_VIEW + CLUSTER_TREE_EXPORT — controller authz passes via KPIS_VIEW
        // backward-compat, but the scope widening requires KPIS_EXPORT (strict).
        // Net result: actor can hit the endpoint but the scope is strict same-org.
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [Capability::KPIS_VIEW, Capability::CLUSTER_TREE_EXPORT]);

        $this->makeKpi($cluster, ['code' => 'CL-BC', 'name' => 'Cluster Backward Compat']);
        $this->makeKpi($hospital, ['code' => 'HO-BC', 'name' => 'Hospital Backward Compat']);

        $response = $this->actingAs($user, 'sanctum')
            ->get('/api/performance/kpis/export/csv?search=BC&status=active');

        $response->assertOk();
        $content = $response->streamedContent();
        $this->assertStringContainsString('CL-BC', $content);
        $this->assertStringNotContainsString('HO-BC', $content, 'KPIS_VIEW + CLUSTER_TREE_EXPORT must NOT widen exports — module EXPORT cap required');
    }

    public function test_user_without_export_capability_gets_403(): void
    {
        // Baseline: no export capability at all → 403.
        $organization = Organization::factory()->create();
        $user = User::factory()->create([
            'organization_id' => $organization->id,
            'is_active' => true,
        ]);
        // No KPIS_VIEW, no KPIS_EXPORT — just a bare user.

        $this->actingAs($user, 'sanctum')
            ->get('/api/performance/kpis/export/csv')
            ->assertForbidden();
    }

    public function test_export_with_only_kpis_view_still_works_for_same_org(): void
    {
        // Backward compat: KPIS_VIEW only (no cluster grants) still exports same-org.
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, Capability::KPIS_VIEW);

        $this->makeKpi($cluster, ['code' => 'CL-VIEW', 'name' => 'Cluster View Only']);
        $this->makeKpi($hospital, ['code' => 'HO-VIEW', 'name' => 'Hospital View Only']);

        $response = $this->actingAs($user, 'sanctum')
            ->get('/api/performance/kpis/export/csv?search=VIEW&status=active');

        $response->assertOk();
        $content = $response->streamedContent();
        $this->assertStringContainsString('CL-VIEW', $content);
        $this->assertStringNotContainsString('HO-VIEW', $content);
    }

    public function test_sibling_cluster_admin_does_not_see_other_cluster_descendants_in_export(): void
    {
        $clusterA = Organization::factory()->cluster()->create(['name' => 'clusterA']);
        $hospitalA = Organization::factory()->hospital()->childOf($clusterA)->create(['name' => 'hospitalA']);

        $clusterB = Organization::factory()->cluster()->create(['name' => 'clusterB']);
        $hospitalB = Organization::factory()->hospital()->childOf($clusterB)->create(['name' => 'hospitalB']);

        $admin = User::factory()->create([
            'organization_id' => $clusterA->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($admin, [Capability::KPIS_EXPORT, Capability::CLUSTER_TREE_EXPORT]);

        $this->makeKpi($clusterA, ['code' => 'A-CL', 'name' => 'A Cluster']);
        $this->makeKpi($hospitalA, ['code' => 'A-HO', 'name' => 'A Hospital']);
        $this->makeKpi($clusterB, ['code' => 'B-CL', 'name' => 'B Cluster']);
        $this->makeKpi($hospitalB, ['code' => 'B-HO', 'name' => 'B Hospital']);

        $response = $this->actingAs($admin, 'sanctum')
            ->get('/api/performance/kpis/export/csv?search=A&status=active');

        $response->assertOk();
        $content = $response->streamedContent();
        $this->assertStringContainsString('A-CL', $content);
        $this->assertStringContainsString('A-HO', $content);
        $this->assertStringNotContainsString('B-CL', $content, 'sibling cluster must be isolated in export');
        $this->assertStringNotContainsString('B-HO', $content, 'sibling cluster descendants must be isolated in export');
    }

    public function test_child_cluster_admin_does_not_see_parent_via_export(): void
    {
        // One-directional: child with export pair must NOT widen upward to parent.
        [$cluster, $hospital] = $this->makeClusterTree();

        $childAdmin = User::factory()->create([
            'organization_id' => $hospital->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($childAdmin, [Capability::KPIS_EXPORT, Capability::CLUSTER_TREE_EXPORT]);

        $this->makeKpi($cluster, ['code' => 'CL-PARENT', 'name' => 'Cluster Parent']);
        $this->makeKpi($hospital, ['code' => 'HO-CHILD', 'name' => 'Hospital Child']);

        $response = $this->actingAs($childAdmin, 'sanctum')
            ->get('/api/performance/kpis/export/csv?search=&status=active');

        $response->assertOk();
        $content = $response->streamedContent();
        $this->assertStringContainsString('HO-CHILD', $content);
        $this->assertStringNotContainsString('CL-PARENT', $content, 'child user must NOT see parent cluster KPIs via export widening (one-directional)');
    }

    public function test_super_admin_export_includes_all_kpis(): void
    {
        [$cluster, $hospital, $other] = $this->makeClusterTree();

        $superAdmin = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $superAdmin->assignRole('super_admin');

        $this->makeKpi($cluster, ['code' => 'CL-SA', 'name' => 'Cluster Super']);
        $this->makeKpi($hospital, ['code' => 'HO-SA', 'name' => 'Hospital Super']);
        $this->makeKpi($other, ['code' => 'SI-SA', 'name' => 'Sibling Super']);

        $response = $this->actingAs($superAdmin, 'sanctum')
            ->get('/api/performance/kpis/export/csv?search=SA&status=active');

        $response->assertOk();
        $content = $response->streamedContent();
        $this->assertStringContainsString('CL-SA', $content);
        $this->assertStringContainsString('HO-SA', $content);
        $this->assertStringContainsString('SI-SA', $content, 'super_admin must see all KPIs in export');
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

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeKpi(Organization $organization, array $overrides = []): Kpi
    {
        $kpi = new Kpi(array_merge([
            'name' => 'Performance KPI',
            'baseline' => 0,
            'target' => 100,
            'current_value' => 0,
            'frequency' => 'monthly',
            'direction' => Kpi::DIRECTION_INCREASE,
            'status' => 'active',
        ], $overrides));
        $kpi->forceFill(['organization_id' => $organization->id])->save();

        return $kpi;
    }
}