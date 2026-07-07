<?php

namespace Tests\Feature\Api\Meetings;

use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeetingPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $dept = Department::factory()->create();
        $this->user = User::factory()->create([
            'department_id' => $dept->id,
            'is_active' => true,
        ]);
        $this->user->assignRole('viewer');
    }

    public function test_viewer_cannot_list_meetings(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')->getJson('/api/meetings');
        $response->assertStatus(403);
    }

    public function test_viewer_cannot_create_meeting(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/meetings', [
            'title' => 'x',
            'scheduled_at' => now()->addDay()->toIso8601String(),
            'duration_minutes' => 60,
            'organizer_id' => $this->user->id,
        ]);
        $response->assertStatus(403);
    }
}
