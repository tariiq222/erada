<?php

namespace Tests\Unit\Shared\Policies;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Shared\Models\ActivityLog;
use App\Modules\Shared\Policies\ActivityLogPolicy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * Phase CFA-11 — cluster_auditor widening at the ActivityLogPolicy layer.
 *
 * Mirrors the CFA-00 / CFA-02 contract used in PortfolioPolicy / KpiPolicy:
 *
 *   - Both AUDIT_VIEW (or AUDIT_EXPORT) AND CLUSTER_TREE_VIEW (or
 *     CLUSTER_TREE_EXPORT) are REQUIRED on actor.organization_id.
 *   - Missing either capability ⇒ deny (strict same-org).
 *   - Sibling cluster org ⇒ deny.
 *   - Child (descendant) user on parent cluster row ⇒ deny
 *     (one-directional rescue).
 *   - Null-org actor ⇒ deny (fail-closed).
 *   - Super_admin ⇒ allow.
 *   - A cluster PMO role with cluster_tree.view but NO audit.view
 *     must NOT see audit rows.
 *
 * Cross-module boundary: granting cluster_tree.* to the actor must NOT
 * widen module resource-level access (this is the cluster_auditor
 * isolation contract — see ClusterTreeAuditRoleIsolationTest).
 */
class ClusterTreeActivityLogPolicyViewTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_cluster_auditor_with_both_grants_can_view_child_org_log(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [
            Capability::AUDIT_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $childLog = $this->makeLogWithOrg($hospital->id);

        $this->assertTrue((new ActivityLogPolicy)->view($user, $childLog));
    }

    public function test_cluster_auditor_without_cluster_tree_view_cannot_view_child_org_log(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, Capability::AUDIT_VIEW);

        $childLog = $this->makeLogWithOrg($hospital->id);

        $this->assertFalse((new ActivityLogPolicy)->view($user, $childLog));
    }

    public function test_cluster_auditor_without_audit_view_cannot_view_child_org_log(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, Capability::CLUSTER_TREE_VIEW);

        $childLog = $this->makeLogWithOrg($hospital->id);

        $this->assertFalse((new ActivityLogPolicy)->view($user, $childLog));
    }

    public function test_sibling_cluster_cannot_view_other_cluster_child_log(): void
    {
        [$clusterA] = $this->makeClusterTree('cluster A', 'hospital A');
        [, $hospitalB] = $this->makeClusterTree('cluster B', 'hospital B');

        $user = User::factory()->create([
            'organization_id' => $clusterA->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [
            Capability::AUDIT_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $unrelatedLog = $this->makeLogWithOrg($hospitalB->id);

        $this->assertFalse((new ActivityLogPolicy)->view($user, $unrelatedLog));
    }

    public function test_child_user_cannot_view_parent_cluster_log_via_cluster_auditor(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $childUser = User::factory()->create([
            'organization_id' => $hospital->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($childUser, [
            Capability::AUDIT_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $parentLog = $this->makeLogWithOrg($cluster->id);

        $this->assertFalse((new ActivityLogPolicy)->view($childUser, $parentLog));
    }

    public function test_null_org_user_cannot_view_anything_via_cluster_auditor(): void
    {
        [, $hospital] = $this->makeClusterTree();

        $nullOrgUser = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($nullOrgUser, [
            Capability::AUDIT_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $childLog = $this->makeLogWithOrg($hospital->id);

        $this->assertFalse((new ActivityLogPolicy)->view($nullOrgUser, $childLog));
    }

    public function test_super_admin_bypasses_cluster_auditor_pair(): void
    {
        [, $hospital] = $this->makeClusterTree();

        $superAdmin = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $superAdmin->assignRole('super_admin');

        $childLog = $this->makeLogWithOrg($hospital->id);

        $this->assertTrue((new ActivityLogPolicy)->view($superAdmin, $childLog));
    }

    public function test_cluster_pmo_with_cluster_tree_view_but_no_audit_view_cannot_view_log(): void
    {
        // Cross-module boundary: a user holding CLUSTER_TREE_VIEW but NOT
        // AUDIT_VIEW must NOT be able to read activity logs via the
        // cluster widening. The audit pair is REQUIRED; cluster_tree alone
        // does NOT imply audit access.
        [$cluster, $hospital] = $this->makeClusterTree();

        $clusterPmo = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($clusterPmo, Capability::CLUSTER_TREE_VIEW);

        $childLog = $this->makeLogWithOrg($hospital->id);

        $this->assertFalse((new ActivityLogPolicy)->view($clusterPmo, $childLog));
    }

    public function test_null_org_log_row_stays_super_admin_only(): void
    {
        [$cluster] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [
            Capability::AUDIT_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $nullOrgLog = $this->makeLogWithOrg(null);

        // system-level null-org rows are H-01 super_admin only — the
        // cluster widening must NOT silently admit them.
        $this->assertFalse((new ActivityLogPolicy)->view($user, $nullOrgLog));
    }

    public function test_cluster_auditor_export_pair_can_export_child_org_log(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [
            Capability::AUDIT_EXPORT,
            Capability::CLUSTER_TREE_EXPORT,
        ]);

        $childLog = $this->makeLogWithOrg($hospital->id);

        $this->assertTrue((new ActivityLogPolicy)->exportOne($user, $childLog));
    }

    public function test_view_any_with_cluster_pair_admits(): void
    {
        [$cluster] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [
            Capability::AUDIT_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $this->assertTrue((new ActivityLogPolicy)->viewAny($user));
    }

    public function test_view_any_without_either_grant_denies(): void
    {
        [$cluster] = $this->makeClusterTree();

        // Audit cap only ⇒ viewAny=true (same-org path is honored);
        // cluster widening never silently blocks the same-org gate.
        $userAuditOnly = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($userAuditOnly, Capability::AUDIT_VIEW);
        $this->assertTrue((new ActivityLogPolicy)->viewAny($userAuditOnly));

        // Cluster_tree only ⇒ viewAny=false (no audit capability means
        // the engine returns deny; cluster_tree alone never widens to
        // audit access).
        $userClusterOnly = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($userClusterOnly, Capability::CLUSTER_TREE_VIEW);
        $this->assertFalse((new ActivityLogPolicy)->viewAny($userClusterOnly));

        // Neither ⇒ deny.
        $userNeither = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->assertFalse((new ActivityLogPolicy)->viewAny($userNeither));
    }

    // ──────────────────────────────────────────────────────────────
    // helpers
    // ──────────────────────────────────────────────────────────────

    /**
     * @return array{0: Organization, 1: Organization}
     */
    private function makeClusterTree(string $clusterName = 'cluster', string $hospitalName = 'hospital'): array
    {
        $cluster = Organization::factory()->cluster()->create(['name' => $clusterName]);
        $hospital = Organization::factory()->hospital()->childOf($cluster)->create(['name' => $hospitalName]);

        return [$cluster, $hospital];
    }

    private function makeLogWithOrg(?int $orgId): ActivityLog
    {
        $deptId = null;
        if ($orgId !== null) {
            $dept = Department::factory()->create(['organization_id' => $orgId]);
            $deptId = (int) $dept->getKey();
        }
        $user = User::factory()->create([
            'organization_id' => $orgId,
            'department_id' => $deptId,
            'is_active' => true,
        ]);

        return ActivityLog::create([
            'user_id' => $user->id,
            'action' => 'cfa11_test',
            'description' => 'cluster audit widening probe',
            'loggable_type' => User::class,
            'loggable_id' => $user->id,
            'organization_id' => $orgId,
        ]);
    }
}
