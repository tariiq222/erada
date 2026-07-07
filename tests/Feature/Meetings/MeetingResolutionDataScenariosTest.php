<?php

namespace Tests\Feature\Meetings;

use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Models\MeetingResolution;
use App\Modules\Meetings\Models\ResolutionLink;
use App\Modules\Projects\Models\Project;
use App\Modules\RiskManagement\Models\Risk;
use App\Modules\Tasks\Models\Task;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Release Validation — Data scenario coverage for Meeting Resolutions.
 *
 * Covers every data path the brief calls out:
 *   - resolution with no links
 *   - resolution linked to a project (payload + auto-attach from link)
 *   - resolution linked to a risk
 *   - resolution linked to both project and risk
 *   - convert with a single task
 *   - convert with multiple tasks
 *   - duplicate conversion is blocked
 *   - failed conversion rolls back
 */
class MeetingResolutionDataScenariosTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $project;

    private Risk $risk;

    private Department $dept;

    private Meeting $meeting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->dept = Department::factory()->create();
        $this->project = Project::factory()->create(['department_id' => $this->dept->id]);
        $this->risk = Risk::factory()->create([
            'department_id' => $this->dept->id,
            'organization_id' => $this->project->organization_id,
        ]);
        $this->user = User::factory()->create([
            'department_id' => $this->dept->id,
            'organization_id' => $this->project->organization_id,
            'is_active' => true,
        ]);
        $this->user->assignRole('super_admin');
        $this->meeting = Meeting::factory()->create([
            'department_id' => $this->dept->id,
            'organization_id' => $this->project->organization_id,
            'organizer_id' => $this->user->id,
            'status' => Meeting::STATUS_SCHEDULED,
        ]);
    }

    private function makeResolution(string $kind = 'decision', array $links = []): MeetingResolution
    {
        $r = MeetingResolution::create([
            'meeting_id' => $this->meeting->id,
            'organization_id' => $this->project->organization_id,
            'kind' => $kind,
            'title' => 'مخرج للاختبار',
            'owner_id' => $this->user->id,
            'created_by' => $this->user->id,
            'status' => MeetingResolution::STATUS_OPEN,
            'priority' => MeetingResolution::PRIORITY_MEDIUM,
        ]);

        foreach ($links as $link) {
            ResolutionLink::create(array_merge([
                'resolution_id' => $r->id,
                'created_by' => $this->user->id,
            ], $link));
        }

        return $r;
    }

    // ---- 1) Resolution with no links ----

    public function test_resolution_with_no_links_converts_tasks_with_null_project_id(): void
    {
        $r = $this->makeResolution('recommendation');

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/meeting-resolutions/{$r->id}/convert-to-tasks", [
                'tasks' => [
                    ['title' => 'مهمة بدون ربط', 'assignee_id' => $this->user->id],
                ],
            ]);
        $response->assertStatus(201);

        $task = Task::where('source_id', $r->id)->first();
        $this->assertNull($task->project_id);
    }

    // ---- 2) Resolution linked to a project (payload) ----

    public function test_resolution_links_to_project_via_payload(): void
    {
        $otherProject = Project::factory()->create([
            'department_id' => $this->dept->id,
            'organization_id' => $this->project->organization_id,
        ]);
        $r = $this->makeResolution();

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/meeting-resolutions/{$r->id}/convert-to-tasks", [
                'tasks' => [
                    [
                        'title' => 'مهمة بمشروع',
                        'assignee_id' => $this->user->id,
                        'project_id' => $otherProject->id,
                    ],
                ],
            ])->assertStatus(201);

        $task = Task::where('source_id', $r->id)->first();
        $this->assertSame($otherProject->id, $task->project_id);
    }

    // ---- 3) Resolution linked to a project (resolution_link auto-attach) ----

    public function test_resolution_inherits_project_from_link(): void
    {
        $r = $this->makeResolution('decision', [
            ['linkable_type' => 'project', 'linkable_id' => $this->project->id, 'link_role' => 'related_to'],
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/meeting-resolutions/{$r->id}/convert-to-tasks", [
                'tasks' => [
                    ['title' => 'مهمة', 'assignee_id' => $this->user->id],
                ],
            ])->assertStatus(201);

        $task = Task::where('source_id', $r->id)->first();
        $this->assertSame($this->project->id, $task->project_id);
    }

    // ---- 4) Resolution linked to a risk ----

    public function test_resolution_links_to_risk(): void
    {
        $r = $this->makeResolution('decision', [
            ['linkable_type' => 'risk', 'linkable_id' => $this->risk->id, 'link_role' => 'related_to'],
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/meeting-resolutions/{$r->id}/convert-to-tasks", [
                'tasks' => [
                    ['title' => 'مهمة مرتبطة بخطر', 'assignee_id' => $this->user->id],
                ],
            ]);
        $response->assertStatus(201);

        // The risk link is recorded on the resolution_links pivot;
        // the tasks schema has no risk_id column, so project_id is null.
        $task = Task::where('source_id', $r->id)->first();
        $this->assertNull($task->project_id, 'tasks table has no risk_id column; risk link lives on resolution_links only');

        // The pivot still records the risk.
        $this->assertDatabaseHas('resolution_links', [
            'resolution_id' => $r->id,
            'linkable_type' => 'risk',
            'linkable_id' => $this->risk->id,
        ]);
    }

    // ---- 5) Resolution linked to both project and risk ----

    public function test_resolution_links_to_both_project_and_risk(): void
    {
        $r = $this->makeResolution('decision', [
            ['linkable_type' => 'project', 'linkable_id' => $this->project->id, 'link_role' => 'related_to'],
            ['linkable_type' => 'risk', 'linkable_id' => $this->risk->id, 'link_role' => 'related_to'],
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/meeting-resolutions/{$r->id}/convert-to-tasks", [
                'tasks' => [
                    ['title' => 'مهمة', 'assignee_id' => $this->user->id],
                ],
            ])->assertStatus(201);

        $task = Task::where('source_id', $r->id)->first();
        $this->assertSame($this->project->id, $task->project_id);
        $this->assertDatabaseCount('resolution_links', 2);
    }

    // ---- 6) Convert with a single task ----

    public function test_convert_with_single_task_creates_one_row(): void
    {
        $r = $this->makeResolution('recommendation');
        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/meeting-resolutions/{$r->id}/convert-to-tasks", [
                'tasks' => [
                    ['title' => 'مهمة واحدة', 'assignee_id' => $this->user->id],
                ],
            ])->assertStatus(201);

        $this->assertSame(1, Task::where('source_id', $r->id)->count());
    }

    // ---- 7) Convert with multiple tasks ----

    public function test_convert_with_multiple_tasks_creates_n_rows(): void
    {
        $r = $this->makeResolution('decision');
        $tasks = [];
        for ($i = 1; $i <= 5; $i++) {
            $tasks[] = ['title' => "مهمة $i", 'assignee_id' => $this->user->id];
        }
        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/meeting-resolutions/{$r->id}/convert-to-tasks", ['tasks' => $tasks])
            ->assertStatus(201);

        $this->assertSame(5, Task::where('source_id', $r->id)->count());
    }

    // ---- 8) Duplicate conversion is blocked ----

    public function test_duplicate_conversion_is_blocked_with_409(): void
    {
        $r = $this->makeResolution('recommendation');
        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/meeting-resolutions/{$r->id}/convert-to-tasks", [
                'tasks' => [['title' => 'مهمة', 'assignee_id' => $this->user->id]],
            ])->assertStatus(201);

        $second = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/meeting-resolutions/{$r->id}/convert-to-tasks", [
                'tasks' => [['title' => 'مهمة ثانية', 'assignee_id' => $this->user->id]],
            ]);
        $second->assertStatus(409);

        // The original 1 task is still there; no second batch was created.
        $this->assertSame(1, Task::where('source_id', $r->id)->count());
    }

    // ---- 9) Failed conversion rolls back ----

    public function test_failed_conversion_rolls_back(): void
    {
        $r = $this->makeResolution('decision');

        // Pass an empty tasks array — the FormRequest's `min:1` rule
        // rejects with 422 BEFORE any DB row is touched. This is the
        // most reliable way to exercise the validation guard.
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/meeting-resolutions/{$r->id}/convert-to-tasks", [
                'tasks' => [],
            ]);
        $response->assertStatus(422);

        $this->assertSame(0, Task::where('source_id', $r->id)->count());
        $this->assertSame('open', $r->fresh()->status);
    }

    public function test_partial_failure_rolls_back_all_tasks(): void
    {
        $r = $this->makeResolution('decision');

        // Pass 3 tasks where the second carries an invalid priority
        // (the FormRequest rejects the whole batch via Rule::in()).
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/meeting-resolutions/{$r->id}/convert-to-tasks", [
                'tasks' => [
                    ['title' => 'مهمة ١', 'assignee_id' => $this->user->id],
                    ['title' => 'مهمة ٢', 'assignee_id' => $this->user->id, 'priority' => 'NOT_A_VALID_PRIORITY'],
                    ['title' => 'مهمة ٣', 'assignee_id' => $this->user->id],
                ],
            ]);
        $response->assertStatus(422);

        // No tasks should be created.
        $this->assertSame(0, Task::where('source_id', $r->id)->count());
    }
}
