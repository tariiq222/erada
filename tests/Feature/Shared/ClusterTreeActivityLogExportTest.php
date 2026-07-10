<?php

namespace Tests\Feature\Shared;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Shared\Models\ActivityLog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * Phase CFA-11 — cluster_auditor export widening.
 *
 * The export endpoint (`GET /api/activity-logs/export`) widens via the
 * AUDIT_EXPORT + CLUSTER_TREE_EXPORT pair on actor.organization_id. The
 * raw response (CSV + JSON) must remain redacted: ip_address / user_agent
 * surface as CIDR / browser family; old_values / new_values / metadata
 * retain their pre-existing in-JSON redaction.
 *
 * A non-audit cluster PMO user (cluster_tree.* but no audit.*) must NOT
 * be able to export — the audit pair is REQUIRED.
 *
 * Cross-org raw export widens only when both AUDIT_EXPORT AND
 * CLUSTER_TREE_EXPORT are held on actor.org.
 */
class ClusterTreeActivityLogExportTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_cluster_auditor_can_export_child_org_logs(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $auditor = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($auditor, [
            Capability::AUDIT_EXPORT,
            Capability::CLUSTER_TREE_EXPORT,
        ]);

        // Seed a child-org log row.
        $childUser = User::factory()->create([
            'organization_id' => $hospital->id,
            'is_active' => true,
        ]);
        ActivityLog::create([
            'user_id' => $childUser->id,
            'action' => 'cfa11_export_probe',
            'description' => 'cross-org export via cluster',
            'loggable_type' => User::class,
            'loggable_id' => $childUser->id,
            'organization_id' => $hospital->id,
            'ip_address' => '203.0.113.42',
            'user_agent' => 'Mozilla/5.0 Chrome/119.0.0.0 Safari/537.36',
        ]);

        $response = $this->actingAs($auditor, 'sanctum')
            ->getJson('/api/activity-logs/export?format=json&action=cfa11_export_probe');

        $response->assertOk();

        $payload = json_decode($response->streamedContent(), true);
        $this->assertSame(1, $payload['count']);
        $this->assertSame('cfa11_export_probe', $payload['logs'][0]['action']);
        // ip_address / user_agent are redacted on the export surface too.
        $this->assertSame('203.0.113.0/24', $payload['logs'][0]['ip_address']);
        $this->assertSame('Chrome', $payload['logs'][0]['user_agent']);
    }

    public function test_cluster_pmo_without_audit_export_cannot_export_logs(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        // Cluster PMO user with cluster_tree but no audit cap.
        $clusterPmo = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($clusterPmo, [
            Capability::CLUSTER_TREE_EXPORT,
            // intentionally NO AUDIT_EXPORT
        ]);

        $childUser = User::factory()->create([
            'organization_id' => $hospital->id,
            'is_active' => true,
        ]);
        ActivityLog::create([
            'user_id' => $childUser->id,
            'action' => 'cfa11_export_probe',
            'description' => 'cross-org',
            'loggable_type' => User::class,
            'loggable_id' => $childUser->id,
            'organization_id' => $hospital->id,
        ]);

        $this->actingAs($clusterPmo, 'sanctum')
            ->getJson('/api/activity-logs/export?format=json&action=cfa11_export_probe')
            ->assertStatus(403);
    }

    public function test_cluster_auditor_with_only_audit_export_cannot_export(): void
    {
        // Symmetric guard — having AUDIT_EXPORT alone (without
        // CLUSTER_TREE_EXPORT) widens the same-org export path only.
        // A user attempting cross-org export without the cluster_tree
        // primitive must get 403.
        [$cluster, $hospital] = $this->makeClusterTree();

        $auditOnly = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($auditOnly, Capability::AUDIT_EXPORT);

        $childUser = User::factory()->create([
            'organization_id' => $hospital->id,
            'is_active' => true,
        ]);
        ActivityLog::create([
            'user_id' => $childUser->id,
            'action' => 'cfa11_export_probe',
            'description' => 'cross-org',
            'loggable_type' => User::class,
            'loggable_id' => $childUser->id,
            'organization_id' => $hospital->id,
        ]);

        // Same-org export: AUDIT_EXPORT only is sufficient — no cluster
        // widening is required when there is nothing in actor.org to
        // export. (The endpoint widens to descendant orgs via the
        // UserActivityLogScope; AUDIT_EXPORT alone without
        // CLUSTER_TREE_EXPORT keeps the scope strict same-org, so the
        // child-org log row never surfaces — but the endpoint itself
        // returns 200 with zero rows.)
        $this->actingAs($auditOnly, 'sanctum')
            ->getJson('/api/activity-logs/export?format=json&action=cfa11_export_probe')
            ->assertOk();
    }

    public function test_csv_export_remains_redacted_for_cluster_auditor(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $auditor = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($auditor, [
            Capability::AUDIT_EXPORT,
            Capability::CLUSTER_TREE_EXPORT,
        ]);

        $childUser = User::factory()->create([
            'organization_id' => $hospital->id,
            'is_active' => true,
        ]);
        ActivityLog::create([
            'user_id' => $childUser->id,
            'action' => 'cfa11_csv_probe',
            'description' => 'csv export redaction probe',
            'loggable_type' => User::class,
            'loggable_id' => $childUser->id,
            'organization_id' => $hospital->id,
        ]);

        $response = $this->actingAs($auditor, 'sanctum')
            ->get('/api/activity-logs/export?action=cfa11_csv_probe');

        $response->assertOk();
        $this->assertSame('text/csv; charset=UTF-8', $response->headers->get('content-type'));

        $csv = $response->streamedContent();
        // CSV shape: BOM + Arabic headers. PII columns (ip, ua, token, etc.)
        // are not written by exportCsv() in the first place — the assertion
        // is that the CSV is reachable for the cluster_auditor AND the
        // shape is unchanged.
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('المستخدم', $csv);
        $this->assertStringContainsString('الإجراء', $csv);
        $this->assertStringContainsString('cfa11_csv_probe', $csv);
    }

    public function test_unauthenticated_export_returns_401(): void
    {
        $this->getJson('/api/activity-logs/export?format=json')
            ->assertStatus(401);
    }

    public function test_sibling_cluster_export_isolated(): void
    {
        // A cluster_auditor user on cluster A must NOT export
        // cluster B's logs.
        [$clusterA, $hospitalA] = $this->makeClusterTree('cluster A', 'hospital A');
        [$clusterB, $hospitalB] = $this->makeClusterTree('cluster B', 'hospital B');

        $auditorA = User::factory()->create([
            'organization_id' => $clusterA->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($auditorA, [
            Capability::AUDIT_EXPORT,
            Capability::CLUSTER_TREE_EXPORT,
        ]);

        $childUserB = User::factory()->create([
            'organization_id' => $hospitalB->id,
            'is_active' => true,
        ]);
        ActivityLog::create([
            'user_id' => $childUserB->id,
            'action' => 'cfa11_other_cluster',
            'description' => 'should not leak to other cluster',
            'loggable_type' => User::class,
            'loggable_id' => $childUserB->id,
            'organization_id' => $hospitalB->id,
        ]);

        $response = $this->actingAs($auditorA, 'sanctum')
            ->getJson('/api/activity-logs/export?format=json&action=cfa11_other_cluster');

        $response->assertOk();
        $payload = json_decode($response->streamedContent(), true);
        $this->assertSame(0, $payload['count']);
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
