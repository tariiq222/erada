<?php

namespace Tests\Unit\Performance\Policies;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Performance\Models\Kpi;
use App\Modules\Performance\Policies\KpiPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * ClusterTreeKpiPolicyTest - Phase 9-D-D1a: cluster_tree read widening at the Policy layer.
 *
 * يثبت:
 *   1) cluster user مع KPIS_VIEW + CLUSTER_TREE_VIEW ⇒ view() على child-org KPI = true.
 *   2) cluster user بدون CLUSTER_TREE_VIEW ⇒ view() على child-org KPI = false.
 *   3) cluster user بدون KPIS_VIEW ⇒ view() على child-org KPI = false.
 *   4) sibling cluster ⇒ view() = false.
 *   5) child user ⇒ لا يستطيع رؤية parent cluster KPI عبر cluster_tree.
 *   6) update / delete / create تبقى org-strict (لا widening).
 *   7) super_admin يتجاوز كل شيء.
 *   8) null-org user ⇒ view() = false (fail-closed).
 *   9) sibling org خارج الـ cluster ⇒ لا يُوسّع.
 *
 * Uses GrantsEngineCapability::grantEngineCapability() to grant capabilities on a scoped role.
 */
class ClusterTreeKpiPolicyTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    private KpiPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new KpiPolicy;
    }

    public function test_cluster_user_with_both_grants_can_view_child_org_kpi(): void
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

        $childKpi = Kpi::factory()->create(['organization_id' => $hospital->id]);

        $this->assertTrue($this->policy->view($user, $childKpi));
    }

    public function test_cluster_user_without_cluster_tree_view_cannot_view_child_org_kpi(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, Capability::KPIS_VIEW);

        $childKpi = Kpi::factory()->create(['organization_id' => $hospital->id]);

        $this->assertFalse($this->policy->view($user, $childKpi));
    }

    public function test_cluster_user_without_kpis_view_cannot_view_child_org_kpi(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, Capability::CLUSTER_TREE_VIEW);

        $childKpi = Kpi::factory()->create(['organization_id' => $hospital->id]);

        // CLUSTER_TREE_VIEW وحده لا يكفي — يلزم KPIS_VIEW أيضاً.
        $this->assertFalse($this->policy->view($user, $childKpi));
    }

    public function test_sibling_cluster_cannot_view_each_others_child_org_kpis(): void
    {
        [$clusterA, $hospitalA] = $this->makeClusterTree('cluster A', 'hospital A');
        [$clusterB, $hospitalB] = $this->makeClusterTree('cluster B', 'hospital B');

        $userA = User::factory()->create([
            'organization_id' => $clusterA->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($userA, [
            Capability::KPIS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $kpiInClusterB = Kpi::factory()->create(['organization_id' => $clusterB->id]);
        $kpiInHospitalB = Kpi::factory()->create(['organization_id' => $hospitalB->id]);

        // A لا يستطيع رؤية B subtree حتى مع الكلا القدرةَين.
        $this->assertFalse($this->policy->view($userA, $kpiInClusterB));
        $this->assertFalse($this->policy->view($userA, $kpiInHospitalB));
    }

    public function test_child_user_cannot_view_parent_cluster_kpi_via_cluster_tree(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $childUser = User::factory()->create([
            'organization_id' => $hospital->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($childUser, [
            Capability::KPIS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $parentKpi = Kpi::factory()->create(['organization_id' => $cluster->id]);
        $ownKpi = Kpi::factory()->create(['organization_id' => $hospital->id]);

        // الطفل لا يستطيع رؤية parent (one-directional).
        $this->assertFalse($this->policy->view($childUser, $parentKpi));
        // لكنّه يرى KPI في منظمته (same-org).
        $this->assertTrue($this->policy->view($childUser, $ownKpi));
    }

    public function test_update_remains_org_strict_with_cluster_grants(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [
            Capability::KPIS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
            Capability::KPIS_MANAGE,
        ]);

        $childKpi = Kpi::factory()->create(['organization_id' => $hospital->id]);

        // view مُتوسَّع، لكن update يبقى org-strict.
        $this->assertTrue($this->policy->view($user, $childKpi));
        $this->assertFalse($this->policy->update($user, $childKpi));
    }

    public function test_delete_remains_org_strict_with_cluster_grants(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [
            Capability::KPIS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
            Capability::KPIS_MANAGE,
        ]);

        $childKpi = Kpi::factory()->create(['organization_id' => $hospital->id]);

        $this->assertTrue($this->policy->view($user, $childKpi));
        $this->assertFalse($this->policy->delete($user, $childKpi));
    }

    public function test_create_remains_org_scoped_regardless_of_cluster_grant(): void
    {
        [$cluster] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [
            Capability::KPIS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
            Capability::KPIS_MANAGE,
        ]);

        // create() يعتمد على KPIS_MANAGE فقط، لا يتأثّر بـ cluster_tree.
        $this->assertTrue($this->policy->create($user));

        // مستخدم في cluster بدون KPIS_MANAGE لا يستطيع إنشاء KPI حتى مع cluster_tree.
        $readOnlyUser = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($readOnlyUser, [
            Capability::KPIS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);
        $this->assertFalse($this->policy->create($readOnlyUser));
    }

    public function test_super_admin_can_view_any_kpi_via_cluster_tree_path(): void
    {
        [, $hospital] = $this->makeClusterTree();

        $super = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $super->assignRole('super_admin');

        $childKpi = Kpi::factory()->create(['organization_id' => $hospital->id]);

        // super_admin يتجاوز دون المرور بـ cluster_tree rescue.
        $this->assertTrue($this->policy->view($super, $childKpi));
    }

    public function test_null_org_user_cannot_view_child_org_kpi_even_with_grants(): void
    {
        [, $hospital] = $this->makeClusterTree();

        $orphan = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($orphan, [
            Capability::KPIS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $childKpi = Kpi::factory()->create(['organization_id' => $hospital->id]);

        // null-org user ⇒ cluster_tree rescue fail-closed (userHasScopedRoleGrantingCapability requires orgId).
        $this->assertFalse($this->policy->view($orphan, $childKpi));
    }

    public function test_unrelated_org_outside_cluster_cannot_be_seen(): void
    {
        [$cluster, , $other] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [
            Capability::KPIS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $kpiInOther = Kpi::factory()->create(['organization_id' => $other->id]);

        $this->assertFalse($this->policy->view($user, $kpiInOther));
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
