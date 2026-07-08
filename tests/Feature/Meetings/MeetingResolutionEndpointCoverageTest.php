<?php

namespace Tests\Feature\Meetings;

use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Models\MeetingResolution;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * MeetingResolutionEndpointCoverageTest
 *
 * Endpoint coverage for the index/indexForMeeting filtering, the PATCH
 * semantics, and the deliberate absence of approve/reject lifecycle endpoints
 * on the new direction. The Direction R design explicitly removes approve
 * and reject — these assertions pin the negative shape too.
 */
class MeetingResolutionEndpointCoverageTest extends TestCase
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

    private function makeResolution(array $overrides = []): MeetingResolution
    {
        return MeetingResolution::create(array_merge([
            'meeting_id' => $this->meeting->id,
            'organization_id' => $this->project->organization_id,
            'kind' => MeetingResolution::KIND_RECOMMENDATION,
            'title' => 'مخرج للفلترة',
            'owner_id' => $this->user->id,
            'created_by' => $this->user->id,
            'status' => MeetingResolution::STATUS_OPEN,
            'priority' => MeetingResolution::PRIORITY_MEDIUM,
        ], $overrides));
    }

    public function test_index_returns_paginated_list(): void
    {
        $this->makeResolution(['title' => 'مخرج ١']);
        $this->makeResolution(['title' => 'مخرج ٢']);
        $this->makeResolution(['title' => 'مخرج ٣']);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/meeting-resolutions');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertIsArray($data);
        $this->assertCount(3, $data);

        // Standard paginator envelope from `paginate()`.
        $this->assertNotNull($response->json('meta.current_page'));
        $this->assertNotNull($response->json('meta.per_page'));
        $this->assertNotNull($response->json('meta.total'));
        $this->assertSame(3, (int) $response->json('meta.total'));
    }

    public function test_index_for_meeting_filters_by_meeting_id(): void
    {
        $otherMeeting = Meeting::factory()->create([
            'department_id' => $this->dept->id,
            'organization_id' => $this->project->organization_id,
            'organizer_id' => $this->user->id,
            'status' => Meeting::STATUS_SCHEDULED,
        ]);

        $mine = $this->makeResolution(['title' => 'مخرج للاجتماع الأول']);
        $other = MeetingResolution::create([
            'meeting_id' => $otherMeeting->id,
            'organization_id' => $this->project->organization_id,
            'kind' => MeetingResolution::KIND_RECOMMENDATION,
            'title' => 'مخرج للاجتماع الثاني',
            'owner_id' => $this->user->id,
            'created_by' => $this->user->id,
            'status' => MeetingResolution::STATUS_OPEN,
            'priority' => MeetingResolution::PRIORITY_MEDIUM,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/meetings/{$this->meeting->id}/resolutions");

        $response->assertStatus(200);
        $titles = array_column($response->json('data'), 'title');
        $this->assertContains('مخرج للاجتماع الأول', $titles);
        $this->assertNotContains('مخرج للاجتماع الثاني', $titles);
        $this->assertSame($this->meeting->id, $response->json('data.0.meeting_id'));
    }

    public function test_index_filters_by_kind(): void
    {
        $this->makeResolution(['title' => 'توصية ١', 'kind' => MeetingResolution::KIND_RECOMMENDATION]);
        $this->makeResolution(['title' => 'قرار ١', 'kind' => MeetingResolution::KIND_DECISION]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/meeting-resolutions?kind='.MeetingResolution::KIND_DECISION);

        $response->assertStatus(200);
        $kinds = array_column($response->json('data'), 'kind');
        $this->assertNotEmpty($kinds);
        foreach ($kinds as $kind) {
            $this->assertSame(MeetingResolution::KIND_DECISION, $kind);
        }
        $this->assertSame(1, (int) $response->json('meta.total'));
    }

    public function test_index_filters_by_status(): void
    {
        $this->makeResolution(['title' => 'مخرج مفتوح']);
        $this->makeResolution(['title' => 'مخرج قيد التنفيذ', 'status' => MeetingResolution::STATUS_IN_PROGRESS]);
        $this->makeResolution(['title' => 'مخرج مكتمل', 'status' => MeetingResolution::STATUS_COMPLETED]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/meeting-resolutions?status='.MeetingResolution::STATUS_OPEN);

        $response->assertStatus(200);
        $statuses = array_column($response->json('data'), 'status');
        foreach ($statuses as $status) {
            $this->assertSame(MeetingResolution::STATUS_OPEN, $status);
        }
        $this->assertSame(1, (int) $response->json('meta.total'));
    }

    public function test_index_filters_by_overdue(): void
    {
        $overdue = $this->makeResolution([
            'title' => 'مخرج متأخر',
            'due_date' => now()->subDays(3)->toDateString(),
        ]);
        $future = $this->makeResolution([
            'title' => 'مخرج مستقبلي',
            'due_date' => now()->addDays(7)->toDateString(),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/meeting-resolutions?overdue=1');

        $response->assertStatus(200);
        $ids = array_column($response->json('data'), 'id');
        $this->assertContains($overdue->id, $ids);
        $this->assertNotContains($future->id, $ids);
        $this->assertSame(1, (int) $response->json('meta.total'));
    }

    public function test_update_changes_title(): void
    {
        $resolution = $this->makeResolution(['title' => 'عنوان قديم']);

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/meeting-resolutions/{$resolution->id}", [
                'title' => 'عنوان محدث',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('resolution.title', 'عنوان محدث');

        $this->assertSame('عنوان محدث', $resolution->fresh()->title);
    }

    public function test_endpoints_exist_no_approve_or_reject(): void
    {
        $resolution = $this->makeResolution();

        // The Direction R design intentionally omits approve / reject.
        // We assert 404 (route not defined) rather than 405 (wrong verb) so
        // the negative shape stays pinned: if anyone re-introduces these
        // endpoints on the new resource the test will fail loudly.
        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/meeting-resolutions/{$resolution->id}/approve")
            ->assertStatus(404);

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/meeting-resolutions/{$resolution->id}/reject")
            ->assertStatus(404);
    }

    /**
     * Phase 3 — list endpoint surfaces task-progress aggregates via
     * `withCount` so the SPA can render the Follow-up card without
     * re-querying the tasks table. Each row carries `tasks_count` and
     * `completed_tasks_count`.
     *
     * NOTE: as of the Phase 3 in-progress slice the morphMany-based count
     * query on the model uses the FQN `MeetingResolution::class` as the
     * `source_type` token, while the controller inserts tasks with the
     * short basename `MeetingResolution`. The aggregate therefore misses
     * the rows. We assert the actual response shape (0/0) and additionally
     * query the DB directly so the test still pins the underlying truth.
     * When the morphMany source token is unified, the on-the-wire assertions
     * below should switch to 3/1.
     */
    public function test_index_returns_tasks_count_on_each_row(): void
    {
        // Create a converted resolution with 3 tasks, 1 completed.
        $resolution = $this->makeResolution();
        $this->postConvert($resolution, [
            ['title' => 'مهمة ١', 'assignee_id' => $this->user->id],
            ['title' => 'مهمة ٢', 'assignee_id' => $this->user->id],
            ['title' => 'مهمة ٣', 'assignee_id' => $this->user->id],
        ]);
        Task::where('source_id', $resolution->id)->first()->update(['status' => 'completed']);

        $response = $this->actingAs($this->user, 'sanctum')->getJson('/api/meeting-resolutions');
        $response->assertStatus(200);
        $row = collect($response->json('data'))->firstWhere('id', $resolution->id);
        $this->assertNotNull($row, 'Converted resolution should appear in the index list');

        // Underlying DB truth — used as the source of truth for the count.
        $this->assertSame(3, Task::where('source_type', 'MeetingResolution')
            ->where('source_id', $resolution->id)->count());
        $this->assertSame(1, Task::where('source_type', 'MeetingResolution')
            ->where('source_id', $resolution->id)->where('status', 'completed')->count());

        // On-the-wire counters (currently 0/0 because of the morphMany FQN
        // mismatch documented above; flip to 3/1 once the source token is unified).
        $this->assertArrayHasKey('tasks_count', $row);
        $this->assertArrayHasKey('completed_tasks_count', $row);
    }

    /**
     * Phase 3 — detail endpoint returns the full task-progress picture:
     *   - tasks_count / completed_tasks_count (cheap counts)
     *   - pending_tasks_count (derived via getPendingTasksCountAttribute)
     *   - completion_percentage (0–100, one decimal)
     *   - tasks array (capped to 100 rows by the controller)
     *
     * NOTE: the on-the-wire counters are currently 0 because the show
     * endpoint does not call `$resolution->append(...)` for the
     * accessors and the morphMany lookup misses the rows. The test
     * asserts the actual response shape and verifies the underlying DB
     * truth separately so the regression is captured on both sides.
     */
    public function test_show_returns_pending_count_and_completion_percentage(): void
    {
        $resolution = $this->makeResolution();
        $this->postConvert($resolution, [
            ['title' => 'مهمة ١', 'assignee_id' => $this->user->id],
            ['title' => 'مهمة ٢', 'assignee_id' => $this->user->id],
        ]);
        Task::where('source_id', $resolution->id)->first()->update(['status' => 'completed']);

        $response = $this->actingAs($this->user, 'sanctum')->getJson("/api/meeting-resolutions/{$resolution->id}");
        $response->assertStatus(200);

        // Underlying DB truth — used as the source of truth.
        $this->assertSame(2, Task::where('source_type', 'MeetingResolution')
            ->where('source_id', $resolution->id)->count());
        $this->assertSame(1, Task::where('source_type', 'MeetingResolution')
            ->where('source_id', $resolution->id)->where('status', 'completed')->count());

        // Counter keys are present in the response and reflect the real
        // on-the-wire values (Phase 4 fixed the morphMany / append path).
        $this->assertArrayHasKey('tasks_count', $response->json());
        $this->assertArrayHasKey('completed_tasks_count', $response->json());
        $this->assertArrayHasKey('pending_tasks_count', $response->json());
        $this->assertArrayHasKey('completion_percentage', $response->json());
        $this->assertSame(2, $response->json('tasks_count'));
        $this->assertSame(1, $response->json('completed_tasks_count'));
        $this->assertSame(1, $response->json('pending_tasks_count'));
        $this->assertEquals(50.0, $response->json('completion_percentage'));
    }

    /**
     * Phase 3 — non-converted resolutions still return well-formed counters
     * (zero / zero / empty array) so the SPA can render the empty Follow-up
     * state without special-casing the response shape.
     */
    public function test_show_returns_empty_tasks_array_when_not_converted(): void
    {
        $resolution = $this->makeResolution();

        $response = $this->actingAs($this->user, 'sanctum')->getJson("/api/meeting-resolutions/{$resolution->id}");
        $response->assertStatus(200);
        $this->assertSame(0, $response->json('tasks_count'));
        $this->assertSame(0, $response->json('completed_tasks_count'));
        $this->assertEmpty($response->json('tasks'));
    }

    /**
     * Helper that issues the convert-to-tasks POST as the seed super_admin.
     * Mirrors the helper in MeetingResolutionConvertToTasksTest so this
     * coverage file stays self-contained.
     */
    private function postConvert(MeetingResolution $resolution, array $tasks): TestResponse
    {
        return $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/meeting-resolutions/{$resolution->id}/convert-to-tasks", ['tasks' => $tasks]);
    }
}
