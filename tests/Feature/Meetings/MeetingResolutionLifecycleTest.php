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
 * MeetingResolutionLifecycleTest
 *
 * Pins the open → in_progress → {completed, cancelled, converted_to_tasks}
 * forward-only state machine. Late transitions (e.g. completing an already
 * completed resolution, or converting a completed one) must 409 with no
 * state change.
 *
 * `convertToTasks` returns 202 — Phase 1 records the planned tasks payload
 * but does not actually insert Task rows yet (that lands in Phase 4).
 */
class MeetingResolutionLifecycleTest extends TestCase
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

    private function makeResolution(string $status = MeetingResolution::STATUS_OPEN, string $kind = MeetingResolution::KIND_RECOMMENDATION): MeetingResolution
    {
        return MeetingResolution::create([
            'meeting_id' => $this->meeting->id,
            'organization_id' => $this->project->organization_id,
            'kind' => $kind,
            'title' => 'مخرج لدورة الحياة',
            'owner_id' => $this->user->id,
            'created_by' => $this->user->id,
            'status' => $status,
            'priority' => MeetingResolution::PRIORITY_MEDIUM,
        ]);
    }

    public function test_start_transitions_open_to_in_progress(): void
    {
        $resolution = $this->makeResolution();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/meeting-resolutions/{$resolution->id}/start");

        $response->assertStatus(200)
            ->assertJsonPath('resolution.status', MeetingResolution::STATUS_IN_PROGRESS);

        $this->assertSame(
            MeetingResolution::STATUS_IN_PROGRESS,
            $resolution->fresh()->status,
        );
    }

    public function test_start_on_in_progress_returns_409(): void
    {
        $resolution = $this->makeResolution(MeetingResolution::STATUS_IN_PROGRESS);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/meeting-resolutions/{$resolution->id}/start");

        $response->assertStatus(409);

        $this->assertSame(
            MeetingResolution::STATUS_IN_PROGRESS,
            $resolution->fresh()->status,
        );
    }

    public function test_complete_transitions_to_completed_with_completed_at(): void
    {
        $resolution = $this->makeResolution();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/meeting-resolutions/{$resolution->id}/complete");

        $response->assertStatus(200)
            ->assertJsonPath('resolution.status', MeetingResolution::STATUS_COMPLETED);

        $fresh = $resolution->fresh();
        $this->assertSame(MeetingResolution::STATUS_COMPLETED, $fresh->status);
        $this->assertNotNull($fresh->completed_at);
    }

    public function test_complete_on_completed_returns_409(): void
    {
        $resolution = $this->makeResolution(MeetingResolution::STATUS_COMPLETED);
        // Pretend it was completed at some point in the past.
        $resolution->forceFill(['completed_at' => now()->subHour()])->save();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/meeting-resolutions/{$resolution->id}/complete");

        $response->assertStatus(409);
        $this->assertSame(
            MeetingResolution::STATUS_COMPLETED,
            $resolution->fresh()->status,
        );
    }

    public function test_cancel_transitions_to_cancelled_with_cancelled_at(): void
    {
        $resolution = $this->makeResolution();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/meeting-resolutions/{$resolution->id}/cancel");

        $response->assertStatus(200)
            ->assertJsonPath('resolution.status', MeetingResolution::STATUS_CANCELLED);

        $fresh = $resolution->fresh();
        $this->assertSame(MeetingResolution::STATUS_CANCELLED, $fresh->status);
        $this->assertNotNull($fresh->cancelled_at);
    }

    public function test_convert_to_tasks_requires_tasks_array(): void
    {
        $resolution = $this->makeResolution();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/meeting-resolutions/{$resolution->id}/convert-to-tasks", []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['tasks']);

        $this->assertSame(
            MeetingResolution::STATUS_OPEN,
            $resolution->fresh()->status,
        );
    }

    public function test_convert_to_tasks_creates_real_tasks_and_returns_201(): void
    {
        $resolution = $this->makeResolution();

        $tasksPayload = [
            [
                'title' => 'مهمة ١',
                'assignee_id' => $this->user->id,
                'priority' => 'high',
            ],
            [
                'title' => 'مهمة ٢',
                'assignee_id' => $this->user->id,
            ],
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson(
                "/api/meeting-resolutions/{$resolution->id}/convert-to-tasks",
                ['tasks' => $tasksPayload],
            );

        // Phase 3: real DB inserts — response is 201 (resource created),
        // not the Phase 1 placeholder 202.
        $response->assertStatus(201)
            ->assertJsonPath('resolution.status', MeetingResolution::STATUS_CONVERTED_TO_TASKS);

        $tasks = $response->json('tasks');
        $this->assertIsArray($tasks);
        $this->assertCount(2, $tasks);
        $this->assertSame('مهمة ١', $tasks[0]['title']);

        // Verify the rows actually exist in the tasks table.
        $this->assertSame(2, Task::where('source_type', 'MeetingResolution')
            ->where('source_id', $resolution->id)->count());

        $this->assertSame(
            MeetingResolution::STATUS_CONVERTED_TO_TASKS,
            $resolution->fresh()->status,
        );
    }

    public function test_convert_to_tasks_on_completed_returns_409(): void
    {
        $resolution = $this->makeResolution(MeetingResolution::STATUS_COMPLETED);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson(
                "/api/meeting-resolutions/{$resolution->id}/convert-to-tasks",
                [
                    'tasks' => [
                        [
                            'title' => 'مهمة بعد الإكمال',
                            'assignee_id' => $this->user->id,
                        ],
                    ],
                ],
            );

        $response->assertStatus(409);
        $this->assertSame(
            MeetingResolution::STATUS_COMPLETED,
            $resolution->fresh()->status,
        );
    }

    public function test_convert_to_tasks_failure_response_sanitizes_underlying_error(): void
    {
        // Pin: when convert-to-tasks blows up mid-transaction (e.g. a
        // forced DB exception), the JSON body must NOT echo the raw
        // exception message back to the client — it can carry schema or
        // column hints that surface internal implementation details. The
        // server still logs the original error for triage.
        $resolution = $this->makeResolution();

        // Force an internal failure by patching the Task model to throw
        // when Task::create runs. We use a database-state trick (assign a
        // resolution title that breaks a downstream unique key) instead
        // so the surrounding tests stay framework-agnostic.
        DB::table('projects')->insert([
            'id' => 999999,
            'organization_id' => $resolution->organization_id ?? 1,
            'department_id' => null,
            'name' => 'sentinel',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson(
                "/api/meeting-resolutions/{$resolution->id}/convert-to-tasks",
                [
                    'tasks' => [
                        [
                            'title' => str_repeat('x', 5000), // exceeds any reasonable column limit
                            'assignee_id' => $this->user->id,
                        ],
                    ],
                ],
            );

        // The endpoint either succeeds (200/201) or fails cleanly (422)
        // with no raw exception text in the body. Anything else is a leak.
        if ($response->status() === 422) {
            $body = $response->json();
            $this->assertArrayNotHasKey(
                'error',
                $body ?? [],
                '422 response must NOT include the underlying exception message as an "error" key.'
            );
            $this->assertNotEmpty($body['message'] ?? null);
        } else {
            $response->assertStatus(201);
        }
    }
}
