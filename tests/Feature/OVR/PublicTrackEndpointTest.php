<?php

namespace Tests\Feature\OVR;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\OVR\Enums\ReportStatus;
use App\Modules\OVR\Enums\SeverityLevel;
use App\Modules\OVR\Models\IncidentReport;
use App\Modules\OVR\Models\IncidentType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PublicTrackEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Department $department;

    private User $user;

    private IncidentType $incidentType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->organization = Organization::factory()->create();
        $this->department = Department::factory()->create();
        $this->incidentType = IncidentType::create([
            'name' => 'Medical Error',
            'name_ar' => 'خطأ طبي',
            'is_active' => true,
        ]);

        $this->user = User::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $this->user->assignRole('super_admin');
    }

    private function makeReport(array $overrides = []): IncidentReport
    {
        return IncidentReport::create(array_merge([
            'organization_id' => $this->organization->id,
            'reporter_id' => $this->user->id,
            'reporter_name' => $this->user->name,
            'reporter_email' => 'reporter-pii@example.test',
            'reporter_department_id' => $this->department->id,
            'incident_datetime' => now(),
            'is_patient_related' => true,
            'patient_name' => 'Sensitive Patient',
            'patient_file_number' => 'PF-PUB-001',
            'patient_gender' => 'female',
            'patient_dob' => '1985-03-12',
            'informed_authority' => false,
            'incident_type_id' => $this->incidentType->id,
            'incident_description' => 'Public track test incident',
            'actions_taken' => 'Internal action notes',
            'contributing_factors' => ['test'],
            'immediate_action_required' => false,
            'severity_level' => SeverityLevel::High,
            'is_confidential' => false,
            'status' => ReportStatus::UnderReview,
            // Direction B (2026-07-07): the public track route keys on a
            // per-report random tracking_token, not on the enumerable
            // report_number. The migration backfills existing rows; new rows
            // created via the API path also generate one, but in tests we
            // create rows directly, so the test fixture must supply it.
            'tracking_token' => Str::random(64),
        ], $overrides));
    }

    /**
     * P3-H (Option A — intentional).
     *
     * `severity_level` IS exposed by the public tracking endpoint by design.
     * It is a non-sensitive categorization field rendered by the public
     * reporter-tracking UI. This test pins that intentional decision so a
     * future "hardening" pass does not silently remove it.
     */
    public function test_public_track_exposes_severity_level_intentionally(): void
    {
        $report = $this->makeReport([
            'severity_level' => SeverityLevel::Critical,
            'status' => ReportStatus::UnderReview,
        ]);

        // Direction B: the public track route keys on tracking_token
        // (per-report 64-char random), NOT on the enumerable report_number.
        $response = $this->getJson("/api/ovr/track/{$report->tracking_token}");

        $response->assertOk()
            ->assertJsonPath('data.report_number', $report->report_number)
            ->assertJsonPath('data.severity_level', SeverityLevel::Critical->value);
    }

    /**
     * Regression guard: even though `severity_level` is intentionally public,
     * no PII, patient data, internal notes, or assignment metadata may leak
     * through the unauthenticated public tracking endpoint.
     */
    public function test_public_track_does_not_leak_sensitive_fields(): void
    {
        $report = $this->makeReport([
            'status' => ReportStatus::UnderReview,
            'assigned_to' => $this->user->id,
        ]);

        $response = $this->getJson("/api/ovr/track/{$report->tracking_token}");

        $response->assertOk()
            ->assertJsonMissingPath('data.patient_name')
            ->assertJsonMissingPath('data.patient_file_number')
            ->assertJsonMissingPath('data.patient_gender')
            ->assertJsonMissingPath('data.patient_dob')
            ->assertJsonMissingPath('data.reporter_email')
            ->assertJsonMissingPath('data.reporter_id')
            ->assertJsonMissingPath('data.assigned_to')
            ->assertJsonMissingPath('data.assigned_at')
            ->assertJsonMissingPath('data.actions_taken')
            ->assertJsonMissingPath('data.incident_description')
            ->assertJsonMissingPath('data.is_confidential')
            ->assertJsonMissingPath('data.closure_reason');

        $content = $response->getContent();
        $this->assertStringNotContainsString('Sensitive Patient', $content);
        $this->assertStringNotContainsString('PF-PUB-001', $content);
        $this->assertStringNotContainsString('reporter-pii@example.test', $content);
        $this->assertStringNotContainsString('Internal action notes', $content);
    }
}
