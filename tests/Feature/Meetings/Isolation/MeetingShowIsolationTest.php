<?php

namespace Tests\Feature\Meetings\Isolation;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Meetings\Models\Meeting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * MeetingShowIsolationTest - Phase 5.C: org-A user cannot show org-B meeting.
 *
 * GET /api/meetings/{meeting} is gated by MeetingPolicy::view, which uses
 * precheck() (org-id floor) + AccessDecision::can(MEETINGS_VIEW, $meeting).
 * Cross-org and null-org actors must receive 403.
 */
class MeetingShowIsolationTest extends TestCase
{
    use GrantsEngineCapability;
    use RefreshDatabase;

    public function test_org_a_user_cannot_show_org_b_meeting(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $organizerB = User::factory()->create(['organization_id' => $orgB->id]);

        $meetingB = Meeting::factory()->create([
            'organization_id' => $orgB->id,
            'organizer_id' => $organizerB->id,
        ]);

        $actor = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);
        $this->grantEngineCapability($actor, Capability::MEETINGS_VIEW);

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson("/api/meetings/{$meetingB->id}");

        $response->assertStatus(403);
    }

    public function test_org_a_user_can_show_org_a_meeting(): void
    {
        $orgA = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $organizerA = User::factory()->create(['organization_id' => $orgA->id]);

        $meetingA = Meeting::factory()->create([
            'organization_id' => $orgA->id,
            'organizer_id' => $organizerA->id,
        ]);

        $actor = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);
        $this->grantEngineCapability($actor, Capability::MEETINGS_VIEW);

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson("/api/meetings/{$meetingA->id}");

        $response->assertStatus(200);
        $this->assertSame($meetingA->id, $response->json('id'));
    }

    public function test_super_admin_can_show_any_meeting(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $organizerB = User::factory()->create(['organization_id' => $orgB->id]);

        $meetingB = Meeting::factory()->create([
            'organization_id' => $orgB->id,
            'organizer_id' => $organizerB->id,
        ]);

        $superAdmin = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);
        $this->grantCanonicalSuperAdmin($superAdmin);

        $response = $this->actingAs($superAdmin, 'sanctum')
            ->getJson("/api/meetings/{$meetingB->id}");

        $response->assertStatus(200);
        $this->assertSame($meetingB->id, $response->json('id'));
    }

    public function test_null_org_actor_is_denied(): void
    {
        $orgA = Organization::factory()->create();
        $organizerA = User::factory()->create(['organization_id' => $orgA->id]);
        $meetingA = Meeting::factory()->create([
            'organization_id' => $orgA->id,
            'organizer_id' => $organizerA->id,
        ]);

        $actor = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($actor, Capability::MEETINGS_VIEW);

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson("/api/meetings/{$meetingA->id}");

        $response->assertStatus(403);
    }
}
