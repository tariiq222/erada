<?php

namespace Tests\Unit\Performance\Scopes;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Performance\Models\Kpi;
use App\Modules\Performance\Models\KpiLink;
use App\Modules\Performance\Models\KpiMeasurement;
use App\Modules\Performance\Scopes\UserKpiScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * ClusterTreeUserKpiScopeTest - Phase 9-D-D1a: cluster_tree read widening at the Scope layer.
 *
 * يثبت:
 *   1) cluster user مع KPIS_VIEW + CLUSTER_TREE_VIEW يرى KPIs في منظمته + descendants.
 *   2) cluster user مع KPIS_VIEW فقط لا يرى child org KPIs.
 *   3) cluster user مع CLUSTER_TREE_VIEW فقط لا يرى child org KPIs (يلزم الكلا القدرةَين).
 *   4) cluster user مع الكلا القدرةَين لا يرى sibling org KPIs.
 *   5) child user لا يرى parent cluster KPIs عبر cluster_tree (one-directional).
 *   6) null-org user لا يرى شيئاً (fail-closed).
 *   7) super_admin يرى الكل بغض النظر عن الـ grants.
 *   8) القياسات والروابط ترث توسيع الـ cluster_tree (descendants).
 *
 * يستخدم GrantsEngineCapability::grantEngineCapability() لإضافة Capability
 * على scoped role في user.organization_id.
 */
class ClusterTreeUserKpiScopeTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    private UserKpiScope $scope;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scope = new UserKpiScope;
    }

    public function test_cluster_user_with_both_grants_sees_own_and_descendant_kpis(): void
    {
        [$cluster, $hospital, $other] = $this->makeClusterTree();
        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [
            Capability::KPIS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        Kpi::factory()->count(2)->create(['organization_id' => $cluster->id]);
        Kpi::factory()->count(3)->create(['organization_id' => $hospital->id]);
        Kpi::factory()->count(5)->create(['organization_id' => $other->id]);

        $query = Kpi::query();
        $this->scope->applyToKpis($query, $user);

        // cluster (2) + hospital descendant (3) = 5. Sibling 'other' excluded.
        $this->assertSame(5, (clone $query)->count());
    }

    public function test_cluster_user_with_only_kpis_view_does_not_see_descendant_kpis(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();
        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, Capability::KPIS_VIEW);

        Kpi::factory()->count(2)->create(['organization_id' => $cluster->id]);
        Kpi::factory()->count(3)->create(['organization_id' => $hospital->id]);

        $query = Kpi::query();
        $this->scope->applyToKpis($query, $user);

        // بدون CLUSTER_TREE_VIEW ⇒ strict same-org ⇒ 2 فقط (الـ cluster نفسها).
        $this->assertSame(2, (clone $query)->count());
    }

    public function test_cluster_user_with_only_cluster_tree_view_does_not_see_descendant_kpis(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();
        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, Capability::CLUSTER_TREE_VIEW);

        Kpi::factory()->count(2)->create(['organization_id' => $cluster->id]);
        Kpi::factory()->count(3)->create(['organization_id' => $hospital->id]);

        $query = Kpi::query();
        $this->scope->applyToKpis($query, $user);

        // الـ Scope لا يفحص KPIS_VIEW (يفحصه الـ controller والـ Policy).
        // بدون CLUSTER_TREE_VIEW الـ widening لن يطبَّق، فيبقى strict same-org (2).
        $this->assertSame(2, (clone $query)->count());
    }

    public function test_sibling_cluster_is_isolated(): void
    {
        [$clusterA, $hospitalA] = $this->makeClusterTree('cluster A', 'hospital A');
        [$clusterB, $hospitalB] = $this->makeClusterTree('cluster B', 'hospital B');

        $user = User::factory()->create([
            'organization_id' => $clusterA->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [
            Capability::KPIS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        Kpi::factory()->create(['organization_id' => $clusterA->id]);
        Kpi::factory()->create(['organization_id' => $hospitalA->id]);
        Kpi::factory()->create(['organization_id' => $clusterB->id]);
        Kpi::factory()->create(['organization_id' => $hospitalB->id]);

        $query = Kpi::query();
        $this->scope->applyToKpis($query, $user);

        // cluster A + hospital A (descendant of A) — cluster B و descendant of B كلاهما غير مرئيّين.
        $this->assertSame(2, (clone $query)->count());
    }

    public function test_child_user_cannot_see_parent_cluster_via_cluster_tree(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $childUser = User::factory()->create([
            'organization_id' => $hospital->id,
            'is_active' => true,
        ]);
        // الطفل في hospital يحاول الحصول على cluster_tree grant لرؤية parent.
        $this->grantEngineCapability($childUser, [
            Capability::KPIS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        Kpi::factory()->count(2)->create(['organization_id' => $cluster->id]);
        Kpi::factory()->count(3)->create(['organization_id' => $hospital->id]);

        $query = Kpi::query();
        $this->scope->applyToKpis($query, $childUser);

        // الطفل لا يستطيع رؤية parent (one-directional walk من user.org نحو descendants فقط).
        $this->assertSame(3, (clone $query)->count());
    }

    public function test_null_org_user_with_cluster_grant_still_fail_closed(): void
    {
        $orphan = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($orphan, [
            Capability::KPIS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $org = Organization::factory()->create();
        Kpi::factory()->count(2)->create(['organization_id' => $org->id]);

        $query = Kpi::query();
        $this->scope->applyToKpis($query, $orphan);

        // null-org user ⇒ whereRaw('false') ⇒ 0 rows.
        $this->assertSame(0, (clone $query)->count());
    }

    public function test_super_admin_sees_all_kpis_regardless_of_cluster_grant(): void
    {
        [$cluster, $hospital, $other] = $this->makeClusterTree();
        $super = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $super->assignRole('super_admin');

        Kpi::factory()->count(2)->create(['organization_id' => $cluster->id]);
        Kpi::factory()->count(3)->create(['organization_id' => $hospital->id]);
        Kpi::factory()->count(5)->create(['organization_id' => $other->id]);

        $query = Kpi::query();
        $this->scope->applyToKpis($query, $super);

        $this->assertSame(10, (clone $query)->count());
    }

    public function test_apply_to_measurements_inherits_cluster_widening(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();
        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [
            Capability::KPIS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $kpiCluster = Kpi::factory()->create(['organization_id' => $cluster->id]);
        $kpiHospital = Kpi::factory()->create(['organization_id' => $hospital->id]);

        $this->makeMeasurement($kpiCluster, $cluster);
        $this->makeMeasurement($kpiHospital, $hospital);
        $this->makeMeasurement(Kpi::factory()->create(['organization_id' => Organization::factory()->create()->id]), Organization::factory()->create());

        $query = KpiMeasurement::query();
        $this->scope->applyToMeasurements($query, $user);

        // يقيسان فقط: cluster + descendant hospital.
        $this->assertSame(2, (clone $query)->count());
    }

    public function test_apply_to_links_inherits_cluster_widening(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();
        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [
            Capability::KPIS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $kpiCluster = Kpi::factory()->create(['organization_id' => $cluster->id]);
        $kpiHospital = Kpi::factory()->create(['organization_id' => $hospital->id]);

        $this->makeLink($kpiCluster, $cluster);
        $this->makeLink($kpiHospital, $hospital);
        $this->makeLink(Kpi::factory()->create(['organization_id' => Organization::factory()->create()->id]), Organization::factory()->create());

        $query = KpiLink::query();
        $this->scope->applyToLinks($query, $user);

        $this->assertSame(2, (clone $query)->count());
    }

    public function test_multi_level_descendant_chain_is_walked_bfs(): void
    {
        $cluster = Organization::factory()->cluster()->create(['name' => 'cluster root']);
        $hospital1 = Organization::factory()->hospital()->childOf($cluster)->create(['name' => 'hospital 1']);
        $center1 = Organization::factory()->center()->childOf($hospital1)->create(['name' => 'center 1 under hospital 1']);
        $hospital2 = Organization::factory()->hospital()->childOf($cluster)->create(['name' => 'hospital 2']);
        $sibling = Organization::factory()->create(['name' => 'unrelated sibling']);

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [
            Capability::KPIS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        Kpi::factory()->create(['organization_id' => $cluster->id]);
        Kpi::factory()->create(['organization_id' => $hospital1->id]);
        Kpi::factory()->create(['organization_id' => $center1->id]);
        Kpi::factory()->create(['organization_id' => $hospital2->id]);
        Kpi::factory()->create(['organization_id' => $sibling->id]);

        $query = Kpi::query();
        $this->scope->applyToKpis($query, $user);

        // 4 مرئية: cluster + hospital1 + center1 + hospital2. الـ sibling معزول.
        $this->assertSame(4, (clone $query)->count());
    }

    /**
     * يبني شجرة: [cluster, hospital child, unrelated sibling].
     *
     * @return array{0: Organization, 1: Organization, 2: Organization}
     */
    private function makeClusterTree(string $clusterName = 'cluster', string $hospitalName = 'hospital'): array
    {
        $cluster = Organization::factory()->cluster()->create(['name' => $clusterName]);
        $hospital = Organization::factory()->hospital()->childOf($cluster)->create(['name' => $hospitalName]);
        $sibling = Organization::factory()->create(['name' => 'sibling of '.$hospitalName]);

        return [$cluster, $hospital, $sibling];
    }

    private function makeMeasurement(Kpi $kpi, Organization $org): KpiMeasurement
    {
        $m = new KpiMeasurement([
            'kpi_id' => $kpi->id,
            'value' => 10,
            'measurement_date' => now()->toDateString(),
            'recorded_by' => null,
        ]);
        $m->forceFill(['organization_id' => $org->id])->save();

        return $m;
    }

    private function makeLink(Kpi $kpi, Organization $org): KpiLink
    {
        $link = new KpiLink([
            'kpi_id' => $kpi->id,
            'linkable_type' => 'project',
            'linkable_id' => 0,
            'relationship_type' => 'related',
        ]);
        $link->forceFill(['organization_id' => $org->id])->save();

        return $link;
    }
}
