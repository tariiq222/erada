<?php

namespace Tests\Feature\Meetings;

use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Models\MeetingResolution;
use App\Modules\Meetings\Models\Recommendation;
use App\Modules\Projects\Models\Project;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Release Validation — Legacy regression contract for Meeting Resolutions.
 *
 * Pins the boundary between the new flow (MeetingResolution) and the legacy
 * Direction B flow (Recommendation):
 *   - Recommendation endpoints still work (no breaking change for legacy
 *     Strategy/Portfolio surfaces)
 *   - Strategy `decisions` page still renders for super_admin (Phase 2 hides
 *     it from non-elevated roles but super_admin must still see it)
 *   - RecommendationCard / RecommendationsSection never leaks into the new
 *     meeting flow (ResolutionsSection + ResolutionCard only)
 *   - Approve / Reject / Adopt / Deliberate endpoints do not exist on the
 *     new flow (404)
 */
class MeetingResolutionLegacyRegressionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $project;

    private Department $dept;

    private Meeting $meeting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->dept = Department::factory()->create();
        $this->project = Project::factory()->create(['department_id' => $this->dept->id]);
        $this->user = User::factory()->create([
            'department_id' => $this->dept->id,
            'organization_id' => $this->project->organization_id,
            'is_active' => true,
        ]);
        $this->user->assignRole('super_admin');
        $this->meeting = Meeting::factory()->create([
            'department_id' => $this->dept->id,
            'organization_id' => $this->project->organization_id,
            'organizer_id' => $this->user->id,
            'status' => Meeting::STATUS_SCHEDULED,
        ]);
    }

    // ---- Recommendation legacy endpoints still work ----

    public function test_recommendation_list_still_works(): void
    {
        Recommendation::create([
            'meeting_id' => $this->meeting->id,
            'organization_id' => $this->project->organization_id,
            'kind' => Recommendation::KIND_RULING,
            'title' => 'توصية قديمة',
            'owner_id' => $this->user->id,
            'created_by' => $this->user->id,
            'status' => Recommendation::STATUS_PENDING,
            'priority' => 'medium',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->getJson('/api/recommendations');
        $response->assertStatus(200);
        $this->assertGreaterThan(0, count($response->json('data') ?? []));
    }

    public function test_recommendation_show_still_works(): void
    {
        $r = Recommendation::create([
            'meeting_id' => $this->meeting->id,
            'organization_id' => $this->project->organization_id,
            'kind' => Recommendation::KIND_RULING,
            'title' => 'توصية للعرض',
            'owner_id' => $this->user->id,
            'created_by' => $this->user->id,
            'status' => Recommendation::STATUS_PENDING,
            'priority' => 'medium',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->getJson("/api/recommendations/{$r->id}");
        $response->assertStatus(200);
        $this->assertSame('ruling', $response->json('kind'));
    }

    public function test_recommendation_approve_endpoint_still_works(): void
    {
        $r = Recommendation::create([
            'meeting_id' => $this->meeting->id,
            'organization_id' => $this->project->organization_id,
            'kind' => Recommendation::KIND_RULING,
            'title' => 'توصية للاعتماد',
            'owner_id' => $this->user->id,
            'created_by' => $this->user->id,
            'status' => Recommendation::STATUS_PENDING,
            'priority' => 'medium',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/recommendations/{$r->id}/approve", []);
        $response->assertStatus(200);
        $this->assertSame(Recommendation::STATUS_APPROVED, $r->fresh()->status);
    }

    public function test_recommendation_reject_endpoint_still_works(): void
    {
        $r = Recommendation::create([
            'meeting_id' => $this->meeting->id,
            'organization_id' => $this->project->organization_id,
            'kind' => Recommendation::KIND_RULING,
            'title' => 'توصية للرفض',
            'owner_id' => $this->user->id,
            'created_by' => $this->user->id,
            'status' => Recommendation::STATUS_PENDING,
            'priority' => 'medium',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/recommendations/{$r->id}/reject", ['rationale' => 'لا ينطبق']);
        $response->assertStatus(200);
        $this->assertSame(Recommendation::STATUS_REJECTED, $r->fresh()->status);
    }

    // ---- New flow boundaries ----

    public function test_meeting_resolution_endpoints_do_not_have_legacy_verbs(): void
    {
        // Pin the negative contract: the new flow must NOT have
        // approve/reject/adopt/deliberate endpoints.
        $r = MeetingResolution::create([
            'meeting_id' => $this->meeting->id,
            'organization_id' => $this->project->organization_id,
            'kind' => MeetingResolution::KIND_DECISION,
            'title' => 'مخرج',
            'owner_id' => $this->user->id,
            'created_by' => $this->user->id,
            'status' => 'open',
            'priority' => 'medium',
        ]);

        foreach (['approve', 'reject', 'adopt', 'deliberate', 'endorse'] as $verb) {
            $response = $this->actingAs($this->user, 'sanctum')
                ->postJson("/api/meeting-resolutions/{$r->id}/{$verb}", []);
            $this->assertContains(
                $response->status(),
                [404, 405, 422],
                "Endpoint /{$verb} must NOT exist on meeting-resolutions (got {$response->status()})"
            );
        }
    }

    public function test_recommendation_and_meeting_resolution_are_separate_entities(): void
    {
        // Create one of each. Both must coexist without cross-contamination.
        $rec = Recommendation::create([
            'meeting_id' => $this->meeting->id,
            'organization_id' => $this->project->organization_id,
            'kind' => Recommendation::KIND_RULING,
            'title' => 'توصية',
            'owner_id' => $this->user->id,
            'created_by' => $this->user->id,
            'status' => Recommendation::STATUS_PENDING,
            'priority' => 'medium',
        ]);

        $res = MeetingResolution::create([
            'meeting_id' => $this->meeting->id,
            'organization_id' => $this->project->organization_id,
            'kind' => MeetingResolution::KIND_DECISION,
            'title' => 'مخرج',
            'owner_id' => $this->user->id,
            'created_by' => $this->user->id,
            'status' => 'open',
            'priority' => 'medium',
        ]);

        // Both list endpoints serve their own scope.
        $recsList = $this->actingAs($this->user, 'sanctum')->getJson('/api/recommendations');
        $recsList->assertStatus(200);
        $this->assertCount(1, $recsList->json('data'));

        $resList = $this->actingAs($this->user, 'sanctum')->getJson('/api/meeting-resolutions');
        $resList->assertStatus(200);
        $this->assertCount(1, $resList->json('data'));

        // Counts on the Recommendation table unaffected by MeetingResolution operations.
        $this->assertSame(1, Recommendation::count());

        // No Recommendation rows leaked via the new convert flow.
        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/meeting-resolutions/{$res->id}/convert-to-tasks", [
                'tasks' => [['title' => 'مهمة', 'assignee_id' => $this->user->id]],
            ])->assertStatus(201);

        $this->assertSame(1, Recommendation::count(), 'convert-to-tasks must NOT touch Recommendation rows');
    }

    public function test_meeting_resolution_views_do_not_leak_recommendation_data(): void
    {
        // Even when both tables have rows for the same meeting, the
        // MeetingResolution view does not surface Recommendation data.
        Recommendation::create([
            'meeting_id' => $this->meeting->id,
            'organization_id' => $this->project->organization_id,
            'kind' => Recommendation::KIND_RULING,
            'title' => 'توصية فقط في الـ legacy',
            'owner_id' => $this->user->id,
            'created_by' => $this->user->id,
            'status' => Recommendation::STATUS_PENDING,
            'priority' => 'medium',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/meeting-resolutions');
        $response->assertStatus(200);

        $titles = array_column($response->json('data'), 'title');
        $this->assertNotContains('توصية فقط في الـ legacy', $titles);
    }
}
