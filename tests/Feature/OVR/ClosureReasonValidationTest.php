<?php

namespace Tests\Feature\OVR;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\OVR\Enums\ReportStatus;
use App\Modules\OVR\Enums\SeverityLevel;
use App\Modules\OVR\Models\IncidentReport;
use App\Modules\OVR\Models\IncidentType;
use App\Modules\OVR\Models\StatusHistory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ClosureReasonValidationTest — F.0 (audit priority #1).
 *
 * The closure_reason field is REQUIRED and must be at least 5 chars when a
 * report transitions into the Closed state. Without this guard, closed reports
 * could carry NULL/empty closure_reason, which the audit history cannot
 * reconstruct ("report closed by X for ???") and which violates the P1
 * governance requirement that every closure be explainable.
 *
 * Pairs with UpdateStatusRequest rules():
 *   'closure_reason' => [
 *       Rule::requiredIf(fn () => $this->input('status') === ReportStatus::Closed->value),
 *       'nullable', 'string', 'min:5',
 *   ],
 */
class ClosureReasonValidationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Department $dept;

    private User $superAdmin;

    private User $reporter;

    private User $assignee;

    private IncidentType $incidentType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->org = Organization::factory()->create();
        $this->dept = Department::factory()->create(['organization_id' => $this->org->id]);

        $this->superAdmin = User::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'is_active' => true,
        ]);
        $this->superAdmin->assignRole('super_admin');

        $this->reporter = User::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'is_active' => true,
        ]);

        $this->assignee = User::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'is_active' => true,
        ]);

        $this->incidentType = IncidentType::create([
            'name' => 'Medical Error',
            'name_ar' => 'خطأ طبي',
            'is_active' => true,
        ]);
    }

    /**
     * Build a Resolved report (the only state that can transition to Closed).
     */
    private function makeResolvedReport(): IncidentReport
    {
        return IncidentReport::create([
            'organization_id' => $this->org->id,
            'reporter_id' => $this->reporter->id,
            'reporter_name' => $this->reporter->name,
            'reporter_email' => $this->reporter->email,
            'reporter_department_id' => $this->dept->id,
            'incident_datetime' => now(),
            'is_patient_related' => false,
            'informed_authority' => false,
            'incident_type_id' => $this->incidentType->id,
            'incident_description' => 'closure validation fixture',
            'immediate_action_required' => false,
            'severity_level' => SeverityLevel::Medium,
            'status' => ReportStatus::Resolved,
            'is_confidential' => false,
            'assigned_to' => $this->assignee->id,
            'assigned_at' => now(),
            'resolved_at' => now(),
        ]);
    }

    public function test_closure_reason_required_when_transitioning_to_closed(): void
    {
        $report = $this->makeResolvedReport();

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->patchJson("/api/ovr/incidents/{$report->report_number}/status", [
                'status' => ReportStatus::Closed->value,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['closure_reason']);

        // Status must NOT have advanced to Closed.
        $this->assertDatabaseHas('ovr_incident_reports', [
            'id' => $report->id,
            'status' => ReportStatus::Resolved->value,
        ]);
        $this->assertDatabaseMissing('ovr_incident_reports', [
            'id' => $report->id,
            'status' => ReportStatus::Closed->value,
        ]);
        $this->assertDatabaseMissing('ovr_status_history', [
            'report_id' => $report->id,
            'to_status' => ReportStatus::Closed->value,
        ]);
    }

    public function test_closure_reason_too_short_when_transitioning_to_closed(): void
    {
        $report = $this->makeResolvedReport();

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->patchJson("/api/ovr/incidents/{$report->report_number}/status", [
                'status' => ReportStatus::Closed->value,
                'closure_reason' => 'no',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['closure_reason']);

        $this->assertDatabaseHas('ovr_incident_reports', [
            'id' => $report->id,
            'status' => ReportStatus::Resolved->value,
        ]);
        $this->assertDatabaseMissing('ovr_status_history', [
            'report_id' => $report->id,
            'to_status' => ReportStatus::Closed->value,
        ]);
    }

    public function test_closure_reason_accepted_when_meeting_min_length(): void
    {
        $report = $this->makeResolvedReport();

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->patchJson("/api/ovr/incidents/{$report->report_number}/status", [
                'status' => ReportStatus::Closed->value,
                'closure_reason' => 'Risk mitigated by updating SOP and retraining staff.',
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('ovr_incident_reports', [
            'id' => $report->id,
            'status' => ReportStatus::Closed->value,
            'closure_reason' => 'Risk mitigated by updating SOP and retraining staff.',
            'closed_by' => $this->superAdmin->id,
        ]);

        $history = StatusHistory::query()
            ->where('report_id', $report->id)
            ->where('to_status', ReportStatus::Closed->value)
            ->first();

        $this->assertNotNull($history, 'a StatusHistory row should record the closure transition');
        $this->assertSame(ReportStatus::Resolved->value, $history->from_status);
        $this->assertSame(ReportStatus::Closed->value, $history->to_status);
        $this->assertSame($this->superAdmin->id, $history->changed_by);
    }

    public function test_closure_reason_unrestricted_for_non_closed_transitions(): void
    {
        // A report that can transition into UnderReview must not require closure_reason.
        $report = IncidentReport::create([
            'organization_id' => $this->org->id,
            'reporter_id' => $this->reporter->id,
            'reporter_name' => $this->reporter->name,
            'reporter_email' => $this->reporter->email,
            'reporter_department_id' => $this->dept->id,
            'incident_datetime' => now(),
            'is_patient_related' => false,
            'informed_authority' => false,
            'incident_type_id' => $this->incidentType->id,
            'incident_description' => 'non-closure transition fixture',
            'immediate_action_required' => false,
            'severity_level' => SeverityLevel::High,
            'status' => ReportStatus::New,
            'is_confidential' => false,
        ]);

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->patchJson("/api/ovr/incidents/{$report->report_number}/status", [
                'status' => ReportStatus::UnderReview->value,
                // closure_reason omitted entirely — the validator must not trip.
            ]);

        $response->assertStatus(200);
        $response->assertJsonMissingValidationErrors(['closure_reason']);

        $this->assertDatabaseHas('ovr_incident_reports', [
            'id' => $report->id,
            'status' => ReportStatus::UnderReview->value,
            'closure_reason' => null,
        ]);
    }
}
