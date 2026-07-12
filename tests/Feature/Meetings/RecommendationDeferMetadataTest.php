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
 * RecommendationDeferMetadataTest
 *
 * DeferRecommendationRequest persists two free-form metadata fields on
 * the recommendation:
 *
 *   - defer_reason    (free text, minimum 5 chars)
 *   - deferred_until  (date, must be today or later)
 *
 * Plus three implicit audit fields populated by RecommendationController::defer():
 *
 *   - deferred_by     (the acting user's id)
 *   - deferred_at     (the call timestamp)
 *
 * These tests pin that the metadata round-trips from request -> DB ->
 * response JSON correctly for both kinds, and that the validation rules
 * defer_reason (min 5) and deferred_until (after_or_equal:today) gate bad
 * input with 422.
 */
class RecommendationDeferMetadataTest extends TestCase
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

    public function test_defer_persists_reason_and_until_on_action_item(): void
    {
        $rec = $this->makeActionItem(Recommendation::STATUS_PROPOSED);

        $until = now()->addDays(7)->toDateString();
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/recommendations/{$rec->id}/defer", [
                'defer_reason' => 'بانتظار اعتماد الإدارة',
                'deferred_until' => $until,
            ]);

        $response->assertStatus(200);

        $fresh = $rec->fresh();
        $this->assertSame('بانتظار اعتماد الإدارة', $fresh->defer_reason);
        // deferred_until is a `timestamp` column without a model cast, so the
        // raw value comes back as a string — compare the date portion only.
        $this->assertSame($until, substr((string) $fresh->deferred_until, 0, 10));
        $this->assertSame($this->user->id, $fresh->deferred_by);
        $this->assertNotNull($fresh->deferred_at);
    }

    public function test_defer_persists_audit_metadata_on_ruling(): void
    {
        $other = User::factory()->create([
            'department_id' => $this->dept->id,
            'organization_id' => $this->project->organization_id,
            'is_active' => true,
        ]);
        $this->grantCanonicalSuperAdmin($other);

        $ruling = Recommendation::create([
            'kind' => Recommendation::KIND_RULING,
            'meeting_id' => $this->meeting->id,
            'title' => 'قرار للتأجيل',
            'type' => 'approval',
            'requested_by' => $this->user->id,
            'status' => Recommendation::STATUS_PENDING,
            'priority' => Recommendation::PRIORITY_MEDIUM,
            'organization_id' => $this->project->organization_id,
        ]);

        $until = now()->addDays(14)->toDateString();
        $response = $this->actingAs($other, 'sanctum')
            ->postJson("/api/recommendations/{$ruling->id}/defer", [
                'defer_reason' => 'بانتظار تقرير الدراسة الفنية',
                'deferred_until' => $until,
            ]);

        $response->assertStatus(200);

        $fresh = $ruling->fresh();
        $this->assertSame('بانتظار تقرير الدراسة الفنية', $fresh->defer_reason);
        $this->assertSame($until, substr((string) $fresh->deferred_until, 0, 10));
        $this->assertSame($other->id, $fresh->deferred_by);
        $this->assertNotNull($fresh->deferred_at);
    }

    public function test_defer_allows_null_until_when_only_reason_supplied(): void
    {
        $rec = $this->makeActionItem(Recommendation::STATUS_PROPOSED);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/recommendations/{$rec->id}/defer", [
                'defer_reason' => 'بانتظار توضيح إضافي',
            ]);

        $response->assertStatus(200);

        $fresh = $rec->fresh();
        $this->assertSame('بانتظار توضيح إضافي', $fresh->defer_reason);
        $this->assertNull($fresh->deferred_until);
        $this->assertSame($this->user->id, $fresh->deferred_by);
    }

    public function test_defer_rejects_reason_shorter_than_minimum(): void
    {
        $rec = $this->makeActionItem(Recommendation::STATUS_PROPOSED);

        // defer_reason rule: min:5. A 3-char reason must be refused.
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/recommendations/{$rec->id}/defer", [
                'defer_reason' => 'لا',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['defer_reason']);
        $this->assertSame(Recommendation::STATUS_PROPOSED, $rec->fresh()->status);
    }

    public function test_defer_rejects_until_in_the_past(): void
    {
        $rec = $this->makeActionItem(Recommendation::STATUS_PROPOSED);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/recommendations/{$rec->id}/defer", [
                'defer_reason' => 'سبب كافٍ للطول المطلوب',
                'deferred_until' => now()->subDays(1)->toDateString(),
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['deferred_until']);
        $this->assertSame(Recommendation::STATUS_PROPOSED, $rec->fresh()->status);
    }

    public function test_defer_rejects_until_today_boundary(): void
    {
        // Boundary: deferred_until must be after_or_equal:today; "today"
        // must be accepted, "yesterday" must be refused. This pins both
        // sides of the boundary in one logical check.
        $rec = $this->makeActionItem(Recommendation::STATUS_PROPOSED);

        // Today boundary — accepted.
        $ok = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/recommendations/{$rec->id}/defer", [
                'defer_reason' => 'تأجيل حتى نهاية اليوم',
                'deferred_until' => now()->toDateString(),
            ]);
        $ok->assertStatus(200);

        // Set it back to proposed for the negative path.
        $rec->update(['status' => Recommendation::STATUS_PROPOSED]);

        $bad = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/recommendations/{$rec->id}/defer", [
                'defer_reason' => 'تأجيل ليوم ماضٍ',
                'deferred_until' => now()->subDay()->toDateString(),
            ]);
        $bad->assertStatus(422)
            ->assertJsonValidationErrors(['deferred_until']);
    }

    public function test_defer_response_includes_metadata_fields(): void
    {
        $rec = $this->makeActionItem(Recommendation::STATUS_PROPOSED);
        $until = now()->addDays(5)->toDateString();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/recommendations/{$rec->id}/defer", [
                'defer_reason' => 'تأجيل قصير متعمد',
                'deferred_until' => $until,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('recommendation.status', Recommendation::STATUS_DEFERRED)
            ->assertJsonPath('recommendation.defer_reason', 'تأجيل قصير متعمد')
            ->assertJsonPath('recommendation.deferred_by', $this->user->id);
    }

    private function makeActionItem(string $status): Recommendation
    {
        return Recommendation::create([
            'kind' => Recommendation::KIND_ACTION_ITEM,
            'meeting_id' => $this->meeting->id,
            'title' => 'إجراء للاختبار',
            'assignee_id' => $this->user->id,
            'due_date' => now()->addDays(5)->toDateString(),
            'status' => $status,
            'priority' => Recommendation::PRIORITY_MEDIUM,
            'organization_id' => $this->project->organization_id,
        ]);
    }
}
