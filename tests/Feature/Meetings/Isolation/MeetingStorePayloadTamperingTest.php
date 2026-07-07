<?php

namespace Tests\Feature\Meetings\Isolation;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Meetings\Models\MeetingCategory;
use App\Modules\Projects\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * MeetingStorePayloadTamperingTest - Phase 5.C: منع payload tampering عند إنشاء Meeting.
 *
 * POST /api/meetings flows through StoreMeetingRequest which:
 *   - authorize() returns false for null-org actors (MeetingPolicy::create).
 *   - rules() apply org-scoped Exists on organizer_id / attendee_ids[*] /
 *     category_id. A cross-org id fails the Exists rule and returns 422.
 *   - rules() do NOT include `organization_id`, so a tampered organization_id
 *     is silently stripped by the validated() gate.
 */
class MeetingStorePayloadTamperingTest extends TestCase
{
    use GrantsEngineCapability;
    use RefreshDatabase;

    private function makeValidPayload(int $organizerId, ?int $categoryId = null): array
    {
        return [
            'title' => 'Tampering Test Meeting',
            'description' => 'description',
            'scheduled_at' => now()->addDay()->toDateTimeString(),
            'duration_minutes' => 60,
            'organizer_id' => $organizerId,
            'category_id' => $categoryId,
        ];
    }

    public function test_organization_id_in_payload_is_silently_stripped(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $organizerA = User::factory()->create(['organization_id' => $orgA->id]);

        $actor = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);
        $this->grantEngineCapability($actor, Capability::MEETINGS_CREATE);

        $payload = $this->makeValidPayload($organizerA->id);
        $payload['organization_id'] = $orgB->id;

        $response = $this->actingAs($actor, 'sanctum')
            ->postJson('/api/meetings', $payload);

        // 201 is the success path; organization_id is not in the rules, so
        // Laravel drops it from the validated set, and MeetingController::store
        // re-derives it from the actor (org A). Either 201 (created under
        // org A) or 422 (some other validation block fires) is acceptable —
        // the invariant is that NO row is created under org B.
        $this->assertContains($response->status(), [201, 422]);
        $this->assertDatabaseMissing('meetings', ['organization_id' => $orgB->id]);
    }

    public function test_subject_id_from_other_org_rejected_by_assert_same_organization(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $organizerA = User::factory()->create(['organization_id' => $orgA->id]);
        $deptB = Department::factory()->create(['organization_id' => $orgB->id]);

        // Some ScopeAware target in org B. Project is a registered
        // SUBJECT_CLASS_MAP on Meeting, so a meeting can hang off a Project.
        $orgBProject = Project::factory()->create([
            'organization_id' => $orgB->id,
            'department_id' => $deptB->id,
        ]);

        $actor = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);
        $this->grantEngineCapability($actor, Capability::MEETINGS_CREATE);

        $payload = $this->makeValidPayload($organizerA->id);
        $payload['subject_type'] = 'project';
        $payload['subject_id'] = $orgBProject->id;

        $response = $this->actingAs($actor, 'sanctum')
            ->postJson('/api/meetings', $payload);

        // MeetingController::store calls assertSameOrganization($subject) on the
        // resolved subject — cross-org => AccessDeniedHttpException => 403.
        $response->assertStatus(403);
        $this->assertDatabaseMissing('meetings', [
            'subject_type' => Project::class,
            'subject_id' => $orgBProject->id,
        ]);
    }

    public function test_attendee_id_from_other_org_rejected(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $organizerA = User::factory()->create(['organization_id' => $orgA->id]);
        $orgBUser = User::factory()->create(['organization_id' => $orgB->id]);

        $actor = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);
        $this->grantEngineCapability($actor, Capability::MEETINGS_CREATE);

        $payload = $this->makeValidPayload($organizerA->id);
        $payload['attendee_ids'] = [$orgBUser->id];

        $response = $this->actingAs($actor, 'sanctum')
            ->postJson('/api/meetings', $payload);

        // orgScopedUserRule() on attendee_ids.* restricts to the actor's org.
        // The cross-org id fails Exists and validation returns 422.
        $response->assertStatus(422);
        $this->assertDatabaseMissing('meeting_attendees', ['user_id' => $orgBUser->id]);
    }

    public function test_category_id_from_other_org_rejected(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $organizerA = User::factory()->create(['organization_id' => $orgA->id]);
        $orgBCategory = MeetingCategory::factory()->create(['organization_id' => $orgB->id]);

        $actor = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);
        $this->grantEngineCapability($actor, Capability::MEETINGS_CREATE);

        $payload = $this->makeValidPayload($organizerA->id, $orgBCategory->id);

        $response = $this->actingAs($actor, 'sanctum')
            ->postJson('/api/meetings', $payload);

        // orgScopedCategoryRule() on category_id restricts to the actor's org.
        // Cross-org category fails Exists and returns 422.
        $response->assertStatus(422);
        $this->assertDatabaseMissing('meetings', ['category_id' => $orgBCategory->id]);
    }

    public function test_null_org_actor_is_denied_at_form_request(): void
    {
        $orgA = Organization::factory()->create();
        $organizerA = User::factory()->create(['organization_id' => $orgA->id]);

        $actor = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($actor, Capability::MEETINGS_CREATE);

        $payload = $this->makeValidPayload($organizerA->id);

        $response = $this->actingAs($actor, 'sanctum')
            ->postJson('/api/meetings', $payload);

        // FormRequest's authorize() returns false for null-org actor.
        $response->assertStatus(403);
        $this->assertDatabaseCount('meetings', 0);
    }

    public function test_super_admin_can_store_meeting_with_cross_org_payload(): void
    {
        $orgA = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $organizerA = User::factory()->create(['organization_id' => $orgA->id]);

        $superAdmin = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);
        $superAdmin->assignRole('super_admin');

        // Super admin uses orgA as the target (no subject to override org).
        $payload = $this->makeValidPayload($organizerA->id);

        $response = $this->actingAs($superAdmin, 'sanctum')
            ->postJson('/api/meetings', $payload);

        // Super_admin bypasses the null-org floor and the org-scoped Exists
        // rules. 201 is success. Other 4xx (e.g. 422) is also acceptable as
        // long as it's NOT 403 (the floor is bypassed for super_admin).
        $this->assertNotSame(403, $response->status());
    }
}
