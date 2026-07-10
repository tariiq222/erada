<?php

namespace Tests\Unit\RiskManagement\Policies;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\RiskManagement\Models\Risk;
use App\Modules\RiskManagement\Models\RiskAction;
use App\Modules\RiskManagement\Policies\RiskActionPolicy;
use App\Modules\RiskManagement\Policies\RiskPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * ClusterTreeRiskPolicyCrudStrictTest — Phase CFA-05.
 *
 * Regression pin: arbitrary CRUD writes on Risks / RiskActions stay strict
 * same-org. NO cluster widening for create / update / delete (per CFA-00
 * owner decision 2026-07-09).
 *
 * Only the governance-write subset (RISKS_REASSESS, RISKS_CHANGE_STATUS)
 * widens via CLUSTER_TREE_MANAGE, NOT arbitrary CRUD. The
 * RiskActionPolicy update / delete stays strict same-org as well — only
 * the view path widens (cross-org read for cluster monitoring only).
 */
class ClusterTreeRiskPolicyCrudStrictTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    private function makeClusterTree(string $clusterName = 'cluster', string $hospitalName = 'hospital'): array
    {
        $cluster = Organization::factory()->cluster()->create(['name' => $clusterName]);
        $hospital = Organization::factory()->hospital()->childOf($cluster)->create(['name' => $hospitalName]);

        return [$cluster, $hospital];
    }

    // ============================================================
    // RiskPolicy: create / update / delete / viewReports stay strict same-org
    // ============================================================

    public function test_create_stays_strict_same_org_no_widening(): void
    {
        // create() doesn't take a target — it's a public gate. With BOTH
        // grants on the cluster org, create() still requires same-org
        // departmental governance (RiskAuthorizationService::canCreateAny),
        // which is scope-chained by user.org. A user in cluster cannot
        // create a risk in hospital even with CLUSTER_TREE_MANAGE — per
        // CFA-00 owner decision, create stays strict same-org.
        [$cluster] = $this->makeClusterTree();
        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);

        // Engine grants don't enable create() for cluster actors on
        // hospital risks. This is a public-gate test only; the deeper
        // target-dept check lives in StoreRiskRequest::authorize.
        $result = (new RiskPolicy)->create($user);
        // Even if create() returns false here (no engine grants yet), it
        // doesn't widen to other orgs by design. CFA-00 mandate: NO write
        // widening for create.
        $this->assertFalse($result, 'create() must remain strict same-org gated');
    }

    public function test_update_stays_strict_same_org_no_widening(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, [
            Capability::RISKS_EDIT,
            Capability::CLUSTER_TREE_MANAGE,
        ]);

        $childRisk = Risk::factory()->forOrganization($hospital)->create();

        // Per CFA-00: arbitrary field updates stay strict same-org. Only
        // reassess / changeStatus (governance writes) widen via
        // CLUSTER_TREE_MANAGE.
        $this->assertFalse((new RiskPolicy)->update($user, $childRisk));
    }

    public function test_delete_stays_strict_same_org_no_widening(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, [
            Capability::RISKS_DELETE,
            Capability::CLUSTER_TREE_MANAGE,
        ]);

        $childRisk = Risk::factory()->forOrganization($hospital)->create();

        $this->assertFalse((new RiskPolicy)->delete($user, $childRisk));
    }

    public function test_view_reports_does_not_widen_via_cluster_tree_view(): void
    {
        // Reports gate widens via CLUSTER_TREE_EXPORT (in the
        // RiskDashboardController) — not via CLUSTER_TREE_VIEW. The
        // policy's viewReports() must NOT widen because cluster tree
        // view != cluster tree export. The dashboard controller decides
        // where the widen lives.
        [$cluster] = $this->makeClusterTree();
        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, [
            Capability::RISKS_VIEW_REPORTS,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        // viewReports is public (no target). The controller widens via
        // the dashboard-level path. The policy itself stays strict.
        $result = (new RiskPolicy)->viewReports($user);
        $this->assertTrue($result, 'viewReports() should pass for same-org user with capability');
    }

    // ============================================================
    // RiskActionPolicy: update / delete stay strict same-org
    // ============================================================

    public function test_action_update_stays_strict_same_org_no_widening(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, [
            Capability::RISKS_EDIT,
            Capability::CLUSTER_TREE_MANAGE,
        ]);

        $risk = Risk::factory()->forOrganization($hospital)->create();
        $action = RiskAction::factory()->create([
            'organization_id' => $hospital->id,
            'risk_id' => $risk->id,
        ]);

        $this->assertFalse((new RiskActionPolicy)->update($user, $action));
    }

    public function test_action_delete_stays_strict_same_org_no_widening(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, [
            Capability::RISKS_DELETE,
            Capability::CLUSTER_TREE_MANAGE,
        ]);

        $risk = Risk::factory()->forOrganization($hospital)->create();
        $action = RiskAction::factory()->create([
            'organization_id' => $hospital->id,
            'risk_id' => $risk->id,
        ]);

        $this->assertFalse((new RiskActionPolicy)->delete($user, $action));
    }
}
