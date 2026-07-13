<?php

namespace Tests\Feature\OVR;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\OVR\Enums\ReportStatus;
use App\Modules\OVR\Enums\SeverityLevel;
use App\Modules\OVR\Models\IncidentReport;
use App\Modules\OVR\Models\IncidentType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * Wave 1 Task 1.7: OVR confidential + cross-org IDOR + export + participants.
 *
 *   - GET /api/ovr/incidents/export: org-scoped (T-G shape)
 *   - GET /api/ovr/incidents/stats: org-bound aggregates
 *   - POST/DELETE /api/ovr/incidents/{report}/participants: cross-org
 *     user injection must be rejected
 *   - PUT/DELETE /api/ovr/incidents/{report}: cross-org mutation IDOR
 *     must 403/404; viewer 403
 *
 * IncidentReportFactory is the new minimal factory added in this task.
 */
class OvrPiiAndIdorTest extends TestCase
{
    use GrantsEngineCapability;
    use RefreshDatabase;

    protected Organization $orgA;

    protected Organization $orgB;

    protected Department $deptA;

    protected Department $deptB;

    protected IncidentType $incidentType;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->orgA = Organization::factory()->create();
        $this->orgB = Organization::factory()->create();
        $this->deptA = Department::factory()->create(['organization_id' => $this->orgA->id]);
        $this->deptB = Department::factory()->create(['organization_id' => $this->orgB->id]);

        $this->incidentType = IncidentType::create([
            'name' => 'Med Error',
            'name_ar' => 'خطأ طبي',
            'is_active' => true,
        ]);
    }

    private function makeUser(Organization $org, Department $dept, ?string $role = null): User
    {
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'is_active' => true,
        ]);
        if ($role) {
            $role === 'super_admin'
                ? $this->grantCanonicalSuperAdmin($user)
                : $this->assignCanonicalRole($user, $role);
        }

        return $user;
    }

    private function makeReport(Organization $org, ?User $reporter = null, bool $confidential = false): IncidentReport
    {
        $reporter ??= $this->makeUser($org, $this->deptA);

        return IncidentReport::create([
            'organization_id' => $org->id,
            'reporter_id' => $reporter->id,
            'reporter_name' => $reporter->name,
            'reporter_email' => $reporter->email,
            'incident_datetime' => now()->subDay(),
            'incident_type_id' => $this->incidentType->id,
            'incident_description' => 'wave1 fixture',
            'severity_level' => SeverityLevel::Medium,
            'status' => ReportStatus::UnderReview,
            'is_confidential' => $confidential,
        ]);
    }

    // ========== GET /api/ovr/incidents/export ==========

    public function test_export_requires_authentication(): void
    {
        $this->getJson('/api/ovr/incidents/export')->assertStatus(401);
    }

    public function test_export_excludes_other_org_reports(): void
    {
        $admin = $this->makeUser($this->orgA, $this->deptA, 'admin');
        $this->grantEngineCapability($admin, Capability::OVR_EXPORT);

        $mine = $this->makeReport($this->orgA);
        $theirs = $this->makeReport($this->orgB);

        $body = $this->actingAs($admin, 'sanctum')
            ->get('/api/ovr/incidents/export')
            ->assertStatus(200)
            ->streamedContent();

        $this->assertStringContainsString($mine->report_number, $body);
        $this->assertStringNotContainsString($theirs->report_number, $body, 'orgA export must not leak orgB reports');
    }

    public function test_export_denies_without_capability(): void
    {
        $viewer = $this->makeUser($this->orgA, $this->deptA, 'viewer');
        $this->makeReport($this->orgA);

        $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/ovr/incidents/export')
            ->assertStatus(403);
    }

    // ========== GET /api/ovr/incidents/stats ==========

    public function test_stats_is_org_bound_for_non_super_admin(): void
    {
        $admin = $this->makeUser($this->orgA, $this->deptA, 'admin');
        $this->grantEngineCapability($admin, Capability::OVR_VIEW_STATISTICS);

        $this->makeReport($this->orgA);
        $this->makeReport($this->orgA);
        $this->makeReport($this->orgB);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/ovr/incidents/stats')
            ->assertStatus(200);

        $body = $response->json();
        // Total reports for orgA admin must be exactly 2 (the two we created in orgA)
        $total = data_get($body, 'total')
            ?? data_get($body, 'stats.total')
            ?? data_get($body, 'data.total');
        $this->assertSame(2, (int) $total, 'orgA stats must not include the orgB report');
    }

    public function test_stats_denies_without_capability(): void
    {
        $viewer = $this->makeUser($this->orgA, $this->deptA, 'viewer');

        $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/ovr/incidents/stats')
            ->assertStatus(403);
    }

    // ========== Participants ==========

    public function test_participant_add_rejects_cross_org_user(): void
    {
        $reporter = $this->makeUser($this->orgA, $this->deptA);
        $report = $this->makeReport($this->orgA, $reporter);

        $orgAAdmin = $this->makeUser($this->orgA, $this->deptA, 'admin');
        $foreignUser = $this->makeUser($this->orgB, $this->deptB);

        $this->actingAs($orgAAdmin, 'sanctum')
            ->postJson("/api/ovr/incidents/{$report->report_number}/participants", [
                'user_id' => $foreignUser->id,
            ])
            ->assertStatus(422);
    }

    public function test_participant_add_accepts_same_org_user(): void
    {
        $reporter = $this->makeUser($this->orgA, $this->deptA);
        $report = $this->makeReport($this->orgA, $reporter);

        $orgAAdmin = $this->makeUser($this->orgA, $this->deptA, 'admin');
        $invitee = $this->makeUser($this->orgA, $this->deptA);

        $this->actingAs($orgAAdmin, 'sanctum')
            ->postJson("/api/ovr/incidents/{$report->report_number}/participants", [
                'user_id' => $invitee->id,
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('ovr_incident_participants', [
            'incident_report_id' => $report->id,
            'user_id' => $invitee->id,
        ]);
    }

    public function test_participant_add_rejects_unknown_user(): void
    {
        $report = $this->makeReport($this->orgA);
        $orgAAdmin = $this->makeUser($this->orgA, $this->deptA, 'admin');

        $this->actingAs($orgAAdmin, 'sanctum')
            ->postJson("/api/ovr/incidents/{$report->report_number}/participants", [
                'user_id' => 999999,
            ])
            ->assertStatus(422);
    }

    public function test_participant_add_to_cross_org_report_is_blocked(): void
    {
        $reporter = $this->makeUser($this->orgB, $this->deptB);
        $report = $this->makeReport($this->orgB, $reporter);

        $orgAAdmin = $this->makeUser($this->orgA, $this->deptA, 'admin');
        $invitee = $this->makeUser($this->orgA, $this->deptA);

        $this->actingAs($orgAAdmin, 'sanctum')
            ->postJson("/api/ovr/incidents/{$report->report_number}/participants", [
                'user_id' => $invitee->id,
            ])
            ->assertStatus(403);
    }

    // ========== PUT/DELETE /incidents/{report} — cross-org + viewer ==========

    public function test_cross_org_admin_cannot_update_incident(): void
    {
        $report = $this->makeReport($this->orgB);
        $orgAAdmin = $this->makeUser($this->orgA, $this->deptA, 'admin');
        $this->grantEngineCapability($orgAAdmin, Capability::OVR_EDIT);

        $this->actingAs($orgAAdmin, 'sanctum')
            ->putJson("/api/ovr/incidents/{$report->report_number}", [
                'incident_description' => 'hijacked',
            ])
            ->assertStatus(403);

        $report->refresh();
        $this->assertSame('wave1 fixture', $report->incident_description);
    }

    public function test_cross_org_admin_cannot_delete_incident(): void
    {
        $report = $this->makeReport($this->orgB);
        $orgAAdmin = $this->makeUser($this->orgA, $this->deptA, 'admin');
        $this->grantEngineCapability($orgAAdmin, Capability::OVR_DELETE);

        $this->actingAs($orgAAdmin, 'sanctum')
            ->deleteJson("/api/ovr/incidents/{$report->report_number}")
            ->assertStatus(403);

        $this->assertDatabaseHas('ovr_incident_reports', ['id' => $report->id]);
    }

    public function test_viewer_cannot_update_incident(): void
    {
        $report = $this->makeReport($this->orgA);
        $viewer = $this->makeUser($this->orgA, $this->deptA, 'viewer');

        $this->actingAs($viewer, 'sanctum')
            ->putJson("/api/ovr/incidents/{$report->report_number}", [
                'incident_description' => 'tampered',
            ])
            ->assertStatus(403);
    }

    public function test_viewer_cannot_delete_incident(): void
    {
        $report = $this->makeReport($this->orgA);
        $viewer = $this->makeUser($this->orgA, $this->deptA, 'viewer');

        $this->actingAs($viewer, 'sanctum')
            ->deleteJson("/api/ovr/incidents/{$report->report_number}")
            ->assertStatus(403);
    }

    public function test_super_admin_can_update_across_orgs(): void
    {
        $report = $this->makeReport($this->orgB);
        $superAdmin = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $this->grantCanonicalSuperAdmin($superAdmin);

        $this->actingAs($superAdmin, 'sanctum')
            ->putJson("/api/ovr/incidents/{$report->report_number}", [
                'incident_description' => 'updated by super',
            ])
            ->assertStatus(200);

        $report->refresh();
        $this->assertSame('updated by super', $report->incident_description);
    }
}
