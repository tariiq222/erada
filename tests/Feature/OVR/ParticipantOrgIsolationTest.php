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
 * ParticipantOrgIsolationTest — Fix A.0 (P0 audit priority).
 *
 * Validates the two-gate authorization chain on POST/DELETE
 * /api/ovr/incidents/{report}/participants and the {user} route param:
 *
 *   Gate 1 — engine OVR_EDIT capability against the target report
 *            (positional scope + confidentiality floor).
 *   Gate 2 — same-org isolation: $report->organization_id === $user->organization_id
 *            (super_admin bypasses).
 *
 * This is the cross-org guard that closes the participant-injection IDOR a
 * malicious actor in org A could otherwise exploit to add themselves to a
 * confidential report in org B (and read it as a participant).
 *
 * Note: OvrPiiAndIdorTest already covers happy-path + cross-org-foreign-user
 * (the org of the invitee is wrong) cases. This file focuses on the org of the
 * ACTING user being wrong, plus the route-level engine_capability middleware
 * gate which sits in front of the controller's own gate.
 */
class ParticipantOrgIsolationTest extends TestCase
{
    use GrantsEngineCapability;
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private Department $deptA;

    private Department $deptB;

    private IncidentType $incidentType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Cache::flush();

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

    private function makeUser(Organization $org, Department $dept): User
    {
        return User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'is_active' => true,
        ]);
    }

    private function makeReport(Organization $org): IncidentReport
    {
        $reporter = $this->makeUser($org, $this->deptA);

        return IncidentReport::create([
            'organization_id' => $org->id,
            'reporter_id' => $reporter->id,
            'reporter_name' => $reporter->name,
            'reporter_email' => $reporter->email,
            'reporter_department_id' => $this->deptA->id,
            'incident_datetime' => now()->subDay(),
            'incident_type_id' => $this->incidentType->id,
            'incident_description' => 'cross-org guard fixture',
            'severity_level' => SeverityLevel::Medium,
            'status' => ReportStatus::UnderReview,
            'is_confidential' => false,
        ]);
    }

    public function test_add_participant_to_other_orgs_report_returns_403(): void
    {
        // Reporter in orgB; the orgB report lives there.
        $report = $this->makeReport($this->orgB);

        // Actor: an orgA admin with engine OVR_EDIT — should pass gate 1
        // (engine grant on a same-org-style report), but fail gate 2 because
        // their organization_id is orgA and the report's is orgB.
        $actor = $this->makeUser($this->orgA, $this->deptA);
        $this->grantEngineCapability($actor, Capability::OVR_EDIT);
        Cache::flush();

        $invitee = $this->makeUser($this->orgA, $this->deptA);

        $response = $this->actingAs($actor, 'sanctum')
            ->postJson("/api/ovr/incidents/{$report->report_number}/participants", [
                'user_id' => $invitee->id,
            ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('ovr_incident_participants', [
            'incident_report_id' => $report->id,
            'user_id' => $invitee->id,
        ]);
    }

    public function test_remove_participant_to_other_orgs_report_returns_403(): void
    {
        $report = $this->makeReport($this->orgB);

        $actor = $this->makeUser($this->orgA, $this->deptA);
        $this->grantEngineCapability($actor, Capability::OVR_EDIT);
        Cache::flush();

        $invitee = $this->makeUser($this->orgA, $this->deptA);

        $response = $this->actingAs($actor, 'sanctum')
            ->deleteJson("/api/ovr/incidents/{$report->report_number}/participants/{$invitee->id}");

        $response->assertStatus(403);
    }

    public function test_super_admin_can_add_to_any_org(): void
    {
        $report = $this->makeReport($this->orgB);

        // super_admin has no organization_id and bypasses the org-isolation
        // gate per IncidentReportController::addParticipant().
        $superAdmin = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $this->grantCanonicalSuperAdmin($superAdmin);
        Cache::flush();

        $invitee = $this->makeUser($this->orgB, $this->deptB);

        $response = $this->actingAs($superAdmin, 'sanctum')
            ->postJson("/api/ovr/incidents/{$report->report_number}/participants", [
                'user_id' => $invitee->id,
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('ovr_incident_participants', [
            'incident_report_id' => $report->id,
            'user_id' => $invitee->id,
        ]);
    }

    public function test_add_participant_requires_engine_ovr_edit_grant(): void
    {
        $report = $this->makeReport($this->orgA);

        // A same-org user WITHOUT an engine OVR_EDIT grant. The route's
        // engine_capability:ovr.edit middleware must short-circuit before
        // the controller's gates run, returning 403.
        $unauthorized = $this->makeUser($this->orgA, $this->deptA);
        $this->grantCanonicalViewer($unauthorized);
        Cache::flush();

        $invitee = $this->makeUser($this->orgA, $this->deptA);

        $response = $this->actingAs($unauthorized, 'sanctum')
            ->postJson("/api/ovr/incidents/{$report->report_number}/participants", [
                'user_id' => $invitee->id,
            ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('ovr_incident_participants', [
            'incident_report_id' => $report->id,
            'user_id' => $invitee->id,
        ]);
    }

    public function test_remove_participant_requires_engine_ovr_edit_grant(): void
    {
        $report = $this->makeReport($this->orgA);

        $unauthorized = $this->makeUser($this->orgA, $this->deptA);
        $this->grantCanonicalViewer($unauthorized);
        Cache::flush();

        $invitee = $this->makeUser($this->orgA, $this->deptA);

        $response = $this->actingAs($unauthorized, 'sanctum')
            ->deleteJson("/api/ovr/incidents/{$report->report_number}/participants/{$invitee->id}");

        $response->assertStatus(403);
    }
}
