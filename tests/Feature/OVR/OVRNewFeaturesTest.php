<?php

namespace Tests\Feature\OVR;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\OVR\Enums\ReportStatus;
use App\Modules\OVR\Enums\SeverityLevel;
use App\Modules\OVR\Models\IncidentReport;
use App\Modules\OVR\Models\IncidentType;
use App\Modules\Tasks\Models\Task;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OVRNewFeaturesTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Organization $organization;

    protected Department $department;

    protected IncidentType $incidentType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->organization = Organization::factory()->create();
        $this->department = Department::factory()->create();

        $this->user = User::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalSuperAdmin($this->user);

        $this->incidentType = IncidentType::create([
            'name' => 'Medical Error',
            'name_ar' => 'خطأ طبي',
            'is_active' => true,
        ]);
    }

    private function makeReport(array $override = []): IncidentReport
    {
        return IncidentReport::create(array_merge([
            'organization_id' => $this->organization->id,
            'reporter_id' => $this->user->id,
            'reporter_name' => $this->user->name,
            'reporter_email' => $this->user->email,
            'reporter_department_id' => $this->department->id,
            'incident_datetime' => now(),
            'is_patient_related' => true,
            'patient_name' => 'Secret Patient',
            'patient_file_number' => 'PF-SECRET',
            'informed_authority' => false,
            'incident_type_id' => $this->incidentType->id,
            'incident_description' => 'desc',
            'immediate_action_required' => false,
            'severity_level' => SeverityLevel::High,
            'is_confidential' => false,
            'status' => ReportStatus::New,
        ], $override));
    }

    // ---- Public Track ----

    public function test_public_track_returns_limited_data_without_auth(): void
    {
        $report = $this->makeReport(['status' => ReportStatus::UnderReview]);

        // Direction B (2026-07-07): the public track route keys on the
        // per-report random tracking_token, NOT on the enumerable
        // report_number. The model boot auto-stamps a token on every
        // IncidentReport::create(), so any report produced by makeReport
        // carries one.
        $response = $this->getJson("/api/ovr/track/{$report->tracking_token}");

        $response->assertStatus(200)
            ->assertJsonPath('data.report_number', $report->report_number)
            ->assertJsonPath('data.status', ReportStatus::UnderReview->value);
    }

    public function test_public_track_does_not_leak_patient_or_internal_data(): void
    {
        $report = $this->makeReport(['status' => ReportStatus::UnderReview]);

        $content = $this->getJson("/api/ovr/track/{$report->tracking_token}")->getContent();

        $this->assertStringNotContainsString('Secret Patient', $content);
        $this->assertStringNotContainsString('PF-SECRET', $content);
        $this->assertStringNotContainsString('reporter_email', $content);
    }

    public function test_public_track_hides_draft_reports(): void
    {
        $report = $this->makeReport(['status' => ReportStatus::Draft]);

        $this->getJson("/api/ovr/track/{$report->tracking_token}")
            ->assertStatus(404);
    }

    public function test_public_track_unknown_number_returns_404(): void
    {
        $this->getJson('/api/ovr/track/OVR-9999-9999')
            ->assertStatus(404);
    }

    // ---- Route-model binding by report_number (regression for the UUID-cast bug) ----

    public function test_incident_routes_resolve_by_report_number(): void
    {
        $report = $this->makeReport(['status' => ReportStatus::New]);

        // The frontend addresses incidents by their human report number, not the UUID.
        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/ovr/incidents/{$report->report_number}")
            ->assertStatus(200)
            ->assertJsonPath('data.report_number', $report->report_number);

        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/ovr/incidents/{$report->report_number}/comments")
            ->assertStatus(200);
    }

    // ---- Audit Log ----

    public function test_audit_log_returns_status_history(): void
    {
        $report = $this->makeReport(['status' => ReportStatus::New]);

        $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/ovr/incidents/{$report->report_number}/status", [
                'status' => ReportStatus::UnderReview->value,
                'reason' => 'review started',
            ])->assertStatus(200);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/ovr/incidents/{$report->report_number}/audit");

        $response->assertStatus(200)
            ->assertJsonStructure(['data']);

        $this->assertNotEmpty($response->json('data'));
    }

    // ---- Stats time filter ----

    public function test_stats_supports_period_filter(): void
    {
        // One report this month, one a year ago.
        $this->makeReport(['status' => ReportStatus::New]);
        $old = $this->makeReport(['status' => ReportStatus::New]);
        $old->forceFill(['created_at' => now()->subYear()])->saveQuietly();

        $all = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/ovr/incidents/stats')->json('total');

        $month = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/ovr/incidents/stats?period=month')->json('total');

        $this->assertSame(2, $all);
        $this->assertSame(1, $month);
    }

    public function test_stats_supports_custom_date_range(): void
    {
        $this->makeReport(['status' => ReportStatus::New]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/ovr/incidents/stats?from='.now()->subDay()->toDateString().'&to='.now()->toDateString());

        $response->assertStatus(200)
            ->assertJsonPath('total', 1)
            ->assertJsonStructure(['period' => ['from', 'to']]);
    }

    // ---- PDF export ----

    public function test_export_returns_pdf(): void
    {
        $this->makeReport(['status' => ReportStatus::New]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->get('/api/ovr/incidents/export?format=pdf');

        $response->assertStatus(200);
        $this->assertStringContainsString('application/pdf', $response->headers->get('content-type'));
    }

    // ---- Task auto-creation ----

    public function test_task_created_when_report_assigned(): void
    {
        $handler = User::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);

        $report = $this->makeReport(['status' => ReportStatus::UnderReview]);

        $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/ovr/incidents/{$report->report_number}/status", [
                'status' => ReportStatus::InProgress->value,
                'assigned_to' => $handler->id,
                'reason' => 'assigning handler',
            ])->assertStatus(200);

        $this->assertDatabaseHas('tasks', [
            'assigned_to' => $handler->id,
            'title' => "معالجة حادثة {$report->report_number}",
        ]);
    }

    public function test_task_not_duplicated_on_reassign_to_same_handler(): void
    {
        $handler = User::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);

        $report = $this->makeReport(['status' => ReportStatus::UnderReview]);

        $payload = [
            'status' => ReportStatus::InProgress->value,
            'assigned_to' => $handler->id,
            'reason' => 'assign',
        ];

        $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/ovr/incidents/{$report->report_number}/status", $payload)->assertStatus(200);

        // Move back then forward again to re-trigger assignment path.
        $report->refresh()->update(['status' => ReportStatus::UnderReview, 'assigned_to' => null]);

        $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/ovr/incidents/{$report->report_number}/status", $payload)->assertStatus(200);

        $count = Task::where('assigned_to', $handler->id)
            ->where('title', 'like', "%{$report->report_number}%")
            ->count();

        $this->assertSame(1, $count);
    }
}
