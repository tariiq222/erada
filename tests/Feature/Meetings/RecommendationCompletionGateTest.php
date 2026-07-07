<?php

namespace Tests\Feature\Meetings;

use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Models\Recommendation;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Enums\TaskStatus;
use App\Modules\Tasks\Models\Task;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RecommendationCompletionGateTest
 *
 * Ppins the "has_pending_tasks" completion gate on Recommendation::complete():
 * an action_item recommendation CANNOT be marked complete while any non-terminal
 * Task remains attached via source_type=Recommendation / source_id=rec.id.
 *
 * Behavior contract:
 *   - Complete with no tasks or only completed/cancelled tasks -> 200 + status=completed.
 *   - Complete with one or more tasks in {todo, in_progress, in_review, on_hold}
 *     -> 422 + pending_task_ids list (no state change).
 *   - The list endpoint for a closed recommendation must surface completed_at.
 *
 * Independent of the action_item kind assertion in RecommendationControllerTest —
 * these tests stand alone so they can pin the gate even when the surface tests
 * are edited.
 */
class RecommendationCompletionGateTest extends TestCase
{
    use RefreshDatabase;

    private Department $dept;

    private Project $project;

    private Meeting $meeting;

    private Recommendation $rec;

    private $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

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

        $this->rec = Recommendation::create([
            'kind' => Recommendation::KIND_ACTION_ITEM,
            'meeting_id' => $this->meeting->id,
            'title' => 'إجراء للبوابة',
            'assignee_id' => $this->user->id,
            'due_date' => now()->addDays(5)->toDateString(),
            'status' => Recommendation::STATUS_ACCEPTED,
            'priority' => Recommendation::PRIORITY_MEDIUM,
            'organization_id' => $this->project->organization_id,
        ]);
    }

    public function test_completion_with_zero_tasks_succeeds(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/recommendations/{$this->rec->id}/complete");

        $response->assertStatus(200)
            ->assertJsonPath('recommendation.status', Recommendation::STATUS_COMPLETED);
        $this->assertNotNull($this->rec->fresh()->completed_at);
    }

    public function test_completion_with_only_completed_tasks_succeeds(): void
    {
        Task::factory()->create([
            'source_type' => Recommendation::class,
            'source_id' => $this->rec->id,
            'organization_id' => $this->project->organization_id,
            'department_id' => $this->dept->id,
            'status' => TaskStatus::COMPLETED->value,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/recommendations/{$this->rec->id}/complete");

        $response->assertStatus(200)
            ->assertJsonPath('recommendation.status', Recommendation::STATUS_COMPLETED);
    }

    public function test_completion_with_only_cancelled_tasks_succeeds(): void
    {
        Task::factory()->create([
            'source_type' => Recommendation::class,
            'source_id' => $this->rec->id,
            'organization_id' => $this->project->organization_id,
            'department_id' => $this->dept->id,
            'status' => TaskStatus::CANCELLED->value,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/recommendations/{$this->rec->id}/complete");

        $response->assertStatus(200)
            ->assertJsonPath('recommendation.status', Recommendation::STATUS_COMPLETED);
    }

    public function test_completion_with_one_pending_task_returns_422_with_ids(): void
    {
        $pending = Task::factory()->create([
            'source_type' => Recommendation::class,
            'source_id' => $this->rec->id,
            'organization_id' => $this->project->organization_id,
            'department_id' => $this->dept->id,
            'status' => TaskStatus::IN_PROGRESS->value,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/recommendations/{$this->rec->id}/complete");

        $response->assertStatus(422)
            ->assertJsonStructure(['message', 'pending_task_ids']);
        $this->assertSame([$pending->id], $response->json('pending_task_ids'));
        $this->assertSame(Recommendation::STATUS_ACCEPTED, $this->rec->fresh()->status);
    }

    public function test_completion_lists_all_non_terminal_pending_tasks(): void
    {
        // Three non-terminal tasks across distinct statuses, plus one
        // completed task. The gate must return ALL three non-terminal ids.
        $todo = Task::factory()->create([
            'source_type' => Recommendation::class,
            'source_id' => $this->rec->id,
            'organization_id' => $this->project->organization_id,
            'department_id' => $this->dept->id,
            'status' => TaskStatus::TODO->value,
        ]);
        $inProgress = Task::factory()->create([
            'source_type' => Recommendation::class,
            'source_id' => $this->rec->id,
            'organization_id' => $this->project->organization_id,
            'department_id' => $this->dept->id,
            'status' => TaskStatus::IN_PROGRESS->value,
        ]);
        $onHold = Task::factory()->create([
            'source_type' => Recommendation::class,
            'source_id' => $this->rec->id,
            'organization_id' => $this->project->organization_id,
            'department_id' => $this->dept->id,
            'status' => TaskStatus::ON_HOLD->value,
        ]);
        Task::factory()->create([
            'source_type' => Recommendation::class,
            'source_id' => $this->rec->id,
            'organization_id' => $this->project->organization_id,
            'department_id' => $this->dept->id,
            'status' => TaskStatus::COMPLETED->value,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/recommendations/{$this->rec->id}/complete");

        $response->assertStatus(422);
        $ids = $response->json('pending_task_ids');
        sort($ids);
        $expected = [$todo->id, $inProgress->id, $onHold->id];
        sort($expected);
        $this->assertSame($expected, $ids);
    }

    public function test_completion_gate_ignores_tasks_sourced_from_another_recommendation(): void
    {
        // The pendingTaskIdsFor helper filters on
        // (source_type, source_id) == (Recommendation, $rec->id) — tasks
        // attached to ANOTHER recommendation must not contaminate the gate.
        $otherRec = Recommendation::create([
            'kind' => Recommendation::KIND_ACTION_ITEM,
            'meeting_id' => $this->meeting->id,
            'title' => 'إجراء آخر',
            'assignee_id' => $this->user->id,
            'due_date' => now()->addDays(5)->toDateString(),
            'status' => Recommendation::STATUS_ACCEPTED,
            'priority' => Recommendation::PRIORITY_MEDIUM,
            'organization_id' => $this->project->organization_id,
        ]);
        Task::factory()->create([
            'source_type' => Recommendation::class,
            'source_id' => $otherRec->id,
            'organization_id' => $this->project->organization_id,
            'department_id' => $this->dept->id,
            'status' => TaskStatus::IN_PROGRESS->value,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/recommendations/{$this->rec->id}/complete");

        $response->assertStatus(200)
            ->assertJsonPath('recommendation.status', Recommendation::STATUS_COMPLETED);
    }

    public function test_completion_gate_ignores_unrelated_project_tasks(): void
    {
        // A task with no `source_type` (legacy project-only task) is NOT a
        // recommendation child, even if it sits on the same project. It must
        // not block completion.
        Task::factory()->create([
            'source_type' => null,
            'source_id' => null,
            'organization_id' => $this->project->organization_id,
            'department_id' => $this->dept->id,
            'project_id' => $this->project->id,
            'status' => TaskStatus::IN_PROGRESS->value,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/recommendations/{$this->rec->id}/complete");

        $response->assertStatus(200)
            ->assertJsonPath('recommendation.status', Recommendation::STATUS_COMPLETED);
    }
}
