<?php

namespace Tests\Unit\Performance\Scopes;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Performance\Models\Kpi;
use App\Modules\Performance\Scopes\UserKpiScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * ClusterTreeKpiExportScopeTest - Phase CFA-02: cluster_tree export widening at the Scope layer.
 *
 * Mirrors ClusterTreeUserKpiScopeTest (9-D-D1a) but for the EXPORT pair:
 *   KPIS_EXPORT + CLUSTER_TREE_EXPORT on actor.organization_id widens the
 *   filter to descendant organizations when purpose='export'.
 *
 * IMPORTANT — test-helper gotcha (also documented in progress.txt):
 * `grantEngineCapability` builds one canonical role per scope in this fixture.
 * Always pass multiple capabilities as an array.
 *
 * Proves:
 *   1) cluster user with KPIS_EXPORT + CLUSTER_TREE_EXPORT sees descendant KPIs in the export scope.
 *   2) cluster user with KPIS_EXPORT only does NOT see descendant KPIs (both grants required).
 *   3) cluster user with CLUSTER_TREE_EXPORT only does NOT see descendant KPIs.
 *   4) cluster user with KPIS_VIEW + CLUSTER_TREE_VIEW (read pair) does NOT widen the export scope.
 *   5) cluster user with KPIS_EXPORT + CLUSTER_TREE_EXPORT does NOT widen the default (view) scope.
 *   6) sibling cluster is isolated.
 *   7) child user does NOT see parent cluster via the export widening (one-directional).
 *   8) null-org user fail-closed (0 rows) on the export scope.
 *   9) super_admin sees all KPIs in export regardless of grants.
 */
class ClusterTreeKpiExportScopeTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    private UserKpiScope $scope;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scope = new UserKpiScope;
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

    public function test_export_scope_widens_for_cluster_user_with_export_pair(): void
    {
        [$cluster, $hospital, $other] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        // Pass both capabilities as an array so they share one canonical role.
        $this->grantEngineCapability($user, [Capability::KPIS_EXPORT, Capability::CLUSTER_TREE_EXPORT]);

        $this->makeKpis($cluster, 2, 'CL-');
        $this->makeKpis($hospital, 3, 'HO-');
        $this->makeKpis($other, 5, 'SI-');

        $query = Kpi::query();
        $this->scope->applyToKpis($query, $user, 'export');

        $this->assertSame(5, (clone $query)->count(), 'export scope must see cluster + hospital (descendants), exclude sibling');
    }

    public function test_export_scope_stays_strict_when_only_kpis_export_granted(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, Capability::KPIS_EXPORT);

        $this->makeKpis($cluster, 2, 'CL-');
        $this->makeKpis($hospital, 3, 'HO-');

        $query = Kpi::query();
        $this->scope->applyToKpis($query, $user, 'export');

        $this->assertSame(2, (clone $query)->count(), 'KPIS_EXPORT alone must NOT widen export scope (CLUSTER_TREE_EXPORT required)');
    }

    public function test_export_scope_stays_strict_when_only_cluster_tree_export_granted(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, Capability::CLUSTER_TREE_EXPORT);

        $this->makeKpis($cluster, 2, 'CL-');
        $this->makeKpis($hospital, 3, 'HO-');

        $query = Kpi::query();
        $this->scope->applyToKpis($query, $user, 'export');

        $this->assertSame(2, (clone $query)->count(), 'CLUSTER_TREE_EXPORT alone must NOT widen export scope (KPIS_EXPORT required)');
    }

    public function test_read_pair_does_not_widen_export_scope(): void
    {
        // CFA-00 strict contract: the read pair (KPIS_VIEW + CLUSTER_TREE_VIEW) widens
        // reads via the view-purpose path, but does NOT widen exports. Only the
        // export pair (KPIS_EXPORT + CLUSTER_TREE_EXPORT) widens exports.
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [Capability::KPIS_VIEW, Capability::CLUSTER_TREE_VIEW]);

        $this->makeKpis($cluster, 2, 'CL-');
        $this->makeKpis($hospital, 3, 'HO-');

        $query = Kpi::query();
        $this->scope->applyToKpis($query, $user, 'export');

        $this->assertSame(2, (clone $query)->count(), 'KPIS_VIEW + CLUSTER_TREE_VIEW (read pair) must NOT widen export scope');
    }

    public function test_export_pair_does_not_widen_view_scope(): void
    {
        // Inverse: the export pair widens exports via the export-purpose path,
        // but does NOT widen reads via the default view purpose.
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [Capability::KPIS_EXPORT, Capability::CLUSTER_TREE_EXPORT]);

        $this->makeKpis($cluster, 2, 'CL-');
        $this->makeKpis($hospital, 3, 'HO-');

        // Default purpose (read path) — export pair must NOT widen.
        $query = Kpi::query();
        $this->scope->applyToKpis($query, $user);

        $this->assertSame(2, (clone $query)->count(), 'KPIS_EXPORT + CLUSTER_TREE_EXPORT must NOT widen default view scope (purpose=null)');
    }

    public function test_export_scope_isolates_sibling_cluster(): void
    {
        $clusterA = Organization::factory()->cluster()->create(['name' => 'clusterA']);
        $hospitalA = Organization::factory()->hospital()->childOf($clusterA)->create(['name' => 'hospitalA']);

        $clusterB = Organization::factory()->cluster()->create(['name' => 'clusterB']);
        $hospitalB = Organization::factory()->hospital()->childOf($clusterB)->create(['name' => 'hospitalB']);

        $user = User::factory()->create([
            'organization_id' => $clusterA->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [Capability::KPIS_EXPORT, Capability::CLUSTER_TREE_EXPORT]);

        $this->makeKpis($clusterA, 2, 'A-CL-');
        $this->makeKpis($hospitalA, 3, 'A-HO-');
        $this->makeKpis($clusterB, 1, 'B-CL-');
        $this->makeKpis($hospitalB, 4, 'B-HO-');

        $query = Kpi::query();
        $this->scope->applyToKpis($query, $user, 'export');

        $this->assertSame(5, (clone $query)->count(), 'export scope sees only own tree (clusterA + hospitalA)');
    }

    public function test_export_scope_is_one_directional_child_cannot_see_parent(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        // Child user (hospital) with the export pair — must NOT widen to parent.
        $user = User::factory()->create([
            'organization_id' => $hospital->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [Capability::KPIS_EXPORT, Capability::CLUSTER_TREE_EXPORT]);

        $this->makeKpis($cluster, 2, 'CL-');
        $this->makeKpis($hospital, 3, 'HO-');

        $query = Kpi::query();
        $this->scope->applyToKpis($query, $user, 'export');

        $this->assertSame(3, (clone $query)->count(), 'child user must NOT see parent cluster KPIs via export widening (one-directional)');
    }

    public function test_export_scope_fail_closed_for_null_org_user_with_grants(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [Capability::KPIS_EXPORT, Capability::CLUSTER_TREE_EXPORT]);

        $this->makeKpis($cluster, 2, 'CL-');
        $this->makeKpis($hospital, 3, 'HO-');

        $query = Kpi::query();
        $this->scope->applyToKpis($query, $user, 'export');

        $this->assertSame(0, (clone $query)->count(), 'null-org user must fail-closed on export scope (0 rows)');
    }

    public function test_export_scope_super_admin_sees_all(): void
    {
        [$cluster, $hospital, $other] = $this->makeClusterTree();

        $superAdmin = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $this->grantCanonicalSuperAdmin($superAdmin);

        $this->makeKpis($cluster, 2, 'CL-');
        $this->makeKpis($hospital, 3, 'HO-');
        $this->makeKpis($other, 5, 'SI-');

        $query = Kpi::query();
        $this->scope->applyToKpis($query, $superAdmin, 'export');

        $this->assertSame(10, (clone $query)->count(), 'super_admin must see all KPIs in export regardless of cluster_tree grants');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeKpis(Organization $org, int $count, string $codePrefix, array $overrides = []): void
    {
        Kpi::factory()->count($count)->create(array_merge([
            'organization_id' => $org->id,
            'status' => 'active',
        ], $overrides))->each(function (Kpi $kpi, int $i) use ($codePrefix): void {
            $kpi->forceFill(['code' => $codePrefix.str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT)])->save();
        });
    }
}
