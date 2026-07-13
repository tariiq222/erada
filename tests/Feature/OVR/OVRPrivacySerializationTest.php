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
use Tests\TestCase;

class OVRPrivacySerializationTest extends TestCase
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
        $this->grantCanonicalSuperAdmin($this->user);
    }

    public function test_incident_list_omits_patient_and_reporter_pii(): void
    {
        $report = $this->makeIncidentReport();

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/ovr/incidents');

        $response->assertOk()
            ->assertJsonFragment(['report_number' => $report->report_number])
            ->assertJsonMissingPath('data.0.patient_name')
            ->assertJsonMissingPath('data.0.patient_file_number')
            ->assertJsonMissingPath('data.0.patient_gender')
            ->assertJsonMissingPath('data.0.patient_dob')
            ->assertJsonMissingPath('data.0.reporter_email');
    }

    public function test_recent_incidents_omit_patient_and_reporter_pii(): void
    {
        $report = $this->makeIncidentReport();

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/ovr/incidents/recent');

        $response->assertOk()
            ->assertJsonFragment(['report_number' => $report->report_number])
            ->assertJsonMissingPath('data.0.patient_name')
            ->assertJsonMissingPath('data.0.patient_file_number')
            ->assertJsonMissingPath('data.0.patient_gender')
            ->assertJsonMissingPath('data.0.patient_dob')
            ->assertJsonMissingPath('data.0.reporter_email');
    }

    public function test_incident_detail_uses_explicit_detail_resource_for_patient_fields(): void
    {
        $report = $this->makeIncidentReport();

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/ovr/incidents/{$report->report_number}");

        $response->assertOk()
            ->assertJsonPath('data.privacy_mode', 'detail')
            ->assertJsonPath('data.patient_name', 'Sensitive Patient')
            ->assertJsonPath('data.patient_file_number', 'PF-PRIV-001')
            ->assertJsonPath('data.patient_gender', 'female')
            ->assertJsonPath('data.patient_dob', '1985-03-12')
            ->assertJsonPath('data.reporter_email', 'reporter-pii@example.test');
    }

    public function test_public_tracking_never_exposes_patient_or_reporter_pii(): void
    {
        $report = $this->makeIncidentReport(['status' => ReportStatus::New]);

        // Direction B (2026-07-07): the public track route keys on the
        // per-report random tracking_token, NOT on the enumerable
        // report_number. The model boot auto-stamps a token on every
        // IncidentReport::create(), so any report produced by
        // makeIncidentReport carries one.
        $response = $this->getJson("/api/ovr/track/{$report->tracking_token}");

        $response->assertOk()
            ->assertJsonPath('data.report_number', $report->report_number)
            ->assertJsonMissingPath('data.patient_name')
            ->assertJsonMissingPath('data.patient_file_number')
            ->assertJsonMissingPath('data.patient_gender')
            ->assertJsonMissingPath('data.patient_dob')
            ->assertJsonMissingPath('data.reporter_email');
    }

    private function makeIncidentReport(array $overrides = []): IncidentReport
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
            'patient_file_number' => 'PF-PRIV-001',
            'patient_gender' => 'female',
            'patient_dob' => '1985-03-12',
            'informed_authority' => false,
            'incident_type_id' => $this->incidentType->id,
            'incident_description' => 'Privacy regression incident',
            'actions_taken' => 'Initial action',
            'contributing_factors' => ['privacy'],
            'immediate_action_required' => false,
            'severity_level' => SeverityLevel::High,
            'status' => ReportStatus::Draft,
            'is_confidential' => false,
        ], $overrides));
    }
}
