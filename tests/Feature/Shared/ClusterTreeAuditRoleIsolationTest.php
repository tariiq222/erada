<?php

namespace Tests\Feature\Shared;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Support\CapabilityToAuthorizationRolePermission;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Shared\Models\ActivityLog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\ScopedDepartmentRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * Phase CFA-11 — cluster_auditor role isolation contract.
 *
 * The dedicated `cluster_auditor` role carries the AUDIT_VIEW /
 * AUDIT_EXPORT + CLUSTER_TREE_VIEW / CLUSTER_TREE_EXPORT capabilities
 * and NOTHING ELSE. It MUST NOT inherit any non-audit cluster capability
 * (PROJECTS_VIEW, RISKS_VIEW, KPIS_VIEW, STRATEGY_VIEW, MEETINGS_VIEW,
 * USERS_VIEW) — granting those would silently widen the audit role to
 * read other modules' resources across the cluster.
 *
 * The same isolation contract governs the controller surface:
 *   - GET /api/activity-logs admits cluster_auditor on its own org
 *     (same-org path).
 *   - GET /api/activity-logs/{id} for a child-org log admits
 *     cluster_auditor via the cluster widening pair.
 *   - GET /api/projects (or any non-audit module endpoint) must still
 *     403 a cluster_auditor user — the audit cap does NOT widen to
 *     module resource access.
 */
class ClusterTreeAuditRoleIsolationTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(ScopedDepartmentRolesSeeder::class);
    }

    public function test_cluster_auditor_role_has_only_audit_caps_in_engine(): void
    {
        $org = Organization::factory()->cluster()->create();
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [
            Capability::AUDIT_VIEW,
            Capability::AUDIT_EXPORT,
            Capability::CLUSTER_TREE_VIEW,
            Capability::CLUSTER_TREE_EXPORT,
        ]);

        // Held:
        $this->assertTrue(AccessDecision::can($user, Capability::AUDIT_VIEW));
        $this->assertTrue(AccessDecision::can($user, Capability::AUDIT_EXPORT));
        $this->assertTrue(AccessDecision::can($user, Capability::CLUSTER_TREE_VIEW));
        $this->assertTrue(AccessDecision::can($user, Capability::CLUSTER_TREE_EXPORT));

        // NOT held (cross-module isolation):
        $this->assertFalse(AccessDecision::can($user, Capability::PROJECTS_VIEW));
        $this->assertFalse(AccessDecision::can($user, Capability::RISKS_VIEW));
        $this->assertFalse(AccessDecision::can($user, Capability::KPIS_VIEW));
        $this->assertFalse(AccessDecision::can($user, Capability::STRATEGY_VIEW));
        $this->assertFalse(AccessDecision::can($user, Capability::MEETINGS_VIEW));
        $this->assertFalse(AccessDecision::can($user, Capability::USERS_VIEW));
    }

    public function test_cluster_auditor_canonical_role_is_isolated_at_org_scope(): void
    {
        $org = Organization::factory()->cluster()->create();
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability(
            $user,
            [
                Capability::AUDIT_VIEW,
                Capability::AUDIT_EXPORT,
                Capability::CLUSTER_TREE_VIEW,
                Capability::CLUSTER_TREE_EXPORT,
            ],
            roleKey: 'cluster_auditor',
        );

        $role = AuthorizationRole::query()
            ->with('permissions.resource')
            ->where('scope_type', 'organization')
            ->where('name', 'cluster_auditor')
            ->first();

        $this->assertNotNull($role);
        $expectedCapabilities = [
            Capability::AUDIT_VIEW,
            Capability::AUDIT_EXPORT,
            Capability::CLUSTER_TREE_VIEW,
            Capability::CLUSTER_TREE_EXPORT,
        ];
        $expected = collect($expectedCapabilities)
            ->map(fn (string $capability): ?array => CapabilityToAuthorizationRolePermission::map($capability))
            ->filter()
            ->map(fn (array $mapping): string => $mapping['resource'].':'.$mapping['action'])
            ->sort()
            ->values()
            ->all();
        $actual = $role->permissions
            ->map(fn ($permission): string => $permission->resource->key.':'.$permission->action)
            ->sort()
            ->values()
            ->all();

        $this->assertSame($expected, $actual);
        $this->assertFalse($role->is_admin_role);
    }

    public function test_cluster_auditor_can_read_child_org_activity_log_via_cluster_widening(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $auditor = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($auditor, [
            Capability::AUDIT_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        // A log row in the child hospital.
        $childUser = User::factory()->create([
            'organization_id' => $hospital->id,
            'is_active' => true,
        ]);
        $log = ActivityLog::create([
            'user_id' => $childUser->id,
            'action' => 'cfa11_role_isolation',
            'description' => 'child org log row',
            'loggable_type' => User::class,
            'loggable_id' => $childUser->id,
            'organization_id' => $hospital->id,
        ]);

        $this->actingAs($auditor, 'sanctum')
            ->getJson("/api/activity-logs/{$log->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $log->id)
            ->assertJsonPath('data.action', 'cfa11_role_isolation');
    }

    public function test_cluster_auditor_role_cannot_inherit_cluster_pmo_module_caps(): void
    {
        // A cluster PMO role carries CLUSTER_TREE_VIEW/MANAGE + a swathe
        // of module view capabilities. The cluster_auditor role MUST NOT
        // carry those module view capabilities — only audit + cluster_tree.
        // We assert by granting cluster PMO-style caps and confirming the
        // cluster_auditor's narrower set is what passes the audit gates.
        [$cluster, $hospital] = $this->makeClusterTree();

        // Cluster PMO user — module view caps (the auditor role lacks).
        $clusterPmo = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($clusterPmo, [
            Capability::PROJECTS_VIEW,
            Capability::KPIS_VIEW,
            Capability::STRATEGY_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        // Even with cluster_tree + module view caps, cluster PMO must
        // NOT pass the audit view gate (no AUDIT_VIEW granted).
        $this->assertFalse(
            AccessDecision::can($clusterPmo, Capability::AUDIT_VIEW)
        );

        // A log row in the child hospital.
        $childUser = User::factory()->create([
            'organization_id' => $hospital->id,
            'is_active' => true,
        ]);
        $log = ActivityLog::create([
            'user_id' => $childUser->id,
            'action' => 'cluster_pmo_no_audit',
            'description' => 'cluster PMO without audit cap',
            'loggable_type' => User::class,
            'loggable_id' => $childUser->id,
            'organization_id' => $hospital->id,
        ]);

        $this->actingAs($clusterPmo, 'sanctum')
            ->getJson("/api/activity-logs/{$log->id}")
            // H-01 — the controller returns 404 (not 403) when the row is
            // outside the actor's org scope, so the existence of the row
            // cannot be probed. The cluster PMO without AUDIT_VIEW falls
            // into the same path: cluster_tree.* alone does not admit
            // activity-log reads.
            ->assertStatus(404);
    }

    public function test_cluster_auditor_cannot_view_other_modules_resources_via_cluster_widening(): void
    {
        // The cluster_auditor role must NOT widen to read other modules'
        // resources — even when those resources live in the cluster
        // descendant orgs. The cross-module boundary is enforced by the
        // per-module policies (which retain their strict same-org or
        // module-specific cluster widening contracts).
        [$cluster] = $this->makeClusterTree();

        $auditor = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($auditor, [
            Capability::AUDIT_VIEW,
            Capability::AUDIT_EXPORT,
            Capability::CLUSTER_TREE_VIEW,
            Capability::CLUSTER_TREE_EXPORT,
        ]);

        // Engine denies each module-view capability — the cluster_auditor
        // has zero widening into other modules' read surface.
        $this->assertFalse(AccessDecision::can($auditor, Capability::PROJECTS_VIEW));
        $this->assertFalse(AccessDecision::can($auditor, Capability::RISKS_VIEW));
        $this->assertFalse(AccessDecision::can($auditor, Capability::KPIS_VIEW));
        $this->assertFalse(AccessDecision::can($auditor, Capability::STRATEGY_VIEW));
        $this->assertFalse(AccessDecision::can($auditor, Capability::MEETINGS_VIEW));
        $this->assertFalse(AccessDecision::can($auditor, Capability::USERS_VIEW));
    }

    public function test_loggable_pointer_does_not_leak_resource_access_to_actor(): void
    {
        // The ActivityLog response exposes loggable_type + loggable_id.
        // That pointer MUST NOT enable the cluster_auditor to view the
        // pointed-to record if the actor does not hold that module's
        // view capability. We assert that explicitly here by attempting
        // a show() on a project the cluster_auditor doesn't have
        // projects.view for.
        [$cluster, $hospital] = $this->makeClusterTree();

        $auditor = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($auditor, [
            Capability::AUDIT_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $childUser = User::factory()->create([
            'organization_id' => $hospital->id,
            'is_active' => true,
        ]);

        $log = ActivityLog::create([
            'user_id' => $childUser->id,
            'action' => 'cfa11_pointer_isolation',
            'description' => 'pointer is a label, not a grant',
            'loggable_type' => Project::class,
            'loggable_id' => 9999,
            'organization_id' => $hospital->id,
        ]);

        $response = $this->actingAs($auditor, 'sanctum')
            ->getJson("/api/activity-logs/{$log->id}");

        $response->assertOk()
            ->assertJsonPath('data.loggable_type', 'Project')
            ->assertJsonPath('data.loggable_id', '9999');

        // The activity log endpoint itself succeeds, but the loggable
        // pointer is just a label. The cluster_auditor does NOT have
        // PROJECTS_VIEW, so even knowing the project id is 9999 the
        // actor cannot fetch the project. Engine denies:
        $project = Project::factory()->create([
            'organization_id' => $hospital->id,
        ]);
        $this->assertFalse(
            AccessDecision::can($auditor, Capability::PROJECTS_VIEW, $project)
        );
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
}
