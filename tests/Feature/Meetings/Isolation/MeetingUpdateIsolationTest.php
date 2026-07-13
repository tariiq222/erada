<?php

namespace Tests\Feature\Meetings\Isolation;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Projects\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * MeetingUpdateIsolationTest - Phase 5.C: org-A user cannot update org-B meeting.
 *
 * PUT /api/meetings/{meeting} is gated by MeetingPolicy::update (Phase 5.B
 * precheck floor + AccessDecision::can(MEETINGS_EDIT, $meeting)). The
 * FormRequest rules also constrain organizer_id / category_id to actor's org
 * via the org-scoped Exists rules.
 */
class MeetingUpdateIsolationTest extends TestCase
{
    use GrantsEngineCapability;
    use RefreshDatabase;

    private function validUpdatePayload(int $organizerId): array
    {
        return [
            'title' => 'Updated Title',
            'description' => 'updated',
            'scheduled_at' => now()->addDay()->toDateTimeString(),
            'duration_minutes' => 60,
            'organizer_id' => $organizerId,
        ];
    }

    public function test_org_a_user_cannot_update_org_b_meeting(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $organizerB = User::factory()->create(['organization_id' => $orgB->id]);
        $meetingB = Meeting::factory()->create([
            'organization_id' => $orgB->id,
            'organizer_id' => $organizerB->id,
        ]);
        $originalTitle = $meetingB->title;

        $actor = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);
        $this->grantEngineCapability($actor, Capability::MEETINGS_EDIT);

        $payload = $this->validUpdatePayload($organizerB->id);

        $response = $this->actingAs($actor, 'sanctum')
            ->putJson("/api/meetings/{$meetingB->id}", $payload);

        $response->assertStatus(403);
        $this->assertSame($originalTitle, $meetingB->fresh()->title, 'Title must not have changed.');
    }

    public function test_org_a_cannot_change_organization_id_to_org_b_via_payload(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
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
        $this->grantEngineCapability($actor, Capability::MEETINGS_EDIT);

        $payload = $this->validUpdatePayload($organizerA->id);
        $payload['organization_id'] = $orgB->id;

        $response = $this->actingAs($actor, 'sanctum')
            ->putJson("/api/meetings/{$meetingA->id}", $payload);

        // organization_id is NOT in UpdateMeetingRequest's rules(), so Laravel
        // silently strips it from the validated set. The meeting stays org A.
        // 200 is acceptable (no cross-org leakage); the key invariant is that
        // the meeting's organization_id never flips to org B.
        $this->assertContains($response->status(), [200, 422]);
        $this->assertSame(
            $orgA->id,
            $meetingA->fresh()->organization_id,
            'Meeting organization_id must remain org A — payload tampering rejected.'
        );
    }

    public function test_super_admin_can_update_cross_org_meeting(): void
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
        $superAdmin->assignRole('super_admin');

        $payload = $this->validUpdatePayload($organizerB->id);
        $payload['title'] = 'SuperAdmin cross-org update';

        $response = $this->actingAs($superAdmin, 'sanctum')
            ->putJson("/api/meetings/{$meetingB->id}", $payload);

        $response->assertStatus(200);
        $this->assertSame('SuperAdmin cross-org update', $meetingB->fresh()->title);
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
        $this->grantEngineCapability($actor, Capability::MEETINGS_EDIT);

        $payload = $this->validUpdatePayload($organizerA->id);

        $response = $this->actingAs($actor, 'sanctum')
            ->putJson("/api/meetings/{$meetingA->id}", $payload);

        $response->assertStatus(403);
    }

    public function test_super_admin_rebinding_to_cross_org_subject_realigns_organization(): void
    {
        // Pin: when super_admin rebinds a meeting to a subject from a
        // different organization, meetings.organization_id MUST follow the
        // subject. Otherwise we end up with a row whose organization_id
        // no longer agrees with (subject_type, subject_id) — a scope and
        // capability drift.
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $deptB = Department::factory()->create(['organization_id' => $orgB->id]);
        $organizerA = User::factory()->create(['organization_id' => $orgA->id]);
        $meetingA = Meeting::factory()->create([
            'organization_id' => $orgA->id,
            'organizer_id' => $organizerA->id,
            'department_id' => $deptA->id,
            'subject_type' => null,
            'subject_id' => null,
        ]);

        $orgBProject = Project::factory()->create([
            'organization_id' => $orgB->id,
            'department_id' => $deptB->id,
        ]);

        $superAdmin = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);
        $superAdmin->assignRole('super_admin');

        $payload = $this->validUpdatePayload($organizerA->id);
        $payload['subject_type'] = 'project';
        $payload['subject_id'] = $orgBProject->id;

        $this->actingAs($superAdmin, 'sanctum')
            ->putJson("/api/meetings/{$meetingA->id}", $payload)
            ->assertStatus(200);

        $fresh = $meetingA->fresh();
        $this->assertSame(
            $orgB->id,
            $fresh->organization_id,
            'Meeting organization_id must follow the new subject organization.'
        );
        $this->assertSame(
            Project::class,
            $fresh->subject_type,
        );
        $this->assertSame($orgBProject->id, $fresh->subject_id);
    }
}
