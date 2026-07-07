<?php

namespace Tests\Feature\Tasks;

use App\Modules\Core\Authorization\Capability;
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
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * TaskSourceSensitivityLeakTest
 *
 * A task sourced from an OVR confidential IncidentReport inherits a
 * `source_sensitivity = 'confidential'` flag. The expected behavior is
 * that a user WITHOUT the OVR_VIEW_CONFIDENTIAL capability MUST NOT see
 * the task in /api/tasks — even if they have TASKS_VIEW on the same
 * department the incident was reported in.
 *
 * This pins the contract: confidential OVR tasks are need-to-know, not
 * department-broadcast. A department manager who can see all department
 * tasks has no business reading tasks attached to a confidential
 * incident they are not assigned or designated for.
 *
 * Schema note: IncidentReport.id is UUID but tasks.source_id is an
 * unsigned bigint. A direct task -> incident FK is not expressible in
 * the current schema; the engine falls through to project_id when the
 * source row is unresolvable (Task::scopeParent). The tests still
 * represent the leak faithfully: each confidential task is created
 * with source_type=IncidentReport + source_sensitivity='confidential'
 * + a synthetic source_id, exercising the contract that the gate must
 * enforce.
 *
 * Coverage:
 *   - A confidential task on a same-dept confidential incident is hidden
 *     from a user without OVR_VIEW_CONFIDENTIAL.
 *   - The same user CAN see non-confidential tasks on the same incident.
 *   - A user WITH OVR_VIEW_CONFIDENTIAL can see the confidential task.
 *
 * Known bug under investigation: as of Phase R4, TaskController::index()
 * and Task::scopeVisibleTo() do NOT filter on source_sensitivity. These
 * tests assert the EXPECTED behavior (per the Phase R4 spec) and will
 * mark the implementation gap if they fail. The router is responsible for
 * routing the fix; this file does NOT modify backend code.
 */
class TaskSourceSensitivityLeakTest extends TestCase
{
    use GrantsEngineCapability;
    use RefreshDatabase;

    private Organization $org;

    private Department $dept;

    private IncidentReport $confidentialIncident;

    private IncidentReport $normalIncident;

    private User $deptUser;

    private User $ovrAuditor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->org = Organization::factory()->create();
        $this->dept = Department::factory()->create(['organization_id' => $this->org->id]);

        // Reporter user shared by both incidents (kept simple).
        $reporter = User::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'is_active' => true,
        ]);

        // IncidentReport requires incident_type_id (NOT NULL FK) — the
        // factory lives at Database\Factories\IncidentReportFactory but
        // Laravel's nested-namespace discovery looks for it under
        // Modules.OVR.Models, so use the explicit create() pattern that
        // OVRNotificationsTest / OVRModelPolicyNotificationTest already
        // established.
        $incidentType = IncidentType::create([
            'name' => 'Medication Error',
            'name_ar' => 'خطأ دوائي',
            'is_active' => true,
        ]);

        // A confidential incident in the same department.
        $this->confidentialIncident = IncidentReport::create([
            'organization_id' => $this->org->id,
            'reporter_id' => $reporter->id,
            'reporter_name' => $reporter->name,
            'reporter_email' => $reporter->email,
            'reporter_department_id' => $this->dept->id,
            'incident_datetime' => now(),
            'is_patient_related' => false,
            'informed_authority' => false,
            'incident_type_id' => $incidentType->id,
            'incident_description' => 'سرّي: حادث تجريبي للاختبار',
            'actions_taken' => 'Initial containment',
            'contributing_factors' => ['process'],
            'immediate_action_required' => false,
            'severity_level' => SeverityLevel::Medium,
            'status' => ReportStatus::New,
            'is_confidential' => true,
            'due_date' => now()->addDay(),
        ]);

        // A non-confidential sibling incident in the same department.
        $this->normalIncident = IncidentReport::create([
            'organization_id' => $this->org->id,
            'reporter_id' => $reporter->id,
            'reporter_name' => $reporter->name,
            'reporter_email' => $reporter->email,
            'reporter_department_id' => $this->dept->id,
            'incident_datetime' => now(),
            'is_patient_related' => false,
            'informed_authority' => false,
            'incident_type_id' => $incidentType->id,
            'incident_description' => 'حادث مفتوح للاختبار',
            'actions_taken' => 'Initial containment',
            'contributing_factors' => ['process'],
            'immediate_action_required' => false,
            'severity_level' => SeverityLevel::Low,
            'status' => ReportStatus::New,
            'is_confidential' => false,
            'due_date' => now()->addDay(),
        ]);

        // A user in the same department with TASKS_VIEW but NO OVR
        // confidential capability. This is the "should-not-see" actor.
        // The grant is at ORGANIZATION scope because TaskPolicy::viewAny
        // calls AccessDecision::can() with no target, which only matches
        // org-level grants. The list-narrowing still respects department
        // scope via Task::scopeVisibleTo.
        $this->deptUser = User::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($this->deptUser, 'tasks.view', 'organization', $this->org->id);

        // A user in the same department WITH OVR_VIEW_CONFIDENTIAL. This
        // is the "must-see" actor (auditor / investigator). Both
        // capabilities are bundled into ONE scoped-role definition per
        // GrantsEngineCapability's contract (engine single-role-per-scope
        // semantics would otherwise drop one of them).
        $this->ovrAuditor = User::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability(
            $this->ovrAuditor,
            ['tasks.view', Capability::OVR_VIEW_CONFIDENTIAL],
            'organization',
            $this->org->id,
            roleKey: 'ovr_auditor_confidential'
        );
    }

    public function test_confidential_ovr_task_is_hidden_from_user_without_ovr_view_confidential(): void
    {
        // Create a confidential task sourced from the confidential
        // incident, attached to the same department.
        //
        // Schema workaround: IncidentReport.id is UUID; tasks.source_id is
        // bigint. We still set source_type=IncidentReport and
        // source_sensitivity=confidential (the two fields the gate should
        // consult), and let source_id=0 — the engine fails the
        // resolveScopeParent lookup and falls through to project_id per
        // Task::scopeParent(). The leak contract that the gate must fix
        // is the (source_type, source_sensitivity='confidential') pair,
        // independent of whether the source row resolves.
        $this->makeSensitiveTask(
            'SECRET_TASK_TITLE_AAA',
            'confidential'
        );

        $response = $this->actingAs($this->deptUser, 'sanctum')
            ->getJson('/api/unified-tasks');

        $response->assertStatus(200);
        $titles = collect($response->json('data'))->pluck('title')->all();

        // The confidential task must NOT leak into a same-department
        // user that lacks OVR_VIEW_CONFIDENTIAL.
        $this->assertNotContains(
            'SECRET_TASK_TITLE_AAA',
            $titles,
            'Confidential OVR task must be hidden from a dept user without OVR_VIEW_CONFIDENTIAL'
        );
    }

    public function test_non_confidential_ovr_task_is_visible_to_user_without_ovr_view_confidential(): void
    {
        // Negative-control: a task on a NON-confidential OVR incident
        // has source_sensitivity=normal. Same-dept user must see it.
        $this->makeSensitiveTask(
            'OPEN_TASK_TITLE_BBB',
            'normal'
        );

        $response = $this->actingAs($this->deptUser, 'sanctum')
            ->getJson('/api/unified-tasks');

        $response->assertStatus(200);
        $titles = collect($response->json('data'))->pluck('title')->all();

        $this->assertContains(
            'OPEN_TASK_TITLE_BBB',
            $titles,
            'Non-confidential OVR task is visible to a dept user without OVR_VIEW_CONFIDENTIAL'
        );
    }

    public function test_confidential_ovr_task_is_visible_to_user_with_ovr_view_confidential(): void
    {
        $this->makeSensitiveTask(
            'AUDITOR_TASK_TITLE_CCC',
            'confidential'
        );

        $response = $this->actingAs($this->ovrAuditor, 'sanctum')
            ->getJson('/api/unified-tasks');

        $response->assertStatus(200);
        $titles = collect($response->json('data'))->pluck('title')->all();

        $this->assertContains(
            'AUDITOR_TASK_TITLE_CCC',
            $titles,
            'User with OVR_VIEW_CONFIDENTIAL may see the confidential task'
        );
    }

    public function test_show_endpoint_blocks_confidential_task_for_non_privileged_user(): void
    {
        $confidentialTask = $this->makeSensitiveTask(
            'SHOW_BLOCKED_TASK_DDD',
            'confidential'
        );

        $response = $this->actingAs($this->deptUser, 'sanctum')
            ->getJson("/api/unified-tasks/{$confidentialTask->id}");

        // The show path goes through TaskPolicy::view(); that policy must
        // also enforce the source-sensitivity gate. 403 is the engine
        // convention for "you cannot view this specific record."
        $response->assertStatus(403);
    }

    /**
     * Helper: create a Task with the OVR-source metadata stamped on it.
     * source_id is set to 0 because IncidentReport.id is UUID but
     * tasks.source_id is bigint (see file doc-block). The gate the tests
     * pin is the (source_type, source_sensitivity) pair — not the source
     * row lookup.
     */
    private function makeSensitiveTask(string $title, string $sensitivity): Task
    {
        return Task::factory()->create([
            'source_type' => IncidentReport::class,
            'source_id' => 0,
            'source_sensitivity' => $sensitivity,
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'title' => $title,
        ]);
    }
}
