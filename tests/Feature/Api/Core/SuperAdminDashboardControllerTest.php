<?php

namespace Tests\Feature\Api\Core;

use App\Modules\Core\Models\LoginAttempt;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Shared\Models\ActivityLog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * SuperAdminDashboardController (M1 governance console) HTTP-level coverage.
 *
 * Routes (app/Modules/Core/Routes/api.php):
 *   GET /api/admin/overview        overview  (super_admin only)
 *   GET /api/admin/security/alerts alerts    (super_admin only)
 *   GET /api/admin/audit/recent    recent    (super_admin only, capped at 50)
 *
 * All three endpoints are mounted under `role:super_admin` middleware. The
 * response shapes intentionally AVOID leaking module content (no project text,
 * no OVR body, no survey answers, no permission_audits old/new values); they
 * return counts, timestamps, status metadata, and actor/target display
 * fields needed for governance context (per PRD 4 'data minimization').
 *
 * Access decision note: super_admin has both the role gate and the engine's
 * blanket override (per the unified authorization spec). Non-super roles are
 * blocked at the route group before any controller body runs.
 */
class SuperAdminDashboardControllerTest extends TestCase
{
    use GrantsEngineCapability;
    use RefreshDatabase;

    protected Organization $orgA;

    protected Department $deptA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->orgA = Organization::factory()->create(['is_active' => true]);
        Organization::factory()->create(['is_active' => false]);
        $this->deptA = Department::factory()->create(['organization_id' => $this->orgA->id]);
    }

    private function makeUser(?Organization $org = null, ?string $role = null, ?Department $dept = null): User
    {
        $user = User::factory()->create([
            'organization_id' => $org?->id,
            'department_id' => $dept?->id,
            'is_active' => true,
        ]);
        if ($role) {
            $user->assignRole($role);
        }

        return $user;
    }

    // ========== Unauthenticated 401 ==========

    public function test_overview_requires_authentication(): void
    {
        $this->getJson('/api/admin/overview')->assertStatus(401);
    }

    public function test_security_alerts_requires_authentication(): void
    {
        $this->getJson('/api/admin/security/alerts')->assertStatus(401);
    }

    public function test_audit_recent_requires_authentication(): void
    {
        $this->getJson('/api/admin/audit/recent')->assertStatus(401);
    }

    // ========== Non-super-admin denial (403) ==========

    public function test_admin_role_cannot_view_overview(): void
    {
        $admin = $this->makeUser($this->orgA, 'admin', $this->deptA);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/overview')
            ->assertStatus(403);
    }

    public function test_admin_role_cannot_view_security_alerts(): void
    {
        $admin = $this->makeUser($this->orgA, 'admin', $this->deptA);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/security/alerts')
            ->assertStatus(403);
    }

    public function test_admin_role_cannot_view_audit_recent(): void
    {
        $admin = $this->makeUser($this->orgA, 'admin', $this->deptA);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/audit/recent')
            ->assertStatus(403);
    }

    public function test_viewer_role_cannot_view_overview(): void
    {
        $viewer = $this->makeUser($this->orgA, 'viewer', $this->deptA);

        $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/admin/overview')
            ->assertStatus(403);
    }

    // ========== Happy path: super_admin ==========

    public function test_super_admin_can_view_overview_with_correct_counts(): void
    {
        // Capture baseline AFTER setUp but BEFORE adding more data. This test
        // verifies that the overview correctly accounts for users we added
        // in this method and ignores the inactive one (the LR-103-style
        // guarantee that is_active=false does NOT inflate active_users).
        $baselineActiveUsers = (int) User::query()->where('is_active', true)->count();
        $baselineTotalUsers = (int) User::query()->count();
        $baselineLoginAttemptsLast24h = (int) LoginAttempt::query()
            ->where('attempted_at', '>=', now()->subHours(24))
            ->count();
        $baselineSuccessfulLogins = (int) LoginAttempt::query()
            ->where('successful', true)
            ->where('attempted_at', '>=', now()->subHours(24))
            ->count();
        $baselineFailedLogins = (int) LoginAttempt::query()
            ->where('successful', false)
            ->where('attempted_at', '>=', now()->subHours(24))
            ->count();

        $superAdmin = $this->makeUser(null, 'super_admin');

        // 2 new ACTIVE users must bump the active counter.
        User::factory()->create([
            'organization_id' => $this->orgA->id,
            'is_active' => true,
        ]);
        User::factory()->create([
            'organization_id' => $this->orgA->id,
            'is_active' => true,
        ]);
        // 1 INACTIVE user must inflate total but NOT the active counter.
        User::factory()->create([
            'organization_id' => $this->orgA->id,
            'is_active' => false,
        ]);

        // Login attempts in the last 24h: 3 successful, 2 failed. None pre-existed.
        LoginAttempt::record('admin@example.test', '10.0.0.1', 'ua', true);
        LoginAttempt::record('admin@example.test', '10.0.0.1', 'ua', true);
        LoginAttempt::record('super@example.test', '10.0.0.2', 'ua', true);
        LoginAttempt::record('attacker@example.test', '10.0.0.3', 'ua', false);
        LoginAttempt::record('attacker@example.test', '10.0.0.3', 'ua', false);

        $response = $this->actingAs($superAdmin, 'sanctum')
            ->getJson('/api/admin/overview');

        $response->assertStatus(200)
            ->assertJsonPath('data.users.active', $baselineActiveUsers + 3) // superAdmin + 2 new active
            ->assertJsonPath('data.users.total', $baselineTotalUsers + 4) // superAdmin + 2 active + 1 inactive
            ->assertJsonPath('data.login_attempts.last_24h.successful', $baselineSuccessfulLogins + 3)
            ->assertJsonPath('data.login_attempts.last_24h.failed', $baselineFailedLogins + 2)
            ->assertJsonPath('data.login_attempts.last_24h.total', $baselineLoginAttemptsLast24h + 5);
    }

    public function test_overview_does_not_leak_module_content(): void
    {
        $superAdmin = $this->makeUser(null, 'super_admin');

        ActivityLog::create([
            'action' => ActivityLog::ACTION_CREATED,
            'description' => 'Some secret description that must not surface in overview',
            'loggable_type' => 'App\\Modules\\Projects\\Models\\Project',
            'loggable_id' => '999',
            'new_values' => ['secret' => 'no-peek'],
            'user_id' => $superAdmin->id,
            'ip_address' => '203.0.113.1',
        ]);

        $response = $this->actingAs($superAdmin, 'sanctum')
            ->getJson('/api/admin/overview')
            ->assertStatus(200);

        $payload = json_encode($response->json());

        // The overview KPI bundle carries counts/timestamps/status metadata only.
        // Module content (project text, OVR body, survey answer, audit values)
        // must NEVER appear, anywhere in the response.
        $this->assertStringNotContainsString('secret description', $payload);
        $this->assertStringNotContainsString('secret', $payload);
        $this->assertStringNotContainsString('no-peek', $payload);
    }

    public function test_super_admin_can_view_security_alerts(): void
    {
        $superAdmin = $this->makeUser(null, 'super_admin');

        // 5 failures from one email — should appear in repeated-failures bucket.
        for ($i = 0; $i < 5; $i++) {
            LoginAttempt::record('attacker@example.test', '203.0.113.5', 'ua', false);
        }
        // 4 failures from one IP — should appear in repeated-ip bucket.
        for ($i = 0; $i < 4; $i++) {
            LoginAttempt::record("u{$i}@example.test", '203.0.113.10', 'ua', false);
        }
        // stale failure — must NOT be counted (older than 1 hour window)
        LoginAttempt::create([
            'email' => 'stale@example.test',
            'ip_address' => '203.0.113.99',
            'user_agent' => 'ua',
            'successful' => false,
            'attempted_at' => now()->subHours(3),
        ]);
        // an access_denied entry in activity_logs — should appear as a denied elevation.
        ActivityLog::create([
            'action' => ActivityLog::ACTION_ACCESS_DENIED,
            'description' => 'blocked',
            'loggable_type' => User::class,
            'loggable_id' => (string) $superAdmin->id,
            'user_id' => $superAdmin->id,
            'ip_address' => '203.0.113.1',
        ]);

        $response = $this->actingAs($superAdmin, 'sanctum')
            ->getJson('/api/admin/security/alerts')
            ->assertStatus(200);

        $payload = $response->json('data');
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('failed_logins_repeated', $payload);
        $this->assertArrayHasKey('access_denied_events', $payload);
        $this->assertArrayHasKey('windows', $payload);

        $emails = array_column($payload['failed_logins_repeated'], 'email');
        $this->assertContains('attacker@example.test', $emails);
        $this->assertNotContains('stale@example.test', $emails);
    }

    public function test_super_admin_audit_recent_caps_at_50_and_orders_newest_first(): void
    {
        $superAdmin = $this->makeUser(null, 'super_admin');

        // Create 60 log entries; ensure descending order and a hard cap.
        for ($i = 0; $i < 60; $i++) {
            ActivityLog::create([
                'action' => ActivityLog::ACTION_LOGIN,
                'description' => "login #{$i}",
                'loggable_type' => User::class,
                'loggable_id' => (string) $superAdmin->id,
                'user_id' => $superAdmin->id,
                'created_at' => now()->subMinutes(60 - $i),
            ]);
        }

        $response = $this->actingAs($superAdmin, 'sanctum')
            ->getJson('/api/admin/audit/recent')
            ->assertStatus(200);

        $items = $response->json('data');
        $this->assertCount(50, $items);

        // Newest first — first item description should have the highest index.
        $this->assertSame('login #59', $items[0]['description']);
        $this->assertSame('login #10', $items[49]['description']);
    }

    public function test_audit_recent_strips_sensitive_payloads(): void
    {
        $superAdmin = $this->makeUser(null, 'super_admin');

        ActivityLog::create([
            'action' => ActivityLog::ACTION_UPDATED,
            'description' => 'top-level description',
            'loggable_type' => User::class,
            'loggable_id' => '99',
            'old_values' => ['secret_old_field' => 'value-A'],
            'new_values' => ['secret_new_field' => 'value-B'],
            'metadata' => ['ip_intelligence' => 'should-not-leak'],
            'user_id' => $superAdmin->id,
        ]);

        $response = $this->actingAs($superAdmin, 'sanctum')
            ->getJson('/api/admin/audit/recent')
            ->assertStatus(200);

        $payload = json_encode($response->json());

        // Top-level summary string IS allowed (briefly references the event).
        $this->assertStringContainsString('top-level description', $payload);
        // Sensitive payloads MUST be stripped, even with the audit summary.
        $this->assertStringNotContainsString('secret_old_field', $payload);
        $this->assertStringNotContainsString('secret_new_field', $payload);
        $this->assertStringNotContainsString('value-A', $payload);
        $this->assertStringNotContainsString('value-B', $payload);
        $this->assertStringNotContainsString('ip_intelligence', $payload);

        // Sensitive keys themselves must not appear at all in the row payload.
        $first = $response->json('data.0');
        $this->assertArrayNotHasKey('old_values', $first);
        $this->assertArrayNotHasKey('new_values', $first);
        $this->assertArrayNotHasKey('metadata', $first);
    }

    public function test_audit_recent_respects_pagination(): void
    {
        $superAdmin = $this->makeUser(null, 'super_admin');

        for ($i = 0; $i < 30; $i++) {
            ActivityLog::create([
                'action' => ActivityLog::ACTION_LOGIN,
                'description' => "audit #{$i}",
                'loggable_type' => User::class,
                'loggable_id' => (string) $superAdmin->id,
                'user_id' => $superAdmin->id,
                'created_at' => now()->subMinutes(30 - $i),
            ]);
        }

        $response = $this->actingAs($superAdmin, 'sanctum')
            ->getJson('/api/admin/audit/recent?per_page=10&page=2')
            ->assertStatus(200);

        // hard ceiling still applies, but client-requested pagination honoured.
        $items = $response->json('data');
        $this->assertCount(10, $items);
    }
}
