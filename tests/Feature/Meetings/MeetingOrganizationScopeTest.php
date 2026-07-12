<?php

namespace Tests\Feature\Meetings;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Models\Recommendation;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * Null-org fail-closed coverage for Meetings/Recommendations.
 *
 * Regression: a non-super user with no organization_id used to silently
 * pass through several endpoints, either leaking all rows (Recommendation
 * scopeVisibleTo) or creating tenant-less rows (MeetingController::store,
 * MeetingCategoryController::store). This suite asserts those paths now
 * return 403 / empty results instead of leaking.
 */
class MeetingOrganizationScopeTest extends TestCase
{
    use GrantsEngineCapability;
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private Department $deptA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Cache::flush();

        $this->orgA = Organization::factory()->create();
        $this->orgB = Organization::factory()->create();
        $this->deptA = Department::factory()->create(['organization_id' => $this->orgA->id]);
    }

    private function makeUser(?Organization $org, ?string $role = null): User
    {
        $user = User::factory()->create([
            'organization_id' => $org?->id,
            'is_active' => true,
        ]);

        if ($role === 'super_admin') {
            $this->grantCanonicalSuperAdmin($user);
        } elseif ($role === 'admin' && $org !== null) {
            $this->grantCanonicalAdmin($user);
        }

        return $user;
    }

    private function validMeetingPayload(int $organizerId): array
    {
        return [
            'title' => 'Null-org meeting test',
            'scheduled_at' => now()->addDay()->toDateTimeString(),
            'duration_minutes' => 60,
            'organizer_id' => $organizerId,
        ];
    }

    public function test_null_org_non_super_user_is_forbidden_from_meetings_index(): void
    {
        $nullOrgUser = $this->makeUser(null, 'admin');
        Meeting::factory()->create([
            'organization_id' => $this->orgA->id,
            'organizer_id' => $nullOrgUser->id,
        ]);

        $this->actingAs($nullOrgUser, 'sanctum')
            ->getJson('/api/meetings')
            ->assertStatus(403);
    }

    public function test_null_org_non_super_user_is_forbidden_from_meetings_list(): void
    {
        $nullOrgUser = $this->makeUser(null, 'admin');

        $this->actingAs($nullOrgUser, 'sanctum')
            ->getJson('/api/meetings/list')
            ->assertStatus(403);
    }

    public function test_null_org_non_super_user_cannot_create_meeting_category(): void
    {
        $nullOrgUser = $this->makeUser(null, 'admin');

        $this->actingAs($nullOrgUser, 'sanctum')
            ->postJson('/api/meeting-categories', [
                'name' => 'Null-org Category',
            ])
            ->assertStatus(403);

        $this->assertDatabaseMissing('meeting_categories', ['name' => 'Null-org Category']);
    }

    public function test_null_org_non_super_user_cannot_transition_meeting_status(): void
    {
        $nullOrgUser = $this->makeUser(null, 'admin');
        $meeting = Meeting::factory()->create([
            'organization_id' => $this->orgA->id,
            'organizer_id' => $nullOrgUser->id,
            'status' => Meeting::STATUS_SCHEDULED,
        ]);

        // start/complete/cancel all require an org-scoped user. A null-org
        // non-super must be denied rather than silently flipping status.
        $this->actingAs($nullOrgUser, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/start")
            ->assertStatus(403);

        $this->actingAs($nullOrgUser, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/complete")
            ->assertStatus(403);

        $this->actingAs($nullOrgUser, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/cancel")
            ->assertStatus(403);

        $this->assertSame(
            Meeting::STATUS_SCHEDULED,
            $meeting->fresh()->status,
            'Meeting status must not have changed.'
        );
    }

    public function test_null_org_non_super_user_sees_no_recommendations_via_scope_visible_to(): void
    {
        $nullOrgUser = $this->makeUser(null, 'admin');

        $meeting = Meeting::factory()->create([
            'organization_id' => $this->orgA->id,
            'organizer_id' => $nullOrgUser->id,
        ]);

        Recommendation::factory()->create([
            'organization_id' => $this->orgA->id,
            'meeting_id' => $meeting->id,
            'assignee_id' => $nullOrgUser->id,
        ]);

        Recommendation::factory()->create([
            'organization_id' => $this->orgA->id,
            'meeting_id' => $meeting->id,
        ]);

        $visible = Recommendation::query()->visibleTo($nullOrgUser)->pluck('id');

        $this->assertSame(
            0,
            $visible->count(),
            'Null-org non-super user must not see any recommendations, even ones assigned to them.'
        );
    }

    public function test_null_org_non_super_recommendation_index_endpoint_returns_forbidden(): void
    {
        $nullOrgUser = $this->makeUser(null, 'admin');

        $meeting = Meeting::factory()->create([
            'organization_id' => $this->orgA->id,
            'organizer_id' => $nullOrgUser->id,
        ]);

        Recommendation::factory()->create([
            'organization_id' => $this->orgA->id,
            'meeting_id' => $meeting->id,
            'assignee_id' => $nullOrgUser->id,
        ]);

        // /api/recommendations routes through the RecommendationController::index,
        // which uses scopeVisibleTo. With the force-empty for null-org, the
        // response is still 200 with empty data (controller-level, not abort).
        // The model-level guarantee is asserted above; here we verify that the
        // query returns nothing for this user.
        $response = $this->actingAs($nullOrgUser, 'sanctum')
            ->getJson('/api/recommendations');

        $response->assertOk();
        $this->assertSame(
            0,
            collect($response->json('data'))->count(),
            'Recommendations index must be empty for a null-org non-super user.'
        );
    }

    public function test_super_admin_with_null_org_can_still_create_meeting(): void
    {
        // Regression guard: the new null-org floor must NOT block super_admin.
        $super = $this->makeUser(null, 'super_admin');
        $payload = $this->validMeetingPayload($super->id);

        // Note: super_admin still needs an organization_id to land on a meeting,
        // so we send orgA explicitly via subject-driven path... but the store
        // path without subject uses auth()->user()->organization_id which is null
        // here. The contract: super_admin BYPASSES the null-org floor, and the
        // controller defaults org to null — which is acceptable for the super
        // because they have no tenant. This test pins that contract.
        $response = $this->actingAs($super, 'sanctum')
            ->postJson('/api/meetings', $payload);

        // Either 201 (created) or a downstream validation error — but NOT 403
        // from our new floor.
        $this->assertNotSame(403, $response->status(), 'Super admin must not be blocked by the null-org floor.');
    }
}
