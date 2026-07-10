<?php

namespace Tests\Feature\Shared;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Shared\Models\ActivityLog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * Phase 1B — Dual serializer for the activity log.
 *
 * The pre-Phase-1 ActivityLogResource emitted a single shape for every
 * authorized actor: full audit detail with in-JSON key redaction (CFA-11).
 * That left a structural hole — a cluster_auditor with read access to a
 * child-org row could still read the full description / reason /
 * old_values / new_values / metadata blob. Those fields carry module
 * business content (task descriptions, project fields, HR PII, OVR
 * reporter data) that are themselves gated by their module capability;
 * looking at the change-log entry that mentions them must not bypass
 * those module gates.
 *
 * Contract (post-Phase 1B):
 *   - Same-org authorized auditor (AUDIT_VIEW on actor.org) →
 *     FULL shape after universal key-based redaction. This is the
 *     same output CFA-11 already produced.
 *   - Cross-org cluster auditor (AUDIT_VIEW + CLUSTER_TREE_VIEW +
 *     ancestor walk) → MINIMAL envelope: identifiers, action,
 *     model label/type, scope, role, coarse actor identity, IP/UA
 *     CIDR/family, timestamps. The five sensitive free-text / change
 *     columns (description, reason, old_values, new_values,
 *     metadata) are returned as JSON `null`. The polymorphic
 *     `loggable_type` / `loggable_id` foreign-key identifiers remain
 *     — stable routing keys — but accessing the pointed-to record
 *     still flows through THAT module's policy.
 *   - super_admin → FULL shape regardless of row.org (org-wide audit).
 *   - Out-of-scope rows (no AUDIT_VIEW, or actor not in
 *     target.ancestor walk) → 404 before serialize (no existence
 *     disclosure).
 *
 * Both shapes share the same scope rules: the same UserActivityLogScope
 * narrows which rows the actor can see in the first place; the policy
 * rescue authorizes cross-org; and the resource decides per row which
 * surface to emit.
 */
class ActivityLogClusterDualSerializerTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_cluster_auditor_cross_org_show_receives_minimal_envelope(): void
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

        $childUser = User::factory()->create([
            'organization_id' => $hospital->id,
            'is_active' => true,
        ]);
        $log = ActivityLog::create([
            'user_id' => $childUser->id,
            'action' => 'cfa_phase1b_show_minimal',
            'description' => 'Detailed task update — must not leak via cross-org cluster',
            'loggable_type' => User::class,
            'loggable_id' => $childUser->id,
            'organization_id' => $hospital->id,
            'role' => 'manager',
            'reason' => 'cluster cross-org enum probe',
            'old_values' => ['name' => 'Old Name', 'phone' => '+966500000000'],
            'new_values' => ['name' => 'New Name', 'job_title' => 'Engineer'],
            'metadata' => ['audit_token' => 'must-not-leak'],
            'ip_address' => '203.0.113.55',
            'user_agent' => 'Mozilla/5.0 Chrome/119.0.0.0 Safari/537.36',
        ]);

        $response = $this->actingAs($auditor, 'sanctum')
            ->getJson("/api/activity-logs/{$log->id}");

        $response->assertOk()
            // Stable routing keys + safe surface: kept.
            ->assertJsonPath('data.id', $log->id)
            ->assertJsonPath('data.action', 'cfa_phase1b_show_minimal')
            ->assertJsonPath('data.action_color', $log->action_color)
            ->assertJsonPath('data.model_label', 'مستخدم') // formatter map: User::class ⇒ 'مستخدم'
            ->assertJsonPath('data.loggable_type', 'User')
            ->assertJsonPath('data.loggable_id', (string) $childUser->id)
            ->assertJsonPath('data.role', 'manager')
            ->assertJsonPath('data.ip_address', '203.0.113.0/24')
            ->assertJsonPath('data.user_agent', 'Chrome')
            ->assertJsonPath('data.user.id', $childUser->id)
            ->assertJsonPath('data.user.name', $childUser->name)
            // Sensitive free-text + change-log columns: null. This prevents
            // the cross-org audit surface from carrying module business
            // content that is gated by its own module capability.
            ->assertJsonPath('data.description', null)
            ->assertJsonPath('data.reason', null)
            ->assertJsonPath('data.old_values', null)
            ->assertJsonPath('data.new_values', null)
            ->assertJsonPath('data.metadata', null);

        // Belt-and-braces: the *content* of the forbidden fields must not
        // appear anywhere in the payload (a nested leak is just as bad).
        $body = $response->getContent();
        $this->assertStringNotContainsString('Detailed task update', $body);
        $this->assertStringNotContainsString('must not leak via cross-org', $body);
        $this->assertStringNotContainsString('Old Name', $body);
        $this->assertStringNotContainsString('966500000000', $body);
        $this->assertStringNotContainsString('audit_token', $body);
        $this->assertStringNotContainsString('must-not-leak', $body);
    }

    public function test_cluster_auditor_cross_org_index_row_uses_minimal_envelope(): void
    {
        // Mixed rows: one in the cluster (same-org from the auditor's
        // perspective) and one in a child hospital (cross-org). The
        // index endpoint emits the appropriate shape per row.
        [$cluster, $hospital] = $this->makeClusterTree();

        $auditor = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($auditor, [
            Capability::AUDIT_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $clusterUser = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $hospitalUser = User::factory()->create([
            'organization_id' => $hospital->id,
            'is_active' => true,
        ]);

        $sameOrgAction = 'cfa_phase1b_same_org';
        ActivityLog::create([
            'user_id' => $clusterUser->id,
            'action' => $sameOrgAction,
            'description' => 'Same-org description must remain visible',
            'loggable_type' => User::class,
            'loggable_id' => $clusterUser->id,
            'organization_id' => $cluster->id,
        ]);

        $crossOrgAction = 'cfa_phase1b_cross_org';
        ActivityLog::create([
            'user_id' => $hospitalUser->id,
            'action' => $crossOrgAction,
            'description' => 'Cross-org description must be null',
            'loggable_type' => User::class,
            'loggable_id' => $hospitalUser->id,
            'organization_id' => $hospital->id,
        ]);

        $response = $this->actingAs($auditor, 'sanctum')
            ->getJson('/api/activity-logs');

        $response->assertOk();

        $rows = collect($response->json('data'))
            ->keyBy('action')
            ->all();

        $this->assertArrayHasKey('cfa_phase1b_same_org', $rows);
        $this->assertArrayHasKey('cfa_phase1b_cross_org', $rows);

        // Same-org row keeps the description (it's still authorized
        // audit detail for the cluster actor on a row they own).
        $this->assertSame(
            'Same-org description must remain visible',
            $rows['cfa_phase1b_same_org']['description']
        );

        // Cross-org row: description is null, no leak string.
        $this->assertNull($rows['cfa_phase1b_cross_org']['description']);
        $this->assertStringNotContainsString(
            'Cross-org description must be null',
            $response->getContent()
        );
    }

    public function test_cluster_auditor_cross_org_show_for_same_org_row_still_returns_full(): void
    {
        // Sanity — Phase 1B must not regress same-org behavior for a
        // cluster auditor. A row in the cluster's own org keeps the
        // FULL resource shape (cross-org cluster widening applies
        // only when row.organization_id != actor.organization_id).
        [$cluster] = $this->makeClusterTree();

        $auditor = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($auditor, [
            Capability::AUDIT_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $clusterUser = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $log = ActivityLog::create([
            'user_id' => $clusterUser->id,
            'action' => 'cfa_phase1b_same_org_show',
            'description' => 'cluster-actor, own-org row',
            'loggable_type' => User::class,
            'loggable_id' => $clusterUser->id,
            'organization_id' => $cluster->id,
            'old_values' => ['name' => 'Old'],
            'new_values' => ['name' => 'New'],
            'reason' => 'cluster-actor own-org reason',
        ]);

        $response = $this->actingAs($auditor, 'sanctum')
            ->getJson("/api/activity-logs/{$log->id}");

        $response->assertOk()
            ->assertJsonPath('data.description', 'cluster-actor, own-org row')
            ->assertJsonPath('data.old_values.name', 'Old')
            ->assertJsonPath('data.new_values.name', 'New')
            ->assertJsonPath('data.reason', 'cluster-actor own-org reason');
    }

    public function test_super_admin_show_returns_full_shape_regardless_of_org(): void
    {
        // super_admin Bypasses the dual shape — super_admin is the
        // ultimate audit backstop and gets the full redacted shape on
        // every row regardless of whether it lives in the actor's org.
        [, $hospital] = $this->makeClusterTree();

        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        $childUser = User::factory()->create([
            'organization_id' => $hospital->id,
            'is_active' => true,
        ]);
        $log = ActivityLog::create([
            'user_id' => $childUser->id,
            'action' => 'cfa_phase1b_superadmin_cross',
            'description' => 'super_admin sees full text',
            'loggable_type' => User::class,
            'loggable_id' => $childUser->id,
            'organization_id' => $hospital->id,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/activity-logs/{$log->id}")
            ->assertOk()
            ->assertJsonPath('data.description', 'super_admin sees full text');
    }

    public function test_out_of_scope_cluster_row_show_returns_404(): void
    {
        // H-01: existence disclosure prevention. A cluster_auditor on
        // cluster A must NOT see a log row in cluster B's descendant
        // — the controller returns 404 before the resource is asked
        // to render anything (no shape leak either way).
        [$clusterA] = $this->makeClusterTree('clusterA', 'hospitalA');
        [, $hospitalB] = $this->makeClusterTree('clusterB', 'hospitalB');

        $auditorA = User::factory()->create([
            'organization_id' => $clusterA->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($auditorA, [
            Capability::AUDIT_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $otherHospitalUser = User::factory()->create([
            'organization_id' => $hospitalB->id,
            'is_active' => true,
        ]);
        $log = ActivityLog::create([
            'user_id' => $otherHospitalUser->id,
            'action' => 'cfa_phase1b_other_cluster',
            'description' => 'must not be visible to other cluster',
            'loggable_type' => User::class,
            'loggable_id' => $otherHospitalUser->id,
            'organization_id' => $hospitalB->id,
        ]);

        $this->actingAs($auditorA, 'sanctum')
            ->getJson("/api/activity-logs/{$log->id}")
            ->assertNotFound();
    }

    public function test_sequential_id_enumeration_without_audit_view_returns_403(): void
    {
        // Phase 1 brief test scope: "sequential-ID enumeration without
        // AUDIT_VIEW" — try ids 1..N from a non-audit user; every
        // request returns 403.
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $unprivileged = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'is_active' => true,
        ]);

        $owner = User::factory()->create([
            'organization_id' => $org->id,
            'is_active' => true,
        ]);

        // Seed a few rows so the IDs exist.
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = ActivityLog::create([
                'user_id' => $owner->id,
                'action' => 'cfa_phase1b_enum',
                'description' => 'row '.$i,
                'loggable_type' => User::class,
                'loggable_id' => $owner->id,
                'organization_id' => $org->id,
            ])->id;
        }

        foreach ($ids as $id) {
            $this->actingAs($unprivileged, 'sanctum')
                ->getJson("/api/activity-logs/{$id}")
                ->assertStatus(403);
        }
    }

    // ──────────────────────────────────────────────────────────────
    // helpers
    // ──────────────────────────────────────────────────────────────

    /**
     * @return array{0: Organization, 1: Organization}
     */
    private function makeClusterTree(string $clusterName = 'cluster1b', string $hospitalName = 'hospital1b'): array
    {
        $cluster = Organization::factory()->cluster()->create(['name' => $clusterName]);
        $hospital = Organization::factory()->hospital()->childOf($cluster)->create(['name' => $hospitalName]);

        return [$cluster, $hospital];
    }
}
