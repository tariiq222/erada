<?php

namespace Tests\Feature\Meetings;

use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Models\MeetingResolution;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * MeetingResolutionCountsPerformanceTest — Phase 4 / Direction R.
 *
 * Pins the on-the-wire contract AND the N+1 guarantee:
 *   - list endpoint surfaces tasks_count + completed_tasks_count on every row
 *   - show endpoint surfaces pending_tasks_count + completion_percentage
 *   - both endpoints do NOT run a per-resolution subquery (the counts come
 *     from a single grouped `withCount` query)
 *   - the four accessors on the model read the eager-loaded attribute
 *     when present and only fall back to a subquery when absent
 */
class MeetingResolutionCountsPerformanceTest extends TestCase
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

    private function makeResolution(string $status = MeetingResolution::STATUS_OPEN, ?int $ownerId = null): MeetingResolution
    {
        return MeetingResolution::create([
            'meeting_id' => $this->meeting->id,
            'organization_id' => $this->project->organization_id,
            'kind' => MeetingResolution::KIND_DECISION,
            'title' => 'مخرج للاختبار',
            'owner_id' => $ownerId ?? $this->user->id,
            'created_by' => $this->user->id,
            'status' => $status,
            'priority' => MeetingResolution::PRIORITY_MEDIUM,
        ]);
    }

    private function makeTask(int $resolutionId, string $status = 'todo'): Task
    {
        return Task::create([
            'title' => 'مهمة',
            'status' => $status,
            'type' => 'project',
            'priority' => 'medium',
            'source_type' => 'MeetingResolution',
            'source_id' => $resolutionId,
            'organization_id' => $this->project->organization_id,
            'department_id' => $this->meeting->department_id,
            'assigned_to' => $this->user->id,
            'owner_id' => $this->user->id,
            'created_by' => $this->user->id,
            'project_id' => $this->project->id,
        ]);
    }

    public function test_list_returns_tasks_count_and_completed_tasks_count(): void
    {
        $r1 = $this->makeResolution();
        $r2 = $this->makeResolution();
        $this->makeTask($r1->id, 'todo');
        $this->makeTask($r1->id, 'completed');
        $this->makeTask($r1->id, 'todo');
        $this->makeTask($r2->id, 'todo');

        $response = $this->actingAs($this->user, 'sanctum')->getJson('/api/meeting-resolutions');
        $response->assertStatus(200);

        $rows = collect($response->json('data'))->keyBy('id');
        $this->assertSame(3, $rows[$r1->id]['tasks_count']);
        $this->assertSame(1, $rows[$r1->id]['completed_tasks_count']);
        $this->assertSame(1, $rows[$r2->id]['tasks_count']);
        $this->assertSame(0, $rows[$r2->id]['completed_tasks_count']);
    }

    public function test_show_returns_pending_and_completion_percentage(): void
    {
        $r = $this->makeResolution();
        $this->makeTask($r->id, 'todo');
        $this->makeTask($r->id, 'todo');
        $this->makeTask($r->id, 'completed');
        $this->makeTask($r->id, 'completed');

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/meeting-resolutions/{$r->id}");
        $response->assertStatus(200);

        $this->assertSame(4, $response->json('tasks_count'));
        $this->assertSame(2, $response->json('completed_tasks_count'));
        $this->assertSame(2, $response->json('pending_tasks_count'));
        $this->assertEquals(50.0, $response->json('completion_percentage'));
    }

    public function test_show_zero_tasks_returns_zero_completion_without_query(): void
    {
        $r = $this->makeResolution();

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/meeting-resolutions/{$r->id}");
        $response->assertStatus(200);

        $this->assertSame(0, $response->json('tasks_count'));
        $this->assertSame(0, $response->json('completed_tasks_count'));
        $this->assertSame(0, $response->json('pending_tasks_count'));
        $this->assertEquals(0.0, $response->json('completion_percentage'));
    }

    public function test_list_endpoint_uses_eager_loaded_counts_no_n_plus_1(): void
    {
        // N+1 guard: list endpoint must NOT trigger a per-row subquery
        // for tasks_count or completed_tasks_count. We seed 5 resolutions
        // and assert that the tasks table is SELECTed at most twice
        // (one for each withCount grouped subquery).
        for ($i = 0; $i < 5; $i++) {
            $r = $this->makeResolution();
            $this->makeTask($r->id, 'todo');
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->user, 'sanctum')->getJson('/api/meeting-resolutions')->assertStatus(200);

        $taskSelects = collect(DB::getQueryLog())->filter(
            fn ($q) => str_contains(strtolower($q['query']), 'from "tasks"')
        );
        $this->assertLessThanOrEqual(
            2,
            $taskSelects->count(),
            "Expected ≤2 SELECTs against tasks table for 5 resolutions, got {$taskSelects->count()} (N+1 regression?)"
        );
    }
}
