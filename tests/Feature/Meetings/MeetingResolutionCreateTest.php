<?php

namespace Tests\Feature\Meetings;

use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Models\MeetingResolution;
use App\Modules\Projects\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * MeetingResolutionCreateTest
 *
 * Pinned contract for the create flow:
 *   - kind and owner_id are mandatory (no defaults at the API surface).
 *   - Default kind at the DB is `recommendation`, default priority is `medium`,
 *     default status is `open` — asserted via response payload.
 *   - kind, due_date are validated; bad input is rejected with 422.
 */
class MeetingResolutionCreateTest extends TestCase
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

    public function test_kind_is_required(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/meetings/{$this->meeting->id}/resolutions", [
                'title' => 'مخرج بدون نوع',
                'owner_id' => $this->user->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['kind']);
    }

    public function test_owner_is_required(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/meetings/{$this->meeting->id}/resolutions", [
                'kind' => MeetingResolution::KIND_RECOMMENDATION,
                'title' => 'مخرج بدون مسؤول',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['owner_id']);
    }

    public function test_creates_recommendation(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/meetings/{$this->meeting->id}/resolutions", [
                'meeting_id' => $this->meeting->id,
                'kind' => MeetingResolution::KIND_RECOMMENDATION,
                'title' => 'توصية اختبار',
                'owner_id' => $this->user->id,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('resolution.kind', MeetingResolution::KIND_RECOMMENDATION)
            ->assertJsonPath('resolution.status', MeetingResolution::STATUS_OPEN);

        $this->assertDatabaseHas('meeting_resolutions', [
            'meeting_id' => $this->meeting->id,
            'kind' => MeetingResolution::KIND_RECOMMENDATION,
            'title' => 'توصية اختبار',
            'owner_id' => $this->user->id,
            'status' => MeetingResolution::STATUS_OPEN,
        ]);
    }

    public function test_creates_decision(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/meetings/{$this->meeting->id}/resolutions", [
                'meeting_id' => $this->meeting->id,
                'kind' => MeetingResolution::KIND_DECISION,
                'title' => 'قرار اختبار',
                'owner_id' => $this->user->id,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('resolution.kind', MeetingResolution::KIND_DECISION)
            ->assertJsonPath('resolution.status', MeetingResolution::STATUS_OPEN);

        $this->assertDatabaseHas('meeting_resolutions', [
            'meeting_id' => $this->meeting->id,
            'kind' => MeetingResolution::KIND_DECISION,
            'title' => 'قرار اختبار',
        ]);
    }

    public function test_priority_defaults_to_medium(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/meetings/{$this->meeting->id}/resolutions", [
                'meeting_id' => $this->meeting->id,
                'kind' => MeetingResolution::KIND_RECOMMENDATION,
                'title' => 'توصية بأولوية افتراضية',
                'owner_id' => $this->user->id,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('resolution.priority', MeetingResolution::PRIORITY_MEDIUM);
    }

    public function test_invalid_kind_is_rejected(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/meetings/{$this->meeting->id}/resolutions", [
                'kind' => 'foo',
                'title' => 'نوع غير صالح',
                'owner_id' => $this->user->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['kind']);
    }

    public function test_due_date_must_be_today_or_future(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/meetings/{$this->meeting->id}/resolutions", [
                'kind' => MeetingResolution::KIND_RECOMMENDATION,
                'title' => 'تاريخ ماضٍ',
                'owner_id' => $this->user->id,
                'due_date' => now()->subDay()->toDateString(),
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['due_date']);
    }
}
