<?php

namespace Tests\Feature\Meetings;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Models\MeetingResolution;
use Database\Seeders\Meetings\MeetingsPermissionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

class PermissionMigrationTest extends TestCase
{
    use GrantsEngineCapability;
    use RefreshDatabase;

    public function test_user_with_unrelated_strategy_view_cannot_create_meeting_resolution_decision(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(MeetingsPermissionsSeeder::class);
        $organization = Organization::factory()->create();
        $department = Department::factory()->create(['organization_id' => $organization->id]);
        $user = User::factory()->create([
            'department_id' => $department->id,
            'organization_id' => $organization->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability(
            $user,
            Capability::STRATEGY_VIEW,
            scopeId: $organization->id,
            roleKey: 'strategy_viewer_without_meeting_resolution_create',
        );
        $meeting = Meeting::factory()->create([
            'department_id' => $department->id,
            'organization_id' => $organization->id,
            'organizer_id' => $user->id,
        ]);

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/meetings/{$meeting->id}/resolutions", [
            'meeting_id' => $meeting->id,
            'kind' => MeetingResolution::KIND_DECISION,
            'title' => 'اختبار',
            'owner_id' => $user->id,
        ]);

        $response->assertStatus(403);
    }

    public function test_user_with_meeting_resolution_create_grant_can_create_decision_output(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(MeetingsPermissionsSeeder::class);
        $organization = Organization::factory()->create();
        // Department must share the same org so ProjectObserver does not reroute
        // project.organization_id to a different org (which triggers assertSameOrganization 403).
        $department = Department::factory()->create(['organization_id' => $organization->id]);
        $user = User::factory()->create([
            'department_id' => $department->id,
            'organization_id' => $organization->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability(
            $user,
            Capability::MEETING_RESOLUTIONS_CREATE,
            scopeId: $organization->id,
            roleKey: 'meeting_resolution_creator',
        );
        AccessDecision::flushUserCache($user->id);
        $meeting = Meeting::factory()->create([
            'department_id' => $department->id,
            'organization_id' => $organization->id,
            'organizer_id' => $user->id,
        ]);

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/meetings/{$meeting->id}/resolutions", [
            'meeting_id' => $meeting->id,
            'kind' => MeetingResolution::KIND_DECISION,
            'title' => 'اختبار',
            'owner_id' => $user->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('resolution.kind', MeetingResolution::KIND_DECISION);
    }
}
