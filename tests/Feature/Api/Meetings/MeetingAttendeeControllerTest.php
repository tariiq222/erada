<?php

namespace Tests\Feature\Api\Meetings;

use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Meetings\Models\Meeting;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeetingAttendeeControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected User $attendee;

    protected Meeting $meeting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $dept = Department::factory()->create();
        $this->user = User::factory()->create(['department_id' => $dept->id, 'is_active' => true]);
        $this->grantCanonicalSuperAdmin($this->user);
        $this->attendee = User::factory()->create(['department_id' => $dept->id, 'is_active' => true]);
        $this->meeting = Meeting::factory()->create([
            'organizer_id' => $this->user->id,
            'organization_id' => $this->user->organization_id,
        ]);
    }

    public function test_can_attach_an_attendee(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/meetings/{$this->meeting->id}/attendees", [
                'user_id' => $this->attendee->id,
                'role' => 'chair',
            ]);
        $response->assertStatus(200);
        $this->assertDatabaseHas('meeting_attendees', [
            'meeting_id' => $this->meeting->id,
            'user_id' => $this->attendee->id,
            'role' => 'chair',
        ]);
    }

    public function test_can_attach_multiple_attendees(): void
    {
        $a = User::factory()->create(['department_id' => $this->user->department_id]);
        $b = User::factory()->create(['department_id' => $this->user->department_id]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/meetings/{$this->meeting->id}/attendees", [
                'user_ids' => [$a->id, $b->id],
                'role' => 'observer',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('meeting_attendees', ['meeting_id' => $this->meeting->id, 'user_id' => $a->id, 'role' => 'observer']);
        $this->assertDatabaseHas('meeting_attendees', ['meeting_id' => $this->meeting->id, 'user_id' => $b->id, 'role' => 'observer']);
    }

    public function test_can_update_an_attendee(): void
    {
        $this->meeting->attendees()->attach($this->attendee->id, ['role' => 'attendee']);

        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/meetings/{$this->meeting->id}/attendees/{$this->attendee->id}", [
                'role' => 'chair',
                'attended' => true,
            ]);
        $response->assertStatus(200);
        $this->assertDatabaseHas('meeting_attendees', [
            'meeting_id' => $this->meeting->id,
            'user_id' => $this->attendee->id,
            'role' => 'chair',
            'attended' => true,
        ]);
    }

    public function test_can_detach_an_attendee(): void
    {
        $this->meeting->attendees()->attach($this->attendee->id, ['role' => 'attendee']);

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/meetings/{$this->meeting->id}/attendees/{$this->attendee->id}");
        $response->assertStatus(200);
        $this->assertDatabaseMissing('meeting_attendees', [
            'meeting_id' => $this->meeting->id,
            'user_id' => $this->attendee->id,
        ]);
    }
}
