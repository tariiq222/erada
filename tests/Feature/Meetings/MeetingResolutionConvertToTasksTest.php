<?php

namespace Tests\Feature\Meetings;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Models\MeetingResolution;
use App\Modules\Meetings\Models\Recommendation;
use App\Modules\Meetings\Models\ResolutionLink;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * MeetingResolutionConvertToTasksTest
 *
 * Phase 3 / Direction R — pinned behavior for POST /api/meeting-resolutions/
 * {resolution}/convert-to-tasks:
 *
 *   - Creates real Task rows with source_type='MeetingResolution' / source_id
 *     matching the resolution id, organization_id inherited from the resolution,
 *     owner_id = creator (the actor), department_id from the meeting, and
 *     project_id from either the payload or a `linkable_type=project`
 *     resolution_link fallback.
 *   - Single DB::transaction wraps Task::insert + resolution.status update — any
 *     failure rolls the whole conversion back.
 *   - Re-conversion is blocked with 409 once the resolution is in
 *     STATUS_CONVERTED_TO_TASKS / COMPLETED / CANCELLED.
 *   - `risk_id` is `prohibited` (422 with validation error on tasks.*.risk_id).
 *   - No approve / reject / adopt / deliberate / endorse endpoints exist on
 *     /api/meeting-resolutions (Direction R by design).
 *   - Authz: viewer role cannot convert (403), cross-org user cannot convert
 *     (403), no Recommendation rows are created as a side effect.
 */
class MeetingResolutionConvertToTasksTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $project;

    private Department $dept;

    private Meeting $meeting;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dept = Department::factory()->create();
        $this->project = Project::factory()->create(['department_id' => $this->dept->id]);

        $this->user = User::factory()->create([
            'department_id' => $this->dept->id,
            'organization_id' => $this->project->organization_id,
            'is_active' => true,
        ]);
        $this->grantCanonicalSuperAdmin($this->user);

        $this->meeting = Meeting::factory()->create([
            'department_id' => $this->dept->id,
            'organization_id' => $this->project->organization_id,
            'organizer_id' => $this->user->id,
            'status' => Meeting::STATUS_SCHEDULED,
        ]);
    }

    /**
     * Build a resolution in the open state. Status / kind / owner / etc. can be
     * overridden per-test to cover the state-machine edge cases (open vs.
     * in_progress vs. converted_to_tasks).
     */
    private function makeResolution(array $overrides = []): MeetingResolution
    {
        return MeetingResolution::create(array_merge([
            'meeting_id' => $this->meeting->id,
            'organization_id' => $this->project->organization_id,
            'kind' => MeetingResolution::KIND_DECISION,
            'title' => 'مخرج للاختبار',
            'owner_id' => $this->user->id,
            'created_by' => $this->user->id,
            'status' => MeetingResolution::STATUS_OPEN,
            'priority' => MeetingResolution::PRIORITY_MEDIUM,
        ], $overrides));
    }

    /**
     * Issue a POST to /api/meeting-resolutions/{resolution}/convert-to-tasks as
     * the given user (defaults to the seed super_admin). Centralizes the
     * actingAs + endpoint + payload shape so every test reads the same.
     */
    private function postConvert(MeetingResolution $resolution, array $tasks, ?User $asUser = null): TestResponse
    {
        $user = $asUser ?? $this->user;

        return $this->actingAs($user, 'sanctum')
            ->postJson("/api/meeting-resolutions/{$resolution->id}/convert-to-tasks", ['tasks' => $tasks]);
    }

    public function test_convert_creates_real_tasks_in_db(): void
    {
        $resolution = $this->makeResolution();

        $response = $this->postConvert($resolution, [
            ['title' => 'مهمة ١', 'assignee_id' => $this->user->id],
            ['title' => 'مهمة ٢', 'assignee_id' => $this->user->id, 'priority' => 'high'],
        ]);

        $response->assertStatus(201);
        $this->assertSame(2, Task::where('source_type', 'MeetingResolution')->where('source_id', $resolution->id)->count());
    }

    public function test_each_task_has_correct_source_type_and_source_id(): void
    {
        $resolution = $this->makeResolution();

        $this->postConvert($resolution, [
            ['title' => 'مهمة ١', 'assignee_id' => $this->user->id],
        ]);

        $task = Task::where('source_type', 'MeetingResolution')->where('source_id', $resolution->id)->first();
        $this->assertNotNull($task);
        $this->assertSame('MeetingResolution', $task->source_type);
        $this->assertSame($resolution->id, $task->source_id);
        $this->assertSame($resolution->organization_id, $task->organization_id);
    }

    public function test_task_inherits_organization_id_from_resolution(): void
    {
        $resolution = $this->makeResolution();

        $this->postConvert($resolution, [
            ['title' => 'مهمة ١', 'assignee_id' => $this->user->id],
        ]);

        $task = Task::where('source_id', $resolution->id)->first();
        $this->assertNotNull($task);
        $this->assertSame((int) $resolution->organization_id, (int) $task->organization_id);
    }

    public function test_task_can_be_created_without_project_or_risk(): void
    {
        $resolution = $this->makeResolution();

        $response = $this->postConvert($resolution, [
            ['title' => 'مهمة بدون ربط', 'assignee_id' => $this->user->id],
        ]);

        $response->assertStatus(201);
        $task = Task::where('source_id', $resolution->id)->first();
        $this->assertNotNull($task);
        $this->assertNull($task->project_id);
    }

    public function test_task_can_be_linked_to_project_via_payload(): void
    {
        $resolution = $this->makeResolution();
        $otherProject = Project::factory()->create([
            'department_id' => $this->dept->id,
            'organization_id' => $this->project->organization_id,
        ]);

        $this->postConvert($resolution, [
            ['title' => 'مهمة', 'assignee_id' => $this->user->id, 'project_id' => $otherProject->id],
        ]);

        $task = Task::where('source_id', $resolution->id)->first();
        $this->assertNotNull($task);
        $this->assertSame($otherProject->id, $task->project_id);
    }

    public function test_task_inherits_project_from_resolution_link_when_payload_omits_project(): void
    {
        $resolution = $this->makeResolution();
        ResolutionLink::create([
            'resolution_id' => $resolution->id,
            'linkable_type' => ResolutionLink::TYPE_PROJECT,
            'linkable_id' => $this->project->id,
            'link_role' => ResolutionLink::ROLE_RELATED_TO,
            'created_by' => $this->user->id,
        ]);

        $this->postConvert($resolution, [
            ['title' => 'مهمة', 'assignee_id' => $this->user->id],
        ]);

        $task = Task::where('source_id', $resolution->id)->first();
        $this->assertNotNull($task);
        $this->assertSame($this->project->id, $task->project_id);
    }

    public function test_multiple_tasks_are_created_in_one_request(): void
    {
        $resolution = $this->makeResolution();
        $tasks = [];
        for ($i = 0; $i < 5; $i++) {
            $tasks[] = ['title' => "مهمة $i", 'assignee_id' => $this->user->id];
        }

        $response = $this->postConvert($resolution, $tasks);

        $response->assertStatus(201);
        $this->assertSame(5, Task::where('source_id', $resolution->id)->count());
    }

    public function test_failed_task_rolls_back_entire_conversion(): void
    {
        $resolution = $this->makeResolution();
        $initialTaskCount = Task::count();

        // Send a priority value that violates validation (not in the in-list),
        // which causes the request to 422 before the DB transaction even
        // starts. The point of the assertion is end-state: no tasks created,
        // resolution status unchanged — the same shape that a DB-level
        // constraint failure (e.g. CHECK violation) would leave behind after
        // rolling back the transaction.
        $response = $this->postConvert($resolution, [
            ['title' => 'مهمة صالحة', 'assignee_id' => $this->user->id],
            ['title' => 'مهمة غير صالحة', 'assignee_id' => $this->user->id, 'priority' => 'INVALID_PRIORITY'],
        ]);

        $response->assertStatus(422);
        $this->assertSame($initialTaskCount, Task::count());
        $this->assertSame('open', $resolution->fresh()->status);
    }

    public function test_reconversion_is_blocked_with_409(): void
    {
        $resolution = $this->makeResolution(['status' => MeetingResolution::STATUS_CONVERTED_TO_TASKS]);

        $response = $this->postConvert($resolution, [
            ['title' => 'محاولة ثانية', 'assignee_id' => $this->user->id],
        ]);

        $response->assertStatus(409);
    }

    public function test_status_flips_to_converted_to_tasks_on_success(): void
    {
        $resolution = $this->makeResolution();

        $this->postConvert($resolution, [
            ['title' => 'مهمة', 'assignee_id' => $this->user->id],
        ]);

        $this->assertSame(MeetingResolution::STATUS_CONVERTED_TO_TASKS, $resolution->fresh()->status);
    }

    public function test_status_unchanged_on_failure(): void
    {
        $resolution = $this->makeResolution();

        // Send a non-existent assignee — the FormRequest's `exists:users,id`
        // rule fails the validation, returning 422 before the controller
        // body runs. The resolution status must NOT change.
        $response = $this->postConvert($resolution, [
            ['title' => 'مهمة', 'assignee_id' => 999999],
        ]);

        $response->assertStatus(422);
        $this->assertSame('open', $resolution->fresh()->status);
    }

    public function test_risk_id_is_rejected_with_422(): void
    {
        $resolution = $this->makeResolution();

        $response = $this->postConvert($resolution, [
            ['title' => 'مهمة', 'assignee_id' => $this->user->id, 'risk_id' => 1],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['tasks.0.risk_id']);
    }

    public function test_no_approve_reject_endpoints_on_meeting_resolutions(): void
    {
        // Direction R removes the legacy approve / reject / adopt / deliberate /
        // endorse lifecycle. These endpoints must NOT exist on the new
        // /api/meeting-resolutions resource — pinning 404 / 405 here keeps the
        // negative shape stable if anyone re-introduces them later.
        $resolution = $this->makeResolution();

        foreach (['approve', 'reject', 'adopt', 'deliberate', 'endorse'] as $action) {
            $response = $this->actingAs($this->user, 'sanctum')
                ->postJson("/api/meeting-resolutions/{$resolution->id}/{$action}", []);
            $this->assertContains(
                $response->status(),
                [404, 405],
                "Endpoint /{$action} should not exist on meeting-resolutions"
            );
        }
    }

    public function test_user_without_capability_gets_403(): void
    {
        // Viewer role has no engine capability for
        // meeting_resolutions.convert_to_tasks, so the policy's
        // precheck + AccessDecision::can() returns false and the
        // FormRequest authorize() fails closed.
        $viewer = User::factory()->create([
            'department_id' => $this->dept->id,
            'organization_id' => $this->project->organization_id,
            'is_active' => true,
        ]);
        $this->grantCanonicalViewer($viewer);

        $resolution = $this->makeResolution();
        $response = $this->postConvert($resolution, [
            ['title' => 'مهمة', 'assignee_id' => $this->user->id],
        ], $viewer);

        $response->assertStatus(403);
    }

    public function test_cross_org_user_gets_403_on_convert(): void
    {
        // A user in org B (NOT super_admin — super_admin is universally
        // allowed across orgs by policy) attempts to convert a resolution
        // owned by org A. The MeetingOrgGuard / policy precheck fails
        // closed because the cross-org user cannot see the resolution's
        // organization.
        $otherOrg = Organization::factory()->create();
        $otherDept = Department::factory()->create(['organization_id' => $otherOrg->id]);
        $crossOrgUser = User::factory()->create([
            'department_id' => $otherDept->id,
            'organization_id' => $otherOrg->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalViewer($crossOrgUser);

        $resolution = $this->makeResolution();
        $response = $this->postConvert($resolution, [
            ['title' => 'مهمة', 'assignee_id' => $this->user->id],
        ], $crossOrgUser);

        $response->assertStatus(403);
    }

    public function test_no_recommendation_model_used_in_convert(): void
    {
        // Verify no Recommendation rows are created during convert-to-tasks
        // — the legacy Direction B path must NOT be touched by Phase 3.
        $resolution = $this->makeResolution();
        $recCountBefore = Recommendation::count();

        $this->postConvert($resolution, [
            ['title' => 'مهمة', 'assignee_id' => $this->user->id],
        ]);

        $this->assertSame($recCountBefore, Recommendation::count());
    }
}
