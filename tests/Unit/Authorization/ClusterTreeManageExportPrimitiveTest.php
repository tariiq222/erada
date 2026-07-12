<?php

namespace Tests\Unit\Authorization;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Authorization\Contracts\SensitivelyScoped;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\CoreCapabilityProvider;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * ClusterTreeManageExportPrimitiveTest — Phase CFA-01.
 *
 * Sibling primitive test to AccessDecisionTest::cluster_tree_* (Phase 9-D-B).
 * Proves that `core.cluster_tree.manage` and `core.cluster_tree.export` ride the
 * SAME rescue branch as `core.cluster_tree.view`, with the same ancestor walk
 * + scoped-role grant + sensitive-target floor. The two new primitives are
 * pure read-AND write-AND export siblings: each fires through the same gate,
 * each requires an explicit scoped role grant on actor.organization_id, each
 * is forbidden from widening to module capabilities.
 *
 * Contract under test (mirrors CFA-00 owner decisions, 2026-07-09):
 *   - Both primitives activate the cluster_tree_rescue trace layer.
 *   - Both fail closed without an explicit canonical grant on actor.org.
 *   - Sibling cluster orgs denied.
 *   - Child → parent denied.
 *   - Null-org actor denied (fail-closed).
 *   - Sensitive (SensitivelyScoped + isSensitive=true) targets denied.
 *   - Same-org strict-equality gate still wins; rescue never fires for
 *     same-org targets.
 *   - super_admin bypass unchanged.
 *   - The primitive does NOT imply module permissions; AccessDecision::can()
 *     for module.edit + CLUSTER_TREE_MANAGE only returns true when BOTH are
 *     held.
 */
class ClusterTreeManageExportPrimitiveTest extends TestCase
{
    use GrantsEngineCapability;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed the engine role catalog so AccessDecision has role definitions
        // for CapabilityAlias + scoped-role lookups.
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * Helper: create a scoped role definition on an organization that grants
     * ONLY the given capability(ies). Returns the role key used.
     */
    private function grantClusterRoleOnOrganization(User $user, Organization $org, array $capabilities, string $roleKey): void
    {
        $this->grantEngineCapability(
            $user,
            $capabilities,
            'organization',
            (int) $org->id,
            $roleKey,
            ['inherit_to_children' => true],
            ['core' => 'all'],
        );
    }

    /**
     * Build a Project in a given child organization. Project is a
     * ScopeAware model with organization_id derived via department.
     */
    private function makeProjectInOrg(int $orgId): Project
    {
        return Project::factory()->create([
            'organization_id' => $orgId,
            'department_id' => Department::factory()->create(['organization_id' => $orgId])->id,
        ]);
    }

    // =========================================================
    // CLUSTER_TREE_MANAGE — basic rescue
    // =========================================================

    #[Test]
    public function cluster_user_with_manage_grant_can_pass_rescue_on_child_org_target(): void
    {
        $cluster = Organization::factory()->cluster()->create();
        $child = Organization::factory()->hospital()->childOf($cluster)->create();
        $childProject = $this->makeProjectInOrg($child->id);

        $user = User::factory()->create(['organization_id' => $cluster->id]);
        $this->grantClusterRoleOnOrganization(
            $user,
            $cluster,
            [Capability::CLUSTER_TREE_MANAGE],
            'cluster_tree_manager'
        );

        $this->assertTrue(
            AccessDecision::can($user, Capability::CLUSTER_TREE_MANAGE, $childProject),
            'cluster user with CLUSTER_TREE_MANAGE grant should pass rescue on child org target'
        );

        $trace = AccessDecision::whyCan($user, Capability::CLUSTER_TREE_MANAGE, $childProject);
        $this->assertSame('cluster_tree_rescue', $trace['layer']);
        $this->assertTrue($trace['granted']);
    }

    #[Test]
    public function cluster_user_without_manage_grant_is_denied_on_child_org_target(): void
    {
        $cluster = Organization::factory()->cluster()->create();
        $child = Organization::factory()->hospital()->childOf($cluster)->create();
        $childProject = $this->makeProjectInOrg($child->id);

        $user = User::factory()->create(['organization_id' => $cluster->id]);
        // no role granted

        $this->assertFalse(
            AccessDecision::can($user, Capability::CLUSTER_TREE_MANAGE, $childProject),
            'cluster user without CLUSTER_TREE_MANAGE grant must NOT pass rescue'
        );
    }

    #[Test]
    public function cluster_user_with_only_view_grant_does_not_pass_manage_rescue(): void
    {
        $cluster = Organization::factory()->cluster()->create();
        $child = Organization::factory()->hospital()->childOf($cluster)->create();
        $childProject = $this->makeProjectInOrg($child->id);

        $user = User::factory()->create(['organization_id' => $cluster->id]);
        $this->grantClusterRoleOnOrganization(
            $user,
            $cluster,
            [Capability::CLUSTER_TREE_VIEW],
            'cluster_tree_viewer_only'
        );

        // View alone must NOT enable Manage rescue — primitives are
        // independent; each fires only when ITS grant is held.
        $this->assertFalse(
            AccessDecision::can($user, Capability::CLUSTER_TREE_MANAGE, $childProject),
            'CLUSTER_TREE_VIEW alone must NOT enable CLUSTER_TREE_MANAGE rescue'
        );
    }

    #[Test]
    public function cluster_user_with_manage_grant_sees_sibling_cluster_denied(): void
    {
        $clusterA = Organization::factory()->cluster()->create();
        $clusterB = Organization::factory()->cluster()->create();
        $childB = Organization::factory()->hospital()->childOf($clusterB)->create();
        $childBProject = $this->makeProjectInOrg($childB->id);

        $userA = User::factory()->create(['organization_id' => $clusterA->id]);
        $this->grantClusterRoleOnOrganization(
            $userA,
            $clusterA,
            [Capability::CLUSTER_TREE_MANAGE],
            'cluster_a_manager'
        );

        $this->assertFalse(
            AccessDecision::can($userA, Capability::CLUSTER_TREE_MANAGE, $childBProject),
            'cluster A user with MANAGE grant must NOT see cluster B subtree'
        );
    }

    #[Test]
    public function child_user_with_manage_grant_cannot_see_parent_via_rescue(): void
    {
        $cluster = Organization::factory()->cluster()->create();
        $child = Organization::factory()->hospital()->childOf($cluster)->create();
        $clusterProject = $this->makeProjectInOrg($cluster->id);

        $childUser = User::factory()->create(['organization_id' => $child->id]);
        $this->grantClusterRoleOnOrganization(
            $childUser,
            $child,
            [Capability::CLUSTER_TREE_MANAGE],
            'child_user_manager'
        );

        $this->assertFalse(
            AccessDecision::can($childUser, Capability::CLUSTER_TREE_MANAGE, $clusterProject),
            'child user with MANAGE grant must NOT see parent cluster data (one-directional)'
        );
    }

    #[Test]
    public function null_org_user_with_manage_grant_is_fail_closed(): void
    {
        $cluster = Organization::factory()->cluster()->create();
        $child = Organization::factory()->hospital()->childOf($cluster)->create();
        $childProject = $this->makeProjectInOrg($child->id);

        $nullUser = User::factory()->create(['organization_id' => null]);
        $this->grantClusterRoleOnOrganization(
            $nullUser,
            $cluster,
            [Capability::CLUSTER_TREE_MANAGE],
            'null_org_manager'
        );

        $this->assertFalse(
            AccessDecision::can($nullUser, Capability::CLUSTER_TREE_MANAGE, $childProject),
            'null-org actor must NOT pass MANAGE rescue (fail-closed)'
        );
    }

    // =========================================================
    // CLUSTER_TREE_EXPORT — basic rescue
    // =========================================================

    #[Test]
    public function cluster_user_with_export_grant_can_pass_rescue_on_child_org_target(): void
    {
        $cluster = Organization::factory()->cluster()->create();
        $child = Organization::factory()->hospital()->childOf($cluster)->create();
        $childProject = $this->makeProjectInOrg($child->id);

        $user = User::factory()->create(['organization_id' => $cluster->id]);
        $this->grantClusterRoleOnOrganization(
            $user,
            $cluster,
            [Capability::CLUSTER_TREE_EXPORT],
            'cluster_tree_exporter'
        );

        $this->assertTrue(
            AccessDecision::can($user, Capability::CLUSTER_TREE_EXPORT, $childProject),
            'cluster user with CLUSTER_TREE_EXPORT grant should pass rescue on child org target'
        );

        $trace = AccessDecision::whyCan($user, Capability::CLUSTER_TREE_EXPORT, $childProject);
        $this->assertSame('cluster_tree_rescue', $trace['layer']);
        $this->assertTrue($trace['granted']);
    }

    #[Test]
    public function cluster_user_with_only_view_grant_does_not_pass_export_rescue(): void
    {
        $cluster = Organization::factory()->cluster()->create();
        $child = Organization::factory()->hospital()->childOf($cluster)->create();
        $childProject = $this->makeProjectInOrg($child->id);

        $user = User::factory()->create(['organization_id' => $cluster->id]);
        $this->grantClusterRoleOnOrganization(
            $user,
            $cluster,
            [Capability::CLUSTER_TREE_VIEW],
            'cluster_tree_viewer_only_2'
        );

        $this->assertFalse(
            AccessDecision::can($user, Capability::CLUSTER_TREE_EXPORT, $childProject),
            'CLUSTER_TREE_VIEW alone must NOT enable CLUSTER_TREE_EXPORT rescue'
        );
    }

    #[Test]
    public function cluster_user_with_only_manage_grant_does_not_pass_export_rescue(): void
    {
        $cluster = Organization::factory()->cluster()->create();
        $child = Organization::factory()->hospital()->childOf($cluster)->create();
        $childProject = $this->makeProjectInOrg($child->id);

        $user = User::factory()->create(['organization_id' => $cluster->id]);
        $this->grantClusterRoleOnOrganization(
            $user,
            $cluster,
            [Capability::CLUSTER_TREE_MANAGE],
            'cluster_tree_manager_only'
        );

        $this->assertFalse(
            AccessDecision::can($user, Capability::CLUSTER_TREE_EXPORT, $childProject),
            'CLUSTER_TREE_MANAGE alone must NOT enable CLUSTER_TREE_EXPORT rescue'
        );
    }

    #[Test]
    public function cluster_user_with_export_grant_sees_sibling_cluster_denied(): void
    {
        $clusterA = Organization::factory()->cluster()->create();
        $clusterB = Organization::factory()->cluster()->create();
        $childB = Organization::factory()->hospital()->childOf($clusterB)->create();
        $childBProject = $this->makeProjectInOrg($childB->id);

        $userA = User::factory()->create(['organization_id' => $clusterA->id]);
        $this->grantClusterRoleOnOrganization(
            $userA,
            $clusterA,
            [Capability::CLUSTER_TREE_EXPORT],
            'cluster_a_exporter'
        );

        $this->assertFalse(
            AccessDecision::can($userA, Capability::CLUSTER_TREE_EXPORT, $childBProject),
            'cluster A user with EXPORT grant must NOT see cluster B subtree'
        );
    }

    #[Test]
    public function child_user_with_export_grant_cannot_see_parent_via_rescue(): void
    {
        $cluster = Organization::factory()->cluster()->create();
        $child = Organization::factory()->hospital()->childOf($cluster)->create();
        $clusterProject = $this->makeProjectInOrg($cluster->id);

        $childUser = User::factory()->create(['organization_id' => $child->id]);
        $this->grantClusterRoleOnOrganization(
            $childUser,
            $child,
            [Capability::CLUSTER_TREE_EXPORT],
            'child_user_exporter'
        );

        $this->assertFalse(
            AccessDecision::can($childUser, Capability::CLUSTER_TREE_EXPORT, $clusterProject),
            'child user with EXPORT grant must NOT see parent cluster data (one-directional)'
        );
    }

    #[Test]
    public function null_org_user_with_export_grant_is_fail_closed(): void
    {
        $cluster = Organization::factory()->cluster()->create();
        $child = Organization::factory()->hospital()->childOf($cluster)->create();
        $childProject = $this->makeProjectInOrg($child->id);

        $nullUser = User::factory()->create(['organization_id' => null]);
        $this->grantClusterRoleOnOrganization(
            $nullUser,
            $cluster,
            [Capability::CLUSTER_TREE_EXPORT],
            'null_org_exporter'
        );

        $this->assertFalse(
            AccessDecision::can($nullUser, Capability::CLUSTER_TREE_EXPORT, $childProject),
            'null-org actor must NOT pass EXPORT rescue (fail-closed)'
        );
    }

    // =========================================================
    // CI-contract invariant tests
    //
    // The methods below mirror the cluster-tree rescue invariants that
    // scripts/check-cluster-tree-contract.sh scans for (per-primitive
    // `child_user_cannot_*`, `sibling_cluster*`, and
    // `(null_org|fail_closed)*` patterns). Their behavior is proven in
    // detail by the sibling rescue tests above; these methods exist so the
    // CI gate can locate primitive-specific invariant coverage by name.
    // Keeping the names stable is part of the contract — do not rename
    // without updating scripts/check-cluster-tree-contract.sh as well.
    // =========================================================

    #[Test]
    public function child_user_cannot_see_parent_via_cluster_tree_view(): void
    {
        $cluster = Organization::factory()->cluster()->create();
        $child = Organization::factory()->hospital()->childOf($cluster)->create();
        $clusterProject = $this->makeProjectInOrg($cluster->id);

        $childUser = User::factory()->create(['organization_id' => $child->id]);
        $this->grantClusterRoleOnOrganization(
            $childUser,
            $child,
            [Capability::CLUSTER_TREE_VIEW],
            'child_user_viewer_ci'
        );

        $this->assertFalse(
            AccessDecision::can($childUser, Capability::CLUSTER_TREE_VIEW, $clusterProject),
            'CI-contract: child user with VIEW grant must NOT see parent cluster data (one-directional)'
        );
    }

    #[Test]
    public function child_user_cannot_see_parent_via_cluster_tree_manage(): void
    {
        $cluster = Organization::factory()->cluster()->create();
        $child = Organization::factory()->hospital()->childOf($cluster)->create();
        $clusterProject = $this->makeProjectInOrg($cluster->id);

        $childUser = User::factory()->create(['organization_id' => $child->id]);
        $this->grantClusterRoleOnOrganization(
            $childUser,
            $child,
            [Capability::CLUSTER_TREE_MANAGE],
            'child_user_manager_ci'
        );

        $this->assertFalse(
            AccessDecision::can($childUser, Capability::CLUSTER_TREE_MANAGE, $clusterProject),
            'CI-contract: child user with MANAGE grant must NOT see parent cluster data (one-directional)'
        );
    }

    #[Test]
    public function child_user_cannot_see_parent_via_cluster_tree_export(): void
    {
        $cluster = Organization::factory()->cluster()->create();
        $child = Organization::factory()->hospital()->childOf($cluster)->create();
        $clusterProject = $this->makeProjectInOrg($cluster->id);

        $childUser = User::factory()->create(['organization_id' => $child->id]);
        $this->grantClusterRoleOnOrganization(
            $childUser,
            $child,
            [Capability::CLUSTER_TREE_EXPORT],
            'child_user_exporter_ci_v2'
        );

        $this->assertFalse(
            AccessDecision::can($childUser, Capability::CLUSTER_TREE_EXPORT, $clusterProject),
            'CI-contract: child user with EXPORT grant must NOT see parent cluster data (one-directional)'
        );
    }

    #[Test]
    public function sibling_cluster_cannot_share_via_cluster_tree_view(): void
    {
        $clusterA = Organization::factory()->cluster()->create();
        $clusterB = Organization::factory()->cluster()->create();
        $childB = Organization::factory()->hospital()->childOf($clusterB)->create();
        $childBProject = $this->makeProjectInOrg($childB->id);

        $userA = User::factory()->create(['organization_id' => $clusterA->id]);
        $this->grantClusterRoleOnOrganization(
            $userA,
            $clusterA,
            [Capability::CLUSTER_TREE_VIEW],
            'cluster_a_viewer_ci'
        );

        $this->assertFalse(
            AccessDecision::can($userA, Capability::CLUSTER_TREE_VIEW, $childBProject),
            'CI-contract: cluster A VIEW grant must NOT reach cluster B subtree'
        );
    }

    #[Test]
    public function null_org_user_fail_closed_via_cluster_tree_view(): void
    {
        $cluster = Organization::factory()->cluster()->create();
        $child = Organization::factory()->hospital()->childOf($cluster)->create();
        $childProject = $this->makeProjectInOrg($child->id);

        $nullUser = User::factory()->create(['organization_id' => null]);
        $this->grantClusterRoleOnOrganization(
            $nullUser,
            $cluster,
            [Capability::CLUSTER_TREE_VIEW],
            'null_org_viewer_ci'
        );

        $this->assertFalse(
            AccessDecision::can($nullUser, Capability::CLUSTER_TREE_VIEW, $childProject),
            'CI-contract: null-org actor must NOT pass VIEW rescue (fail-closed)'
        );
    }

    #[Test]
    public function sibling_cluster_cannot_share_via_cluster_tree_manage(): void
    {
        $clusterA = Organization::factory()->cluster()->create();
        $clusterB = Organization::factory()->cluster()->create();
        $childB = Organization::factory()->hospital()->childOf($clusterB)->create();
        $childBProject = $this->makeProjectInOrg($childB->id);

        $userA = User::factory()->create(['organization_id' => $clusterA->id]);
        $this->grantClusterRoleOnOrganization(
            $userA,
            $clusterA,
            [Capability::CLUSTER_TREE_MANAGE],
            'cluster_a_manager_ci'
        );

        $this->assertFalse(
            AccessDecision::can($userA, Capability::CLUSTER_TREE_MANAGE, $childBProject),
            'CI-contract: cluster A MANAGE grant must NOT reach cluster B subtree'
        );
    }

    #[Test]
    public function sibling_cluster_cannot_share_via_cluster_tree_export(): void
    {
        $clusterA = Organization::factory()->cluster()->create();
        $clusterB = Organization::factory()->cluster()->create();
        $childB = Organization::factory()->hospital()->childOf($clusterB)->create();
        $childBProject = $this->makeProjectInOrg($childB->id);

        $userA = User::factory()->create(['organization_id' => $clusterA->id]);
        $this->grantClusterRoleOnOrganization(
            $userA,
            $clusterA,
            [Capability::CLUSTER_TREE_EXPORT],
            'cluster_a_exporter_ci'
        );

        $this->assertFalse(
            AccessDecision::can($userA, Capability::CLUSTER_TREE_EXPORT, $childBProject),
            'CI-contract: cluster A EXPORT grant must NOT reach cluster B subtree'
        );
    }

    #[Test]
    public function null_org_user_fail_closed_via_cluster_tree_manage(): void
    {
        $cluster = Organization::factory()->cluster()->create();
        $child = Organization::factory()->hospital()->childOf($cluster)->create();
        $childProject = $this->makeProjectInOrg($child->id);

        $nullUser = User::factory()->create(['organization_id' => null]);
        $this->grantClusterRoleOnOrganization(
            $nullUser,
            $cluster,
            [Capability::CLUSTER_TREE_MANAGE],
            'null_org_manager_ci'
        );

        $this->assertFalse(
            AccessDecision::can($nullUser, Capability::CLUSTER_TREE_MANAGE, $childProject),
            'CI-contract: null-org actor must NOT pass MANAGE rescue (fail-closed)'
        );
    }

    #[Test]
    public function null_org_user_fail_closed_via_cluster_tree_export(): void
    {
        $cluster = Organization::factory()->cluster()->create();
        $child = Organization::factory()->hospital()->childOf($cluster)->create();
        $childProject = $this->makeProjectInOrg($child->id);

        $nullUser = User::factory()->create(['organization_id' => null]);
        $this->grantClusterRoleOnOrganization(
            $nullUser,
            $cluster,
            [Capability::CLUSTER_TREE_EXPORT],
            'null_org_exporter_ci'
        );

        $this->assertFalse(
            AccessDecision::can($nullUser, Capability::CLUSTER_TREE_EXPORT, $childProject),
            'CI-contract: null-org actor must NOT pass EXPORT rescue (fail-closed)'
        );
    }

    // =========================================================
    // Cross-primitive guarantees
    // =========================================================

    #[Test]
    public function manage_and_export_rescue_do_not_fire_for_same_org_targets(): void
    {
        $org = Organization::factory()->create();
        $project = $this->makeProjectInOrg($org->id);

        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->grantClusterRoleOnOrganization(
            $user,
            $org,
            [Capability::CLUSTER_TREE_MANAGE, Capability::CLUSTER_TREE_EXPORT],
            'same_org_user_with_cluster'
        );

        // same-org: rescue MUST NOT fire; strict-equality gate handles it.
        $traceManage = AccessDecision::whyCan($user, Capability::CLUSTER_TREE_MANAGE, $project);
        $this->assertNotSame('cluster_tree_rescue', $traceManage['layer']);

        $traceExport = AccessDecision::whyCan($user, Capability::CLUSTER_TREE_EXPORT, $project);
        $this->assertNotSame('cluster_tree_rescue', $traceExport['layer']);
    }

    #[Test]
    public function manage_and_export_rescue_do_not_bypass_sensitive_floor(): void
    {
        $cluster = Organization::factory()->cluster()->create();
        $child = Organization::factory()->hospital()->childOf($cluster)->create();

        $user = User::factory()->create(['organization_id' => $cluster->id]);
        $this->grantClusterRoleOnOrganization(
            $user,
            $cluster,
            [Capability::CLUSTER_TREE_MANAGE, Capability::CLUSTER_TREE_EXPORT],
            'cluster_user_with_both'
        );

        // Stub sensitive target: SensitivelyScoped + isSensitive=true.
        $sensitiveTarget = new ClusterTreePrimitiveSensitiveStubTarget;
        $sensitiveTarget->organization_id = $child->id;
        $sensitiveTarget->exists = true;

        $traceManage = AccessDecision::whyCan($user, Capability::CLUSTER_TREE_MANAGE, $sensitiveTarget);
        $this->assertFalse(
            $traceManage['granted'],
            'CRITICAL: CLUSTER_TREE_MANAGE must NOT bypass sensitive floor'
        );
        $this->assertNotSame('cluster_tree_rescue', $traceManage['layer']);

        $traceExport = AccessDecision::whyCan($user, Capability::CLUSTER_TREE_EXPORT, $sensitiveTarget);
        $this->assertFalse(
            $traceExport['granted'],
            'CRITICAL: CLUSTER_TREE_EXPORT must NOT bypass sensitive floor'
        );
        $this->assertNotSame('cluster_tree_rescue', $traceExport['layer']);
    }

    #[Test]
    public function super_admin_bypasses_manage_and_export_rescue(): void
    {
        $cluster = Organization::factory()->cluster()->create();
        $child = Organization::factory()->hospital()->childOf($cluster)->create();
        $childProject = $this->makeProjectInOrg($child->id);

        $superAdmin = User::factory()->create(['organization_id' => null]);
        $this->grantCanonicalSuperAdmin($superAdmin);

        // super_admin short-circuit returns true regardless of the primitive.
        $this->assertTrue(
            AccessDecision::can($superAdmin, Capability::CLUSTER_TREE_MANAGE, $childProject),
            'super_admin must bypass CLUSTER_TREE_MANAGE'
        );
        $this->assertTrue(
            AccessDecision::can($superAdmin, Capability::CLUSTER_TREE_EXPORT, $childProject),
            'super_admin must bypass CLUSTER_TREE_EXPORT'
        );

        // Trace layer is canonical_admin, not cluster_tree_rescue.
        $traceManage = AccessDecision::whyCan($superAdmin, Capability::CLUSTER_TREE_MANAGE, $childProject);
        $this->assertSame('canonical_admin', $traceManage['layer']);

        $traceExport = AccessDecision::whyCan($superAdmin, Capability::CLUSTER_TREE_EXPORT, $childProject);
        $this->assertSame('canonical_admin', $traceExport['layer']);
    }

    #[Test]
    public function manage_primitive_does_not_imply_module_edit_capability(): void
    {
        $cluster = Organization::factory()->cluster()->create();
        $child = Organization::factory()->hospital()->childOf($cluster)->create();
        $childProject = $this->makeProjectInOrg($child->id);

        $user = User::factory()->create(['organization_id' => $cluster->id]);
        // only CLUSTER_TREE_MANAGE — no PROJECTS_EDIT
        $this->grantClusterRoleOnOrganization(
            $user,
            $cluster,
            [Capability::CLUSTER_TREE_MANAGE],
            'manage_only_no_module'
        );

        // CLUSTER_TREE_MANAGE alone must NOT enable PROJECTS_EDIT —
        // the module capability is required IN PARALLEL.
        $this->assertFalse(
            AccessDecision::can($user, Capability::PROJECTS_EDIT, $childProject),
            'CLUSTER_TREE_MANAGE must NOT imply module edit capability'
        );
    }

    #[Test]
    public function export_primitive_does_not_imply_module_export_capability(): void
    {
        $cluster = Organization::factory()->cluster()->create();
        $child = Organization::factory()->hospital()->childOf($cluster)->create();
        $childProject = $this->makeProjectInOrg($child->id);

        $user = User::factory()->create(['organization_id' => $cluster->id]);
        // only CLUSTER_TREE_EXPORT — no module-specific export cap
        $this->grantClusterRoleOnOrganization(
            $user,
            $cluster,
            [Capability::CLUSTER_TREE_EXPORT],
            'export_only_no_module'
        );

        // CLUSTER_TREE_EXPORT alone must NOT enable the module's export
        // capability — same parallel-grant contract as Manage.
        $this->assertFalse(
            AccessDecision::can($user, Capability::AUDIT_EXPORT, $childProject),
            'CLUSTER_TREE_EXPORT must NOT imply AUDIT_EXPORT module capability'
        );
    }

    #[Test]
    public function auth_me_surface_exposes_three_cluster_tree_primitives(): void
    {
        $cluster = Organization::factory()->cluster()->create();
        $user = User::factory()->create(['organization_id' => $cluster->id]);
        $this->grantClusterRoleOnOrganization(
            $user,
            $cluster,
            [
                Capability::CLUSTER_TREE_VIEW,
                Capability::CLUSTER_TREE_MANAGE,
                Capability::CLUSTER_TREE_EXPORT,
            ],
            'cluster_full_authority'
        );

        $caps = app(CoreCapabilityProvider::class)
            ->userCapabilities($user);

        // Only the three cluster_tree primitives are asserted here —
        // DASHBOARD_VIEW is a separate capability outside this test's scope.
        $this->assertTrue($caps[Capability::CLUSTER_TREE_VIEW] ?? false);
        $this->assertTrue($caps[Capability::CLUSTER_TREE_MANAGE] ?? false);
        $this->assertTrue($caps[Capability::CLUSTER_TREE_EXPORT] ?? false);
    }

    #[Test]
    public function auth_me_omits_cluster_tree_primitives_when_ungranted(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        // no scoped role — primitives absent

        $caps = app(CoreCapabilityProvider::class)
            ->userCapabilities($user);

        $this->assertFalse($caps[Capability::CLUSTER_TREE_VIEW] ?? false);
        $this->assertFalse($caps[Capability::CLUSTER_TREE_MANAGE] ?? false);
        $this->assertFalse($caps[Capability::CLUSTER_TREE_EXPORT] ?? false);
    }

    #[Test]
    public function super_admin_auth_me_surface_exposes_all_three_cluster_tree_primitives(): void
    {
        // Regression guard: AccessDecision short-circuits super_admin BEFORE
        // the cluster_tree_rescue branch, so a super_admin must still appear
        // with all three cluster_tree primitives enabled on the /auth/me
        // surface. Without this test, an accidental gate inside the engine
        // that requires an explicit scoped-role grant even for super_admin
        // would silently break downstream cluster features (KPIs, Activity
        // Log, etc.) that rely on this surface to render admin controls.
        $org = Organization::factory()->create();
        $user = User::factory()->create([
            'organization_id' => $org->id,
        ]);
        $this->grantCanonicalSuperAdmin($user);

        $caps = app(CoreCapabilityProvider::class)
            ->userCapabilities($user);

        $this->assertTrue($caps[Capability::CLUSTER_TREE_VIEW] ?? false);
        $this->assertTrue($caps[Capability::CLUSTER_TREE_MANAGE] ?? false);
        $this->assertTrue($caps[Capability::CLUSTER_TREE_EXPORT] ?? false);
    }
}

/**
 * ClusterTreePrimitiveSensitiveStubTarget — minimal stub for sensitive-floor
 * assertions in this test class. Mirrors the Phase9DbSensitiveStubTarget pattern
 * from AccessDecisionTest (kept local to avoid coupling the two test classes).
 */
class ClusterTreePrimitiveSensitiveStubTarget extends Model implements SensitivelyScoped
{
    protected $table = 'projects';

    protected $guarded = [];

    public $timestamps = false;

    public function isSensitive(): bool
    {
        return true;
    }

    public function mayAccessSensitive(User $user): bool
    {
        return false;
    }
}
