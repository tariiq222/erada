<?php

namespace Tests\Feature\OVR;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\OVR\Enums\ReportStatus;
use App\Modules\OVR\Enums\SeverityLevel;
use App\Modules\OVR\Http\Requests\UpdateStatusRequest;
use App\Modules\OVR\Models\IncidentReport;
use App\Modules\OVR\Models\IncidentType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UpdateStatusRequestSideEffectValidationTest — P1 audit fix.
 *
 * The audit found that PATCH /status silently ignored side-effect fields
 * outside the conditional branches in IncidentReportController::updateStatus:
 *   - assigned_to is only consumed on the InProgress transition
 *   - closure_reason is only consumed on the Closed transition
 *   - reopen_reason is not wired yet (no dedicated reopen action exists)
 *
 * UpdateStatusRequest now rejects inconsistent combinations at the
 * validation layer (422) instead of silently dropping them on the floor:
 *   - assigned_to is prohibited when status is Closed or Rejected
 *   - closure_reason is required when status is Closed, prohibited otherwise
 *   - reopen_reason stays nullable for now (documented in UpdateStatusRequest)
 */
class UpdateStatusRequestSideEffectValidationTest extends TestCase
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
     * Build a report preloaded in the source status the test needs.
     */
    private function makeReportInStatus(ReportStatus $status, array $overrides = []): IncidentReport
    {
        return IncidentReport::create(array_merge([
            'organization_id' => $this->org->id,
            'reporter_id' => $this->reporter->id,
            'reporter_name' => $this->reporter->name,
            'reporter_email' => $this->reporter->name.'@example.test',
            'reporter_department_id' => $this->dept->id,
            'incident_datetime' => now(),
            'is_patient_related' => false,
            'informed_authority' => false,
            'incident_type_id' => $this->incidentType->id,
            'incident_description' => "side-effect fixture from {$status->value}",
            'immediate_action_required' => false,
            'severity_level' => SeverityLevel::Medium,
            'status' => $status,
            'is_confidential' => false,
        ], $overrides));
    }

    public function test_assigned_to_rejected_when_status_is_closed(): void
    {
        // Resolved → Closed is a legal transition; closure_reason is the only
        // expected side-effect field. assigned_to must be rejected.
        $report = $this->makeReportInStatus(ReportStatus::Resolved, [
            'assigned_to' => $this->assignee->id,
            'assigned_at' => now(),
            'resolved_at' => now(),
        ]);

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->patchJson("/api/ovr/incidents/{$report->report_number}/status", [
                'status' => ReportStatus::Closed->value,
                'closure_reason' => 'Closed during side-effect validation test',
                'assigned_to' => $this->assignee->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['assigned_to']);
    }

    public function test_assigned_to_rejected_when_status_is_rejected(): void
    {
        // Draft → Rejected is legal; assigned_to is meaningless and must be rejected.
        $report = $this->makeReportInStatus(ReportStatus::Draft);

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->patchJson("/api/ovr/incidents/{$report->report_number}/status", [
                'status' => ReportStatus::Rejected->value,
                'assigned_to' => $this->assignee->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['assigned_to']);
    }

    public function test_closure_reason_required_when_status_is_closed(): void
    {
        // closure_reason omitted on Closed must 422 (already covered by
        // ClosureReasonValidationTest, but re-asserted here under the new
        // side-effect rule so the matrix stays explicit).
        $report = $this->makeReportInStatus(ReportStatus::Resolved, [
            'assigned_to' => $this->assignee->id,
            'assigned_at' => now(),
            'resolved_at' => now(),
        ]);

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->patchJson("/api/ovr/incidents/{$report->report_number}/status", [
                'status' => ReportStatus::Closed->value,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['closure_reason']);
    }

    public function test_closure_reason_rejected_when_status_is_not_closed(): void
    {
        // closure_reason sent on a non-Closed transition must be rejected.
        $report = $this->makeReportInStatus(ReportStatus::New);

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->patchJson("/api/ovr/incidents/{$report->report_number}/status", [
                'status' => ReportStatus::UnderReview->value,
                'closure_reason' => 'Smuggled closure reason on a non-closure transition',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['closure_reason']);
    }

    public function test_reopen_reason_documented_but_not_yet_wired(): void
    {
        // The controller has no dedicated reopen action yet (P0 #6 hardening
        // removed reopened_at/by writes). reopen_reason is therefore accepted
        // as a no-op nullable string today; this test pins the current behavior
        // so a future PR that adds a real reopen path sees a clear signal.
        // Archived → Closed is a legal (un-archive) transition.
        $report = $this->makeReportInStatus(ReportStatus::Archived, [
            'resolved_at' => now()->subDay(),
            'closed_at' => now()->subDay(),
            'closed_by' => $this->superAdmin->id,
            'closure_reason' => 'pre-existing closure (archived reopen fixture)',
        ]);

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->patchJson("/api/ovr/incidents/{$report->report_number}/status", [
                'status' => ReportStatus::Closed->value,
                'closure_reason' => 'Re-closed via side-effect validation test',
                'reopen_reason' => 'no-op reopen_reason (no dedicated action yet)',
            ]);

        $response->assertStatus(200);
        $response->assertJsonMissingValidationErrors(['reopen_reason']);
    }

    public function test_assigned_to_allowed_when_status_is_in_progress(): void
    {
        // The validator must NOT prohibit assigned_to on InProgress — that's the
        // canonical happy path the controller consumes. Test the validator
        // directly to keep this orthogonal to the controller's downstream
        // behavior (createHandlerTask, etc.).
        $validator = validator(
            [
                'status' => ReportStatus::InProgress->value,
                'assigned_to' => $this->assignee->id,
            ],
            (new UpdateStatusRequest)->rules()
        );

        $this->assertFalse(
            $validator->fails(),
            'Validator must NOT reject assigned_to on InProgress: '
                .json_encode($validator->errors()->toArray())
        );
    }
}
