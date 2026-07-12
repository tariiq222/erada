<?php

namespace Tests\Feature\Api\Meetings;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Meetings\Models\Meeting;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeetingOrganizationScopeTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $orgA;

    protected Organization $orgB;

    protected User $userA;

    protected User $userB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $deptA = Department::factory()->create();
        $deptB = Department::factory()->create();

        $this->orgA = Organization::factory()->create();
        $this->orgB = Organization::factory()->create();

        $this->userA = User::factory()->create([
            'department_id' => $deptA->id,
            'organization_id' => $this->orgA->id,
            'is_active' => true,
        ]);
        $this->assignCanonicalRole($this->userA, 'admin');

        $this->userB = User::factory()->create([
            'department_id' => $deptB->id,
            'organization_id' => $this->orgB->id,
            'is_active' => true,
        ]);
        $this->assignCanonicalRole($this->userB, 'admin');
    }

    public function test_user_cannot_view_meeting_in_other_organization(): void
    {
        $meeting = Meeting::factory()->create(['organization_id' => $this->orgA->id]);
        $response = $this->actingAs($this->userB, 'sanctum')->getJson("/api/meetings/{$meeting->id}");
        $response->assertStatus(403);
    }

    public function test_user_cannot_update_meeting_in_other_organization(): void
    {
        $meeting = Meeting::factory()->create(['organization_id' => $this->orgA->id]);
        $response = $this->actingAs($this->userB, 'sanctum')->putJson("/api/meetings/{$meeting->id}", [
            'title' => 'hijack',
            'scheduled_at' => now()->addDay()->toIso8601String(),
            'duration_minutes' => 30,
            'organizer_id' => $meeting->organizer_id,
        ]);
        $response->assertStatus(403);
    }

    public function test_list_scopes_to_user_organization(): void
    {
        Meeting::factory()->create(['organization_id' => $this->orgA->id, 'title' => 'A-1']);
        Meeting::factory()->create(['organization_id' => $this->orgB->id, 'title' => 'B-1']);

        $response = $this->actingAs($this->userA, 'sanctum')->getJson('/api/meetings');
        $response->assertStatus(200);
        foreach ($response->json('data') as $row) {
            $this->assertSame($this->orgA->id, $row['organization_id']);
        }
    }

    // ── Cross-org organizer_id / attendee_ids validation (CVE: cross-org notification leak) ──

    /** Store: organizer_id pointing to a different-org user must be rejected with 422. */
    public function test_store_rejects_cross_org_organizer_id(): void
    {
        $response = $this->actingAs($this->userA, 'sanctum')->postJson('/api/meetings', [
            'title' => 'اجتماع اختبار',
            'scheduled_at' => now()->addDay()->toIso8601String(),
            'duration_minutes' => 30,
            'organizer_id' => $this->userB->id, // belongs to orgB — must be rejected
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['organizer_id']);
    }

    /** Store: attendee_ids containing a different-org user must be rejected with 422. */
    public function test_store_rejects_cross_org_attendee_id(): void
    {
        $response = $this->actingAs($this->userA, 'sanctum')->postJson('/api/meetings', [
            'title' => 'اجتماع اختبار',
            'scheduled_at' => now()->addDay()->toIso8601String(),
            'duration_minutes' => 30,
            'organizer_id' => $this->userA->id,
            'attendee_ids' => [$this->userB->id], // belongs to orgB — must be rejected
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['attendee_ids.0']);
    }

    /** Store: same-org organizer_id and attendee_ids must pass validation (201). */
    public function test_store_accepts_same_org_organizer_and_attendees(): void
    {
        $dept = Department::factory()->create();
        $colleague = User::factory()->create([
            'department_id' => $dept->id,
            'organization_id' => $this->orgA->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->userA, 'sanctum')->postJson('/api/meetings', [
            'title' => 'اجتماع داخلي',
            'scheduled_at' => now()->addDay()->toIso8601String(),
            'duration_minutes' => 45,
            'organizer_id' => $this->userA->id,
            'attendee_ids' => [$colleague->id],
        ]);

        $response->assertStatus(201);
    }

    /** Update: organizer_id pointing to a different-org user must be rejected with 422. */
    public function test_update_rejects_cross_org_organizer_id(): void
    {
        $meeting = Meeting::factory()->create([
            'organization_id' => $this->orgA->id,
            'organizer_id' => $this->userA->id,
        ]);

        $response = $this->actingAs($this->userA, 'sanctum')->putJson("/api/meetings/{$meeting->id}", [
            'title' => 'اجتماع معدّل',
            'scheduled_at' => now()->addDay()->toIso8601String(),
            'duration_minutes' => 30,
            'organizer_id' => $this->userB->id, // belongs to orgB — must be rejected
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['organizer_id']);
    }

    /** Update: same-org organizer_id must pass validation (200). */
    public function test_update_accepts_same_org_organizer_id(): void
    {
        $meeting = Meeting::factory()->create([
            'organization_id' => $this->orgA->id,
            'organizer_id' => $this->userA->id,
        ]);

        $response = $this->actingAs($this->userA, 'sanctum')->putJson("/api/meetings/{$meeting->id}", [
            'title' => 'اجتماع معدّل',
            'scheduled_at' => now()->addDay()->toIso8601String(),
            'duration_minutes' => 30,
            'organizer_id' => $this->userA->id,
        ]);

        $response->assertStatus(200);
    }

    /** Attach: attaching a different-org user as attendee must be rejected with 422. */
    public function test_attach_rejects_cross_org_attendee(): void
    {
        $meeting = Meeting::factory()->create([
            'organization_id' => $this->orgA->id,
            'organizer_id' => $this->userA->id,
        ]);

        $response = $this->actingAs($this->userA, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/attendees", [
                'user_id' => $this->userB->id, // belongs to orgB — must be rejected
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['user_id']);
    }

    /** Attach: attaching a same-org user as attendee must succeed (200). */
    public function test_attach_accepts_same_org_attendee(): void
    {
        $dept = Department::factory()->create();
        $colleague = User::factory()->create([
            'department_id' => $dept->id,
            'organization_id' => $this->orgA->id,
            'is_active' => true,
        ]);
        $meeting = Meeting::factory()->create([
            'organization_id' => $this->orgA->id,
            'organizer_id' => $this->userA->id,
        ]);

        $response = $this->actingAs($this->userA, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/attendees", [
                'user_id' => $colleague->id,
            ]);

        $response->assertStatus(200);
    }

    /** Super-admin can set organizer_id to a user in any organization. */
    public function test_super_admin_can_use_cross_org_organizer_id(): void
    {
        $superAdmin = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $this->grantCanonicalSuperAdmin($superAdmin);

        $response = $this->actingAs($superAdmin, 'sanctum')->postJson('/api/meetings', [
            'title' => 'اجتماع عابر للمنظمات',
            'scheduled_at' => now()->addDay()->toIso8601String(),
            'duration_minutes' => 30,
            'organizer_id' => $this->userA->id, // org A user — super-admin may reference any user
        ]);

        // super-admin bypasses org scope; validation should pass
        $response->assertStatus(201);
    }

    /** Super-admin can attach attendees from any organization. */
    public function test_super_admin_can_attach_cross_org_attendee(): void
    {
        $superAdmin = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $this->grantCanonicalSuperAdmin($superAdmin);

        $meeting = Meeting::factory()->create([
            'organization_id' => $this->orgA->id,
            'organizer_id' => $this->userA->id,
        ]);

        $response = $this->actingAs($superAdmin, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/attendees", [
                'user_id' => $this->userB->id, // org B user — super-admin may reference any user
            ]);

        $response->assertStatus(200);
    }
}
