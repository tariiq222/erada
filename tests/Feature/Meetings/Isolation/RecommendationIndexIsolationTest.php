<?php

namespace Tests\Feature\Meetings\Isolation;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Models\Recommendation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * RecommendationIndexIsolationTest - Phase 5.C: org-A user cannot see org-B recommendations.
 *
 * GET /api/recommendations routes through RecommendationController::index,
 * which uses Recommendation::scopeVisibleTo() — a Phase 5.A / 5.B additive
 * scope: super_admin sees all, org-scoped user is narrowed to
 * organization_id, and a null-org user is force-emptied.
 *
 * Note: the controller's null-org floor is a 200/empty (scope-level) rather
 * than a 403 (request-level). The model-level guarantee is asserted in
 * MeetingOrganizationScopeTest::test_null_org_non_super_recommendation_index_endpoint_returns_forbidden
 * and DecisionRecommendationVisibilityScopeTest; this file focuses on the
 * org-A vs org-B isolation at the HTTP boundary plus the ?meeting_id filter
 * narrowing visibleTo first.
 */
class RecommendationIndexIsolationTest extends TestCase
{
    use GrantsEngineCapability;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function makeMeeting(Organization $org, ?Department $dept = null): Meeting
    {
        $dept ??= Department::factory()->create(['organization_id' => $org->id]);
        $organizer = User::factory()->create(['organization_id' => $org->id]);

        return Meeting::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'organizer_id' => $organizer->id,
        ]);
    }

    public function test_org_a_user_only_sees_org_a_recommendations(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        $meetingA = $this->makeMeeting($orgA);
        $meetingB = $this->makeMeeting($orgB);

        Recommendation::factory()->count(2)->create([
            'organization_id' => $orgA->id,
            'meeting_id' => $meetingA->id,
        ]);
        Recommendation::factory()->count(3)->create([
            'organization_id' => $orgB->id,
            'meeting_id' => $meetingB->id,
        ]);

        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $actor = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);
        $this->grantEngineCapability($actor, Capability::RECOMMENDATIONS_VIEW);

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson('/api/recommendations');

        $response->assertStatus(200);
        $this->assertSame(2, $response->json('total'));
    }

    public function test_super_admin_sees_all_recommendations(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        $meetingA = $this->makeMeeting($orgA);
        $meetingB = $this->makeMeeting($orgB);

        Recommendation::factory()->count(2)->create([
            'organization_id' => $orgA->id,
            'meeting_id' => $meetingA->id,
        ]);
        Recommendation::factory()->count(3)->create([
            'organization_id' => $orgB->id,
            'meeting_id' => $meetingB->id,
        ]);

        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $superAdmin = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);
        $superAdmin->assignRole('super_admin');

        $response = $this->actingAs($superAdmin, 'sanctum')
            ->getJson('/api/recommendations');

        $response->assertStatus(200);
        $this->assertSame(5, $response->json('total'));
    }

    public function test_org_a_passing_org_b_meeting_id_returns_empty(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        $meetingA = $this->makeMeeting($orgA);
        $meetingB = $this->makeMeeting($orgB);

        Recommendation::factory()->count(2)->create([
            'organization_id' => $orgA->id,
            'meeting_id' => $meetingA->id,
        ]);
        Recommendation::factory()->count(3)->create([
            'organization_id' => $orgB->id,
            'meeting_id' => $meetingB->id,
        ]);

        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $actor = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);
        $this->grantEngineCapability($actor, Capability::RECOMMENDATIONS_VIEW);

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson("/api/recommendations?meeting_id={$meetingB->id}");

        $response->assertStatus(200);
        // scopeVisibleTo narrows first (org A only) — then meeting_id filter
        // would only match org A meetings. The org B meeting id is filtered
        // out by the org floor before the meeting_id filter even matters.
        $this->assertSame(0, $response->json('total'));
    }
}
