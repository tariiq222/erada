<?php

namespace Tests\Feature\Api\Meetings;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Projects\Models\Project;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

class MeetingControllerTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    protected User $user;

    protected Project $project;

    protected Department $department;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->department = Department::factory()->create();
        $this->user = User::factory()->create([
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalSuperAdmin($this->user);
        $this->project = Project::factory()->create(['department_id' => $this->department->id]);
    }

    private function makeMeeting(array $overrides = []): Meeting
    {
        return Meeting::create(array_merge([
            'title' => 'اجتماع اختباري',
            'scheduled_at' => now()->addDay(),
            'duration_minutes' => 60,
            'organizer_id' => $this->user->id,
            'organization_id' => $this->user->organization_id,
            'status' => Meeting::STATUS_SCHEDULED,
        ], $overrides));
    }

    public function test_can_list_meetings(): void
    {
        $this->makeMeeting();
        $this->makeMeeting(['title' => 'اجتماع ثانٍ']);

        $response = $this->actingAs($this->user, 'sanctum')->getJson('/api/meetings');
        $response->assertStatus(200)->assertJsonStructure(['data']);
        $this->assertGreaterThanOrEqual(2, count($response->json('data')));
    }

    public function test_can_create_a_meeting(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/meetings', [
            'title' => 'اجتماع جديد',
            'description' => 'وصف الاجتماع',
            'scheduled_at' => now()->addDays(2)->toIso8601String(),
            'duration_minutes' => 90,
            'location' => 'قاعة A',
            'organizer_id' => $this->user->id,
            'subject_type' => 'project',
            'subject_id' => $this->project->id,
        ]);

        $response->assertStatus(201)->assertJsonStructure(['message', 'meeting' => ['id', 'reference_number', 'status']]);
        $this->assertDatabaseHas('meetings', ['title' => 'اجتماع جديد']);
    }

    public function test_can_show_a_meeting(): void
    {
        $m = $this->makeMeeting();
        $response = $this->actingAs($this->user, 'sanctum')->getJson("/api/meetings/{$m->id}");
        $response->assertStatus(200)->assertJson(['id' => $m->id]);
    }

    public function test_can_update_a_meeting(): void
    {
        $m = $this->makeMeeting();
        $response = $this->actingAs($this->user, 'sanctum')->putJson("/api/meetings/{$m->id}", [
            'title' => 'عنوان محدث',
            'scheduled_at' => now()->addDays(3)->toIso8601String(),
            'duration_minutes' => 45,
            'organizer_id' => $this->user->id,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('meetings', ['id' => $m->id, 'title' => 'عنوان محدث']);
    }

    public function test_can_soft_delete_a_meeting(): void
    {
        $m = $this->makeMeeting();
        $response = $this->actingAs($this->user, 'sanctum')->deleteJson("/api/meetings/{$m->id}");
        $response->assertStatus(200);
        $this->assertSoftDeleted('meetings', ['id' => $m->id]);
    }

    public function test_can_start_a_scheduled_meeting(): void
    {
        $m = $this->makeMeeting(['status' => Meeting::STATUS_SCHEDULED]);
        $response = $this->actingAs($this->user, 'sanctum')->postJson("/api/meetings/{$m->id}/start");
        $response->assertStatus(200)->assertJsonPath('meeting.status', Meeting::STATUS_IN_PROGRESS);
    }

    public function test_cannot_start_a_completed_meeting(): void
    {
        $m = $this->makeMeeting(['status' => Meeting::STATUS_COMPLETED]);
        $response = $this->actingAs($this->user, 'sanctum')->postJson("/api/meetings/{$m->id}/start");
        $response->assertStatus(409);
    }

    public function test_can_complete_an_in_progress_meeting(): void
    {
        $m = $this->makeMeeting(['status' => Meeting::STATUS_IN_PROGRESS]);
        $response = $this->actingAs($this->user, 'sanctum')->postJson("/api/meetings/{$m->id}/complete");
        $response->assertStatus(200)->assertJsonPath('meeting.status', Meeting::STATUS_COMPLETED);
    }

    public function test_can_cancel_a_scheduled_meeting(): void
    {
        $m = $this->makeMeeting(['status' => Meeting::STATUS_SCHEDULED]);
        $response = $this->actingAs($this->user, 'sanctum')->postJson("/api/meetings/{$m->id}/cancel");
        $response->assertStatus(200)->assertJsonPath('meeting.status', Meeting::STATUS_CANCELLED);
    }

    public function test_validation_rejects_empty_title(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/meetings', [
            'title' => '',
            'scheduled_at' => now()->addDay()->toIso8601String(),
            'duration_minutes' => 60,
            'organizer_id' => $this->user->id,
        ]);
        $response->assertStatus(422)->assertJsonValidationErrors(['title']);
    }

    public function test_validation_rejects_unknown_subject_type(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/meetings', [
            'title' => 'اجتماع',
            'scheduled_at' => now()->addDay()->toIso8601String(),
            'duration_minutes' => 60,
            'organizer_id' => $this->user->id,
            'subject_type' => 'committee',
            'subject_id' => 1,
        ]);
        $response->assertStatus(422)->assertJsonValidationErrors(['subject_type']);
    }

    public function test_unauthenticated_cannot_list_meetings(): void
    {
        $response = $this->getJson('/api/meetings');
        $response->assertStatus(401);
    }

    // ============================================================
    // Task 3.4 — GET /api/meetings/list + GET /api/meetings/{id}/attendees
    // ============================================================

    public function test_list_endpoint_returns_meeting_dropdown_data(): void
    {
        $this->makeMeeting(['title' => 'Drop Meeting One']);
        $this->makeMeeting(['title' => 'Drop Meeting Two']);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/meetings/list');

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['*' => ['id', 'title', 'reference_number', 'scheduled_at', 'status']]]);

        $titles = collect($response->json('data'))->pluck('title')->all();
        $this->assertContains('Drop Meeting One', $titles);
        $this->assertContains('Drop Meeting Two', $titles);
    }

    public function test_list_endpoint_excludes_other_organization_meetings(): void
    {
        // The default $this->user is super_admin and bypasses org-scope. Use an
        // org-A actor that does NOT have cross-org visibility (a plain user
        // bound to orgA) for the cross-org leakage assertion.
        $orgA = $this->department->organization_id;
        // makeMeeting defaults $organization_id from $this->user (super_admin, null).
        // Set it explicitly so the row is owned by orgA, not orphaned.
        $ownMeeting = $this->makeMeeting(['title' => 'OrgA Meeting', 'organization_id' => $orgA]);

        $orgB = Organization::factory()->create();
        $foreignDept = Department::factory()->create(['organization_id' => $orgB->id]);
        $foreignUser = User::factory()->create([
            'department_id' => $foreignDept->id,
            'organization_id' => $orgB->id,
            'is_active' => true,
        ]);

        $foreignMeeting = Meeting::create([
            'title' => 'OrgB Meeting',
            'scheduled_at' => now()->addDay(),
            'duration_minutes' => 60,
            'organizer_id' => $foreignUser->id,
            'organization_id' => $orgB->id,
            'status' => Meeting::STATUS_SCHEDULED,
        ]);

        $orgAActor = User::factory()->create([
            'organization_id' => $orgA,
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $this->assignCanonicalRole($orgAActor, 'admin');
        $this->grantEngineCapability($orgAActor, Capability::MEETINGS_VIEW, 'organization', $orgA);

        $response = $this->actingAs($orgAActor, 'sanctum')
            ->getJson('/api/meetings/list');

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($ownMeeting->id, $ids, 'org-A meeting must appear in own-actor list');
        $this->assertNotContains($foreignMeeting->id, $ids, 'org-B meeting must be scoped out');
    }

    public function test_attendees_endpoint_returns_attendees_for_meeting(): void
    {
        $meeting = $this->makeMeeting();
        $attendee = User::factory()->create([
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $meeting->attendees()->attach($attendee->id, ['role' => 'attendee']);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/meetings/{$meeting->id}/attendees");

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['*' => ['id', 'name', 'email']]]);

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($attendee->id, $ids);
    }

    public function test_attendees_endpoint_denies_other_organization_meeting(): void
    {
        $orgB = Organization::factory()->create();
        $foreignDept = Department::factory()->create(['organization_id' => $orgB->id]);
        $foreignUser = User::factory()->create([
            'department_id' => $foreignDept->id,
            'organization_id' => $orgB->id,
            'is_active' => true,
        ]);
        $foreignMeeting = Meeting::create([
            'title' => 'Foreign meeting',
            'scheduled_at' => now()->addDay(),
            'duration_minutes' => 60,
            'organizer_id' => $foreignUser->id,
            'organization_id' => $orgB->id,
            'status' => Meeting::STATUS_SCHEDULED,
        ]);

        // Use an org-A actor that is NOT super_admin so org-scope is enforced.
        $orgAActor = User::factory()->create([
            'organization_id' => $this->department->organization_id,
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $this->assignCanonicalRole($orgAActor, 'admin');
        $this->grantEngineCapability($orgAActor, Capability::MEETINGS_VIEW, 'organization', $this->department->organization_id);

        $status = $this->actingAs($orgAActor, 'sanctum')
            ->getJson("/api/meetings/{$foreignMeeting->id}/attendees")
            ->status();

        $this->assertContains($status, [403, 404], 'org-A actor must not read org-B meeting attendees');
    }
}
