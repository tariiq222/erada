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
 * MeetingResolutionHoldTest
 *
 * Hold is intentionally a metadata-only action: the resolution's `status`
 * does NOT move on hold, only on release-hold. The four-field triple
 * (hold_reason, hold_until, hold_by, hold_at) is populated by the controller;
 * release-hold clears all four back to NULL.
 *
 * Validation rules per HoldMeetingResolutionRequest:
 *   - hold_reason: required, string, min:3, max:5000
 *   - hold_until:  nullable, date, after:now
 */
class MeetingResolutionHoldTest extends TestCase
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

    private function makeResolution(string $status = MeetingResolution::STATUS_OPEN): MeetingResolution
    {
        return MeetingResolution::create([
            'meeting_id' => $this->meeting->id,
            'organization_id' => $this->project->organization_id,
            'kind' => MeetingResolution::KIND_RECOMMENDATION,
            'title' => 'مخرج للتعليق',
            'owner_id' => $this->user->id,
            'created_by' => $this->user->id,
            'status' => $status,
            'priority' => MeetingResolution::PRIORITY_MEDIUM,
        ]);
    }

    public function test_hold_requires_reason(): void
    {
        $resolution = $this->makeResolution();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/meeting-resolutions/{$resolution->id}/hold", []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['hold_reason']);
    }

    public function test_hold_does_not_change_status(): void
    {
        $resolution = $this->makeResolution(MeetingResolution::STATUS_OPEN);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/meeting-resolutions/{$resolution->id}/hold", [
                'hold_reason' => 'بانتظار اعتماد الميزانية',
            ]);

        $response->assertStatus(200);

        $fresh = $resolution->fresh();
        // Status stays at 'open' — hold is metadata only.
        $this->assertSame(MeetingResolution::STATUS_OPEN, $fresh->status);

        // Hold metadata is fully populated.
        $this->assertSame('بانتظار اعتماد الميزانية', $fresh->hold_reason);
        $this->assertSame($this->user->id, $fresh->hold_by);
        $this->assertNotNull($fresh->hold_at);
        $this->assertNull($fresh->hold_until);

        // The is_on_hold accessor reflects the metadata.
        $this->assertTrue($fresh->is_on_hold);
    }

    public function test_release_hold_clears_hold_metadata(): void
    {
        $resolution = $this->makeResolution(MeetingResolution::STATUS_IN_PROGRESS);
        $resolution->forceFill([
            'hold_reason' => 'بانتظار معلومات إضافية',
            'hold_until' => now()->addDays(7),
            'hold_by' => $this->user->id,
            'hold_at' => now()->subHour(),
        ])->save();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/meeting-resolutions/{$resolution->id}/release-hold");

        $response->assertStatus(200);

        $fresh = $resolution->fresh();
        $this->assertNull($fresh->hold_reason);
        $this->assertNull($fresh->hold_until);
        $this->assertNull($fresh->hold_by);
        $this->assertNull($fresh->hold_at);

        // Status is unchanged by release-hold either.
        $this->assertSame(MeetingResolution::STATUS_IN_PROGRESS, $fresh->status);
    }

    public function test_hold_until_must_be_future(): void
    {
        $resolution = $this->makeResolution();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/meeting-resolutions/{$resolution->id}/hold", [
                'hold_reason' => 'سبب مقبول',
                'hold_until' => now()->subDay()->toDateTimeString(),
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['hold_until']);
    }
}
