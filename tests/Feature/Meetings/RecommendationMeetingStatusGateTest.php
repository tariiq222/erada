<?php

namespace Tests\Feature\Meetings;

use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Models\Recommendation;
use App\Modules\Projects\Models\Project;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RecommendationMeetingStatusGateTest
 *
 * Ppins the meeting-status gate on RecommendationController::store():
 * creating a recommendation against a cancelled meeting must be refused
 * with HTTP 422. The gate only blocks NEW recommendations — existing
 * recommendations attached to a cancelled meeting stay put.
 *
 * Both kinds (ruling and action_item) are exercised independently so
 * neither gate could silently skip one of them.
 */
class RecommendationMeetingStatusGateTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Department $dept;

    private Project $project;

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
        $this->grantCanonicalSuperAdmin($this->user);

        $this->meeting = Meeting::factory()->create([
            'department_id' => $this->dept->id,
            'organization_id' => $this->project->organization_id,
            'organizer_id' => $this->user->id,
            'status' => Meeting::STATUS_SCHEDULED,
        ]);
    }

    public function test_store_on_active_meeting_succeeds_for_ruling(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/recommendations', [
                'kind' => Recommendation::KIND_RULING,
                'meeting_id' => $this->meeting->id,
                'title' => 'قرار على نشط',
                'type' => 'approval',
                'priority' => Recommendation::PRIORITY_MEDIUM,
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('recommendations', ['title' => 'قرار على نشط']);
    }

    public function test_store_on_active_meeting_succeeds_for_action_item(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/recommendations', [
                'kind' => Recommendation::KIND_ACTION_ITEM,
                'meeting_id' => $this->meeting->id,
                'title' => 'إجراء على نشط',
                'assignee_id' => $this->user->id,
                'due_date' => now()->addDays(3)->toDateString(),
                'priority' => Recommendation::PRIORITY_MEDIUM,
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('recommendations', ['title' => 'إجراء على نشط']);
    }

    public function test_store_ruling_on_cancelled_meeting_returns_422(): void
    {
        $this->meeting->update(['status' => Meeting::STATUS_CANCELLED]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/recommendations', [
                'kind' => Recommendation::KIND_RULING,
                'meeting_id' => $this->meeting->id,
                'title' => 'قرار بعد الإلغاء',
                'type' => 'approval',
                'priority' => Recommendation::PRIORITY_MEDIUM,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'لا يمكن إضافة توصيات لاجتماع ملغى');

        $this->assertDatabaseMissing('recommendations', ['title' => 'قرار بعد الإلغاء']);
    }

    public function test_store_action_item_on_cancelled_meeting_returns_422(): void
    {
        $this->meeting->update(['status' => Meeting::STATUS_CANCELLED]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/recommendations', [
                'kind' => Recommendation::KIND_ACTION_ITEM,
                'meeting_id' => $this->meeting->id,
                'title' => 'إجراء بعد الإلغاء',
                'assignee_id' => $this->user->id,
                'due_date' => now()->addDays(3)->toDateString(),
                'priority' => Recommendation::PRIORITY_MEDIUM,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'لا يمكن إضافة توصيات لاجتماع ملغى');

        $this->assertDatabaseMissing('recommendations', ['title' => 'إجراء بعد الإلغاء']);
    }

    public function test_cancellation_does_not_retroactively_destroy_attached_recommendations(): void
    {
        // Pre-existing recommendations on the meeting must survive the
        // meeting being cancelled — the gate only blocks NEW writes.
        $rec = Recommendation::create([
            'kind' => Recommendation::KIND_RULING,
            'meeting_id' => $this->meeting->id,
            'title' => 'قرار سابق',
            'type' => 'approval',
            'requested_by' => $this->user->id,
            'status' => Recommendation::STATUS_PENDING,
            'priority' => Recommendation::PRIORITY_MEDIUM,
            'organization_id' => $this->project->organization_id,
        ]);

        $this->meeting->update(['status' => Meeting::STATUS_CANCELLED]);

        // Read still 200; no status change on the recommendation itself.
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/recommendations/{$rec->id}");

        $response->assertStatus(200);
        $this->assertSame(Recommendation::STATUS_PENDING, $rec->fresh()->status);
        $this->assertSame(Meeting::STATUS_CANCELLED, $this->meeting->fresh()->status);
    }

    public function test_store_without_meeting_id_is_unaffected_by_the_meeting_gate(): void
    {
        // The gate is keyed on `meeting_id`. A recommendation without a
        // meeting_id (ad-hoc / unattached) must NOT be blocked by the gate.
        // (Form-level validation may still reject kind-only-mismatch errors
        // for action_item, so we use kind=ruling here so no body extras are
        // required by validation.)
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/recommendations', [
                'kind' => Recommendation::KIND_RULING,
                'meeting_id' => null,
                'title' => 'قرار بدون اجتماع',
                'type' => 'approval',
                'priority' => Recommendation::PRIORITY_MEDIUM,
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('recommendations', ['title' => 'قرار بدون اجتماع']);
    }

    public function test_store_with_unknown_meeting_id_returns_validation_error(): void
    {
        // Unknown meeting id -> validation error from exists rule, NOT the
        // meeting-status gate. Ensures the gate fires AFTER validation.
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/recommendations', [
                'kind' => Recommendation::KIND_RULING,
                'meeting_id' => 999999,
                'title' => 'قرار وهمي',
                'type' => 'approval',
                'priority' => Recommendation::PRIORITY_MEDIUM,
            ]);

        $response->assertStatus(422);
        // The "cancelled meeting" message must NOT appear — the gate didn't fire.
        $this->assertNotSame('لا يمكن إضافة توصيات لاجتماع ملغى', $response->json('message'));
    }
}
