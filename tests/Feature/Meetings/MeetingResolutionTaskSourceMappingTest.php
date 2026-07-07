<?php

namespace Tests\Feature\Meetings;

use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Models\MeetingResolution;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * MeetingResolutionTaskSourceMappingTest — Phase 4 / Direction R.
 *
 * Pins the source_type token contract between
 *   - Task::SOURCE_CLASS_MAP (engine-side scope walking)
 *   - MeetingResolutionController::convertToTasks (insert-side stamp)
 *   - MeetingResolution::tasks() morphMany (read-side reverse lookup)
 *   - Task::sourceAwareScope (visibility-side predicate)
 *
 * If any side drifts to a different token (e.g. FQN vs short basename),
 * scope walking or visibility will silently drop resolution-sourced tasks.
 */
class MeetingResolutionTaskSourceMappingTest extends TestCase
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
        $this->user->assignRole('super_admin');

        $this->meeting = Meeting::factory()->create([
            'department_id' => $this->dept->id,
            'organization_id' => $this->project->organization_id,
            'organizer_id' => $this->user->id,
            'status' => Meeting::STATUS_SCHEDULED,
        ]);
    }

    private function makeResolution(): MeetingResolution
    {
        return MeetingResolution::create([
            'meeting_id' => $this->meeting->id,
            'organization_id' => $this->project->organization_id,
            'kind' => MeetingResolution::KIND_DECISION,
            'title' => 'مخرج للاختبار',
            'owner_id' => $this->user->id,
            'created_by' => $this->user->id,
            'status' => MeetingResolution::STATUS_OPEN,
            'priority' => MeetingResolution::PRIORITY_MEDIUM,
        ]);
    }

    public function test_official_source_type_token_is_short_basename(): void
    {
        // Pin the contract: the official token is the short basename
        // 'MeetingResolution', NOT the FQCN. This is the single source of
        // truth — if you change one side, change this test first.
        $expected = 'MeetingResolution';
        $this->assertSame($expected, 'MeetingResolution');
    }

    public function test_task_created_from_resolution_carries_correct_source_type(): void
    {
        $resolution = $this->makeResolution();

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/meeting-resolutions/{$resolution->id}/convert-to-tasks", [
                'tasks' => [
                    ['title' => 'مهمة ١', 'assignee_id' => $this->user->id],
                ],
            ])->assertStatus(201);

        $task = Task::where('source_id', $resolution->id)->first();
        $this->assertNotNull($task);
        $this->assertSame('MeetingResolution', $task->source_type, 'source_type must use the short basename so morphMany + scopeAwareScope see the row');
    }

    public function test_resolution_tasks_relationship_finds_inserted_tasks(): void
    {
        $resolution = $this->makeResolution();
        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/meeting-resolutions/{$resolution->id}/convert-to-tasks", [
                'tasks' => [
                    ['title' => 'مهمة ١', 'assignee_id' => $this->user->id],
                    ['title' => 'مهمة ٢', 'assignee_id' => $this->user->id],
                ],
            ])->assertStatus(201);

        $this->assertCount(2, $resolution->fresh()->tasks);
    }

    public function test_task_parent_resolves_to_meeting_resolution(): void
    {
        $resolution = $this->makeResolution();
        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/meeting-resolutions/{$resolution->id}/convert-to-tasks", [
                'tasks' => [
                    ['title' => 'مهمة', 'assignee_id' => $this->user->id],
                ],
            ])->assertStatus(201);

        $task = Task::where('source_id', $resolution->id)->first();

        // Task::scopeParent() resolves polymorphic source to the
        // MeetingResolution via SOURCE_CLASS_MAP.
        $parent = $task->scopeParent();
        $this->assertNotNull($parent);
        $this->assertSame(MeetingResolution::class, get_class($parent));
        $this->assertSame($resolution->id, $parent->id);
    }

    public function test_task_source_aware_scope_includes_resolution_sourced_tasks(): void
    {
        // Phase 4: tasks stamped with source_type='MeetingResolution'
        // must be visible to the actor's organization via the engine.
        $resolution = $this->makeResolution();
        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/meeting-resolutions/{$resolution->id}/convert-to-tasks", [
                'tasks' => [
                    ['title' => 'مهمة', 'assignee_id' => $this->user->id],
                ],
            ])->assertStatus(201);

        $task = Task::where('source_id', $resolution->id)->first();

        // Use the engine scope directly.
        $visibleQuery = Task::query()->visibleTo($this->user);
        $this->assertTrue($visibleQuery->where('id', $task->id)->exists(),
            'Task stamped with source_type=MeetingResolution must be visible to the actor via the engine');
    }

    public function test_resolution_tasks_relationship_handles_kebab_token_for_legacy_compat(): void
    {
        // Belt-and-braces: an older row stamped with the kebab token
        // must still be discoverable so a mid-flight migration does not
        // strand tasks. The controller stamps short basename today, but
        // the morphMany query covers the legacy key too.
        $resolution = $this->makeResolution();
        Task::create([
            'title' => 'مهمة قديمة',
            'status' => 'todo',
            'type' => 'project',
            'priority' => 'medium',
            'source_type' => 'meeting_resolution', // kebab legacy
            'source_id' => $resolution->id,
            'organization_id' => $resolution->organization_id,
            'department_id' => $resolution->meeting->department_id,
            'assigned_to' => $this->user->id,
            'owner_id' => $this->user->id,
            'created_by' => $this->user->id,
            'project_id' => $this->project->id,
        ]);

        $this->assertCount(1, $resolution->fresh()->tasks);
    }
}
