<?php

namespace Tests\Feature\Api\Shared;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Shared\Models\ActivityLog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

class ActivityLogPrivacyTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    private User $user;

    private ActivityLog $activityLog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $organization = Organization::factory()->create();
        $this->user = User::factory()->create([
            'organization_id' => $organization->id,
            'email' => 'audit-user@example.test',
            'is_active' => true,
        ]);
        $this->grantEngineCapability(
            $this->user,
            [Capability::AUDIT_VIEW, Capability::AUDIT_EXPORT],
            'organization',
            $organization->id
        );

        $this->activityLog = ActivityLog::create([
            'user_id' => $this->user->id,
            'action' => 'privacy_probe',
            'description' => 'Updated sensitive record',
            'loggable_type' => User::class,
            'loggable_id' => $this->user->id,
            'old_values' => [
                'name' => 'Safe old name',
                'token' => 'raw-token-value',
                'plainTextToken' => 'plain-token-value',
                'patient_name' => 'Patient Old',
                'nested' => [
                    'password' => 'old-password',
                    'email' => 'old@example.test',
                ],
            ],
            'new_values' => [
                'name' => 'Safe new name',
                'reporter_email' => 'reporter@example.test',
                'patient_file_number' => 'PF-SECRET',
                'metadata' => [
                    'authorization' => 'Bearer secret',
                ],
            ],
            'metadata' => [
                'safe_context' => 'kept',
                'secret' => 'metadata-secret',
                'user_agent' => 'Sensitive Browser',
                'ip_address' => '203.0.113.9',
            ],
            'ip_address' => '203.0.113.10',
            'user_agent' => 'Raw User Agent',
            'target_user_id' => $this->user->id,
            'scope_type' => 'organization',
            'scope_id' => $organization->id,
            'role' => 'admin',
            'reason' => 'Privacy test',
            'organization_id' => $organization->id,
        ]);
    }

    public function test_activity_log_index_uses_redacted_resource_not_raw_models(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/activity-logs?action=privacy_probe');

        $response->assertOk()
            ->assertJsonPath('data.0.user.id', $this->user->id)
            ->assertJsonPath('data.0.user.name', $this->user->name)
            ->assertJsonPath('data.0.old_values.name', 'Safe old name')
            ->assertJsonPath('data.0.old_values.token', '[REDACTED]')
            ->assertJsonPath('data.0.old_values.plainTextToken', '[REDACTED]')
            ->assertJsonPath('data.0.old_values.nested.password', '[REDACTED]')
            ->assertJsonPath('data.0.new_values.reporter_email', '[REDACTED]')
            ->assertJsonPath('data.0.metadata.safe_context', 'kept')
            // Phase CFA-11 — ip_address / user_agent are now surfaced as
            // redacted shapes (CIDR / browser family), not stripped.
            // The raw values must never appear; the redacted form must.
            ->assertJsonPath('data.0.ip_address', '203.0.113.0/24')
            ->assertJsonPath('data.0.user_agent', 'other')
            ->assertJsonPath('data.0.metadata.ip_address', '[REDACTED]')
            ->assertJsonMissingPath('data.0.target_user_id')
            ->assertJsonMissingPath('data.0.user.email');

        $this->assertSafeActivityLogPayload($response->getContent());
    }

    public function test_activity_log_show_uses_redacted_resource_not_raw_model(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/activity-logs/{$this->activityLog->id}");

        $response->assertOk()
            ->assertJsonPath('data.user.id', $this->user->id)
            ->assertJsonPath('data.user.name', $this->user->name)
            ->assertJsonPath('data.old_values.token', '[REDACTED]')
            ->assertJsonPath('data.new_values.patient_file_number', '[REDACTED]')
            ->assertJsonPath('data.metadata.ip_address', '[REDACTED]')
            ->assertJsonPath('data.ip_address', '203.0.113.0/24')
            ->assertJsonPath('data.user_agent', 'other')
            ->assertJsonMissingPath('data.target_user_id')
            ->assertJsonMissingPath('data.user.email');

        $this->assertSafeActivityLogPayload($response->getContent());
    }

    public function test_activity_log_json_export_uses_redacted_resource_arrays(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->get('/api/activity-logs/export?format=json&action=privacy_probe');

        $response->assertOk();

        $content = $response->streamedContent();
        $payload = json_decode($content, true);

        $this->assertSame(1, $payload['count']);
        $this->assertSame($this->activityLog->id, $payload['logs'][0]['id']);
        $this->assertSame('[REDACTED]', $payload['logs'][0]['old_values']['patient_name']);
        $this->assertSame('[REDACTED]', $payload['logs'][0]['new_values']['metadata']['authorization']);
        // Phase CFA-11 — ip_address / user_agent surface as redacted shapes
        // (CIDR / browser family) in the JSON export too.
        $this->assertSame('203.0.113.0/24', $payload['logs'][0]['ip_address']);
        $this->assertSame('other', $payload['logs'][0]['user_agent']);
        $this->assertArrayNotHasKey('target_user_id', $payload['logs'][0]);
        $this->assertArrayNotHasKey('email', $payload['logs'][0]['user']);
        $this->assertSafeActivityLogPayload($content);
    }

    private function assertSafeActivityLogPayload(string $payload): void
    {
        foreach ([
            'raw-token-value',
            'plain-token-value',
            'Patient Old',
            'old-password',
            'old@example.test',
            'reporter@example.test',
            'PF-SECRET',
            'Bearer secret',
            'metadata-secret',
            'Sensitive Browser',
            '203.0.113.9',
            '203.0.113.10',
            'Raw User Agent',
            'audit-user@example.test',
        ] as $sensitiveValue) {
            $this->assertStringNotContainsString($sensitiveValue, $payload);
        }
    }

    // ============================================================
    // T-E + T-B side-effect: GET /api/activity-logs requires audit.view;
    // a plain viewer (no capability) must get 403, unauthenticated must get
    // 401. Single-row show is same-org scoped and still redacted. CSV export
    // (the default format) must redact the same sensitive fields as JSON.
    // ============================================================

    public function test_activity_log_index_denies_user_without_audit_view_capability(): void
    {
        // Bare authenticated user — no super_admin, no admin, no scoped role,
        // no Spatie role grants AUDIT_VIEW. The engine must deny with 403.
        // (The `member` test-env role is a viewer-tier with can_view_all, so
        // it would still pass the engine view-floor check — we test the truly
        // unprivileged user here instead.)
        $unprivileged = User::factory()->create([
            'organization_id' => $this->user->organization_id,
            'is_active' => true,
        ]);

        $this->actingAs($unprivileged, 'sanctum')
            ->getJson('/api/activity-logs')
            ->assertStatus(403);
    }

    public function test_activity_log_index_requires_authentication(): void
    {
        // T-B: no actingAs → 401 from auth:sanctum before any engine check.
        $this->getJson('/api/activity-logs')->assertStatus(401);
    }

    public function test_activity_log_show_allows_same_org_user_without_audit_view_capability_using_redacted_resource(): void
    {
        $unprivileged = User::factory()->create([
            'organization_id' => $this->user->organization_id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($unprivileged, 'sanctum')
            ->getJson("/api/activity-logs/{$this->activityLog->id}");

        $response->assertOk()
            ->assertJsonPath('data.old_values.token', '[REDACTED]')
            ->assertJsonPath('data.new_values.patient_file_number', '[REDACTED]')
            ->assertJsonMissingPath('data.ip_address')
            ->assertJsonMissingPath('data.user.email');

        $this->assertSafeActivityLogPayload($response->getContent());
    }

    public function test_activity_log_csv_export_uses_redacted_resource_columns(): void
    {
        // The export endpoint defaults to CSV when `format` is omitted. The
        // columns are: التاريخ (created_at), المستخدم (user.name),
        // الإجراء (action), الوصف (description), الهدف (loggable class#id).
        // PII fields (token, patient_name, email, ip_address, user_agent)
        // are never written by exportCsv() in the first place — the
        // assertion is "no raw sensitive value reaches the streamed CSV".
        $response = $this->actingAs($this->user, 'sanctum')
            ->get('/api/activity-logs/export?action=privacy_probe');

        $response->assertOk();
        $this->assertSame('text/csv; charset=UTF-8', $response->headers->get('content-type'));

        $csv = $response->streamedContent();
        // BOM is preserved on exportCsv() (line 129).
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        // Header row contains the Arabic column titles.
        $this->assertStringContainsString('المستخدم', $csv);
        $this->assertStringContainsString('الإجراء', $csv);
        $this->assertStringContainsString('الوصف', $csv);
        $this->assertStringContainsString('الهدف', $csv);

        // The sensitive values seeded in setUp() must not appear anywhere
        // in the CSV body — CSV is the production default for downloads
        // and any leak there is a privacy incident.
        $this->assertSafeActivityLogPayload($csv);
    }
}
