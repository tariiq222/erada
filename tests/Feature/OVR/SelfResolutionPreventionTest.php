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
use Illuminate\Support\Facades\Cache;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * SelfResolutionPreventionTest — P1 audit fix.
 *
 * A reporter who is also the assignee and resolver cannot self-certify their
 * own incident: there must be independent review. The controller now aborts
 * the transition with a 403 + the `ovr.api.self_resolution_forbidden` message
 * when status moves to Resolved/Closed and the actor is both reporter and
 * assignee. super_admin bypasses the check.
 *
 * Coverage:
 *   - reporter == assignee resolves own report → 403 (both Resolved and Closed)
 *   - reporter == assignee moves report to UnderReview → 200 (not a self-resolution)
 *   - super_admin resolves even when reporter == assignee → 200
 *   - reporter BUT NOT assignee resolves report → 200 (legitimate resolution)
 */
class SelfResolutionPreventionTest extends TestCase
{
    use GrantsEngineCapability;
    use RefreshDatabase;

    private Organization $org;

    private Department $dept;

    private User $superAdmin;

    private User $resolver;

    private IncidentType $incidentType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Cache::flush();

        $this->org = Organization::factory()->create();
        $this->dept = Department::factory()->create(['organization_id' => $this->org->id]);

        $this->superAdmin = User::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'is_active' => true,
        ]);
        $this->superAdmin->assignRole('super_admin');

        // resolver acts as both reporter AND assignee in the self-resolving scenarios.
        // Grant OVR_CHANGE_STATUS so the policy layer lets the request through; the
        // self-resolution guard then has a chance to fire (otherwise the 403 would
        // come from the policy, not the new controller check).
        $this->resolver = User::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($this->resolver, Capability::OVR_CHANGE_STATUS);

        $this->incidentType = IncidentType::create([
            'name' => 'Medical Error',
            'name_ar' => 'خطأ طبي',
            'is_active' => true,
        ]);
    }

    /**
     * Build a report where the actor is both reporter and assignee (self-resolution setup).
     * Pre-loaded into UnderReview so the next legal transition into Resolved is meaningful.
     */
    private function makeReportAssignedToSelf(User $actor, array $overrides = []): IncidentReport
    {
        return IncidentReport::create(array_merge([
            'organization_id' => $this->org->id,
            'reporter_id' => $actor->id,
            'reporter_name' => $actor->name,
            'reporter_email' => $actor->name.'@example.test',
            'reporter_department_id' => $this->dept->id,
            'incident_datetime' => now(),
            'is_patient_related' => false,
            'informed_authority' => false,
            'incident_type_id' => $this->incidentType->id,
            'incident_description' => 'self-resolution fixture',
            'immediate_action_required' => false,
            'severity_level' => SeverityLevel::High,
            'status' => ReportStatus::UnderReview,
            'is_confidential' => false,
            'assigned_to' => $actor->id,
            'assigned_at' => now(),
        ], $overrides));
    }

    public function test_reporter_and_assignee_cannot_resolve_own_report(): void
    {
        $report = $this->makeReportAssignedToSelf($this->resolver);

        $response = $this->actingAs($this->resolver, 'sanctum')
            ->patchJson("/api/ovr/incidents/{$report->report_number}/status", [
                'status' => ReportStatus::Resolved->value,
            ]);

        $response->assertStatus(403)
            ->assertJson(['message' => __('ovr.api.self_resolution_forbidden')]);

        // Status must NOT have advanced — the guard rejects before the transaction.
        $this->assertDatabaseHas('ovr_incident_reports', [
            'id' => $report->id,
            'status' => ReportStatus::UnderReview->value,
        ]);
        $this->assertDatabaseMissing('ovr_status_history', [
            'report_id' => $report->id,
            'to_status' => ReportStatus::Resolved->value,
        ]);
    }

    public function test_reporter_and_assignee_cannot_close_own_report(): void
    {
        // Same guard covers the Closed transition (not just Resolved).
        $report = $this->makeReportAssignedToSelf($this->resolver, [
            'status' => ReportStatus::Resolved,
            'resolved_at' => now(),
        ]);

        $response = $this->actingAs($this->resolver, 'sanctum')
            ->patchJson("/api/ovr/incidents/{$report->report_number}/status", [
                'status' => ReportStatus::Closed->value,
                'closure_reason' => 'Self-closure attempt — must be rejected',
            ]);

        $response->assertStatus(403)
            ->assertJson(['message' => __('ovr.api.self_resolution_forbidden')]);

        $this->assertDatabaseHas('ovr_incident_reports', [
            'id' => $report->id,
            'status' => ReportStatus::Resolved->value,
        ]);
        $this->assertDatabaseMissing('ovr_incident_reports', [
            'id' => $report->id,
            'status' => ReportStatus::Closed->value,
        ]);
    }

    public function test_reporter_and_assignee_can_move_to_under_review(): void
    {
        // UnderReview is not a self-resolution; the same actor (reporter + assignee)
        // must still be able to advance the report into review. Self-resolution is
        // specifically about Resolved / Closed, not all transitions.
        $report = IncidentReport::create([
            'organization_id' => $this->org->id,
            'reporter_id' => $this->resolver->id,
            'reporter_name' => $this->resolver->name,
            'reporter_email' => $this->resolver->name.'@example.test',
            'reporter_department_id' => $this->dept->id,
            'incident_datetime' => now(),
            'is_patient_related' => false,
            'informed_authority' => false,
            'incident_type_id' => $this->incidentType->id,
            'incident_description' => 'not a self-resolution fixture',
            'immediate_action_required' => false,
            'severity_level' => SeverityLevel::High,
            'status' => ReportStatus::New,
            'is_confidential' => false,
            'assigned_to' => $this->resolver->id,
            'assigned_at' => now(),
        ]);

        $response = $this->actingAs($this->resolver, 'sanctum')
            ->patchJson("/api/ovr/incidents/{$report->report_number}/status", [
                'status' => ReportStatus::UnderReview->value,
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('ovr_incident_reports', [
            'id' => $report->id,
            'status' => ReportStatus::UnderReview->value,
        ]);
    }

    public function test_super_admin_bypasses_self_resolution_guard(): void
    {
        // Governance override — super_admin can resolve any report even when the
        // actor is both reporter and assignee.
        $report = $this->makeReportAssignedToSelf($this->superAdmin);

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->patchJson("/api/ovr/incidents/{$report->report_number}/status", [
                'status' => ReportStatus::Resolved->value,
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('ovr_incident_reports', [
            'id' => $report->id,
            'status' => ReportStatus::Resolved->value,
        ]);
    }

    public function test_reporter_but_not_assignee_can_still_resolve(): void
    {
        // The reporter is the actor but a different user is the assignee — this is
        // a legitimate resolution and must NOT be blocked.
        $otherAssignee = User::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'is_active' => true,
        ]);

        $report = IncidentReport::create([
            'organization_id' => $this->org->id,
            'reporter_id' => $this->resolver->id, // reporter = actor
            'reporter_name' => $this->resolver->name,
            'reporter_email' => $this->resolver->name.'@example.test',
            'reporter_department_id' => $this->dept->id,
            'incident_datetime' => now(),
            'is_patient_related' => false,
            'informed_authority' => false,
            'incident_type_id' => $this->incidentType->id,
            'incident_description' => 'legitimate resolution fixture',
            'immediate_action_required' => false,
            'severity_level' => SeverityLevel::High,
            'status' => ReportStatus::UnderReview,
            'is_confidential' => false,
            'assigned_to' => $otherAssignee->id, // different from actor
            'assigned_at' => now(),
        ]);

        $response = $this->actingAs($this->resolver, 'sanctum')
            ->patchJson("/api/ovr/incidents/{$report->report_number}/status", [
                'status' => ReportStatus::Resolved->value,
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('ovr_incident_reports', [
            'id' => $report->id,
            'status' => ReportStatus::Resolved->value,
        ]);
    }
}
