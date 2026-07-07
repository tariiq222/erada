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
use App\Modules\OVR\Notifications\ReportSubmittedNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * SubmitReviewerNotificationTest — Fix B.0 (P0 audit priority).
 *
 * IncidentReportController::submit() walks every user in the same organization
 * and dispatches ReportSubmittedNotification to whoever the engine
 * AccessDecision::can() grants OVR_VIEW against the report. The submitter is
 * excluded by id so they don't get a "your own report needs review" email.
 *
 * This test pins three contracts:
 *   1. Engine-granted reviewers DO get the notification.
 *   2. The submitter does NOT get the notification (regression guard).
 *   3. When no scoped role grants OVR_VIEW, the notification is silently
 *      skipped (no exception, no fan-out spam).
 *
 * This replaces the legacy `permission('ovr.view_all')` Spatie fan-out path;
 * see IncidentReportController::submit() for the engine-driven lookup.
 */
class SubmitReviewerNotificationTest extends TestCase
{
    use GrantsEngineCapability;
    use RefreshDatabase;

    private Organization $org;

    private Department $dept;

    private User $reporter;

    private User $reviewer;

    private User $outsider;

    private IncidentType $incidentType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Cache::flush();

        $this->org = Organization::factory()->create();
        $this->dept = Department::factory()->create(['organization_id' => $this->org->id]);

        $this->reporter = User::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'is_active' => true,
        ]);

        $this->reviewer = User::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'is_active' => true,
        ]);

        // No scoped role / no Spatie grant — engine will refuse OVR_VIEW for this user.
        $this->outsider = User::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'is_active' => true,
        ]);

        $this->incidentType = IncidentType::create([
            'name' => 'Med Error',
            'name_ar' => 'خطأ طبي',
            'is_active' => true,
        ]);
    }

    private function makeDraftReport(User $reporter): IncidentReport
    {
        return IncidentReport::create([
            'organization_id' => $this->org->id,
            'reporter_id' => $reporter->id,
            'reporter_name' => $reporter->name,
            'reporter_email' => $reporter->email,
            'reporter_department_id' => $this->dept->id,
            'incident_datetime' => now(),
            'is_patient_related' => false,
            'informed_authority' => false,
            'incident_type_id' => $this->incidentType->id,
            'incident_description' => 'submit notification fixture',
            'immediate_action_required' => false,
            'severity_level' => SeverityLevel::Medium,
            'status' => ReportStatus::Draft,
            'is_confidential' => false,
        ]);
    }

    public function test_submit_notifies_reviewers_with_engine_ovr_view_grant(): void
    {
        Notification::fake();

        // Grant OVR_VIEW to the reviewer via the engine (the only path the
        // controller consults). The reporter has NO grant; the outsider has
        // NO grant. Only the reviewer should receive the notification.
        $this->grantEngineCapability($this->reviewer, Capability::OVR_VIEW);
        Cache::flush();

        // The submit() controller calls authorize('update', $report) which
        // gates on OVR_EDIT. Grant the reporter OVR_EDIT so the draft->new
        // transition actually reaches the notification dispatch.
        $this->grantEngineCapability($this->reporter, Capability::OVR_EDIT);
        Cache::flush();

        $report = $this->makeDraftReport($this->reporter);

        $response = $this->actingAs($this->reporter, 'sanctum')
            ->postJson("/api/ovr/incidents/{$report->report_number}/submit");

        $response->assertStatus(200);

        // Engine-granted reviewer got the notification.
        Notification::assertSentTo(
            $this->reviewer,
            ReportSubmittedNotification::class
        );

        // Submitter never receives their own "needs review" notification.
        Notification::assertNotSentTo($this->reporter, ReportSubmittedNotification::class);

        // Same-org user with no grant is silently skipped.
        Notification::assertNotSentTo($this->outsider, ReportSubmittedNotification::class);

        // Status advanced draft → new.
        $this->assertDatabaseHas('ovr_incident_reports', [
            'id' => $report->id,
            'status' => ReportStatus::New->value,
        ]);
    }

    public function test_submit_silently_skips_when_no_qualifying_reviewer(): void
    {
        Notification::fake();

        // Grant the reporter OVR_EDIT so submit() can transition the draft.
        // Nobody in the org has OVR_VIEW, so the reviewer lookup yields an
        // empty collection and Notification::send is a no-op.
        $this->grantEngineCapability($this->reporter, Capability::OVR_EDIT);
        Cache::flush();

        $report = $this->makeDraftReport($this->reporter);

        $response = $this->actingAs($this->reporter, 'sanctum')
            ->postJson("/api/ovr/incidents/{$report->report_number}/submit");

        $response->assertStatus(200);

        Notification::assertNotSentTo($this->reviewer, ReportSubmittedNotification::class);
        Notification::assertNotSentTo($this->outsider, ReportSubmittedNotification::class);
        Notification::assertNotSentTo($this->reporter, ReportSubmittedNotification::class);

        $this->assertDatabaseHas('ovr_incident_reports', [
            'id' => $report->id,
            'status' => ReportStatus::New->value,
        ]);
    }
}
