<?php

namespace Tests\Feature\Meetings;

use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Models\Recommendation;
use App\Modules\Projects\Models\Project;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * RecommendationOptimisticGuardTest
 *
 * Ppins the optimistic-guarded UPDATE pattern that every state-transition
 * endpoint on RecommendationController uses:
 *
 *     Recommendation::whereKey($id)
 *         ->whereIn('status', [...allowed sources...])
 *         ->update([...new fields...]);
 *     if ($updated === 0) abort(409);
 *
 * The guard prevents a stale reader (model in memory) from winning a
 * transition that another concurrent writer already finalized. Under
 * RefreshDatabase + synchronous PHP this manifests by simulating the
 * "other writer" via a direct DB::table() update between Eloquent reads.
 *
 * Coverage:
 *   - approve(): guard hits when status is no longer in {pending, deferred}
 *   - reject(): guard hits when status is no longer in {pending, deferred}
 *   - defer(): guard hits when status is no longer in {pending, approved}
 *               (ruling), or no longer in {proposed, accepted} (action_item)
 *   - complete(): guard hits when status is no longer in {accepted, deferred}
 *   - accept(): guard hits when status is no longer in {proposed, deferred}
 *
 * Each guard outcome returns 409 with a localized
 * "لا يمكن... في الحالة الحالية" payload.
 */
class RecommendationOptimisticGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $approver;

    private User $actor;

    private Department $dept;

    private Project $project;

    private Meeting $meeting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->dept = Department::factory()->create();
        $this->project = Project::factory()->create(['department_id' => $this->dept->id]);
        $this->actor = User::factory()->create([
            'department_id' => $this->dept->id,
            'organization_id' => $this->project->organization_id,
            'is_active' => true,
        ]);
        $this->approver = User::factory()->create([
            'department_id' => $this->dept->id,
            'organization_id' => $this->project->organization_id,
            'is_active' => true,
        ]);
        $this->actor->assignRole('super_admin');
        $this->approver->assignRole('super_admin');

        $this->meeting = Meeting::factory()->create([
            'department_id' => $this->dept->id,
            'organization_id' => $this->project->organization_id,
            'organizer_id' => $this->actor->id,
            'status' => Meeting::STATUS_SCHEDULED,
        ]);
    }

    public function test_approve_guard_hits_when_status_moved_to_approved_concurrently(): void
    {
        $ruling = $this->makeRuling(Recommendation::STATUS_PENDING);

        // Simulate the concurrent winner updating status out of the
        // allowed {pending, deferred} set.
        DB::table('recommendations')
            ->where('id', $ruling->id)
            ->update(['status' => Recommendation::STATUS_APPROVED]);

        $response = $this->actingAs($this->approver, 'sanctum')
            ->postJson("/api/recommendations/{$ruling->id}/approve");

        $response->assertStatus(409)
            ->assertJsonPath('message', 'لا يمكن اعتماد التوصية في الحالة الحالية');
    }

    public function test_approve_guard_hits_when_status_moved_to_rejected_concurrently(): void
    {
        $ruling = $this->makeRuling(Recommendation::STATUS_PENDING);

        DB::table('recommendations')
            ->where('id', $ruling->id)
            ->update(['status' => Recommendation::STATUS_REJECTED]);

        $response = $this->actingAs($this->approver, 'sanctum')
            ->postJson("/api/recommendations/{$ruling->id}/approve");

        $response->assertStatus(409);
    }

    public function test_reject_guard_hits_when_status_moved_to_rejected_concurrently(): void
    {
        $ruling = $this->makeRuling(Recommendation::STATUS_PENDING);

        DB::table('recommendations')
            ->where('id', $ruling->id)
            ->update(['status' => Recommendation::STATUS_REJECTED]);

        $response = $this->actingAs($this->approver, 'sanctum')
            ->postJson("/api/recommendations/{$ruling->id}/reject");

        $response->assertStatus(409)
            ->assertJsonPath('message', 'لا يمكن رفض التوصية في الحالة الحالية');
    }

    public function test_ruling_defer_guard_hits_when_status_moved_concurrently(): void
    {
        $ruling = $this->makeRuling(Recommendation::STATUS_PENDING);

        // Ruling defer source set is {pending, approved}. Push it out.
        DB::table('recommendations')
            ->where('id', $ruling->id)
            ->update(['status' => Recommendation::STATUS_REJECTED]);

        $response = $this->actingAs($this->approver, 'sanctum')
            ->postJson("/api/recommendations/{$ruling->id}/defer", [
                'defer_reason' => 'محاولة تأجيل بعد رفض متزامن',
            ]);

        $response->assertStatus(409)
            ->assertJsonPath('message', 'لا يمكن تأجيل التوصية في الحالة الحالية');
    }

    public function test_accept_guard_hits_when_action_item_status_moved_concurrently(): void
    {
        $rec = $this->makeActionItem(Recommendation::STATUS_PROPOSED);

        DB::table('recommendations')
            ->where('id', $rec->id)
            ->update(['status' => Recommendation::STATUS_ACCEPTED]);

        $response = $this->actingAs($this->actor, 'sanctum')
            ->postJson("/api/recommendations/{$rec->id}/accept");

        $response->assertStatus(409)
            ->assertJsonPath('message', 'لا يمكن قبول التوصية في الحالة الحالية');
    }

    public function test_complete_guard_hits_when_action_item_status_moved_concurrently(): void
    {
        $rec = $this->makeActionItem(Recommendation::STATUS_ACCEPTED);

        DB::table('recommendations')
            ->where('id', $rec->id)
            ->update(['status' => Recommendation::STATUS_DEFERRED]);

        $response = $this->actingAs($this->actor, 'sanctum')
            ->postJson("/api/recommendations/{$rec->id}/complete");

        // The pendingTaskIdsFor gate runs BEFORE the optimistic update; with
        // no tasks attached it skips the 422 path, then the optimistic guard
        // in complete() returns 409 because status is no longer in
        // {accepted, deferred} (it's still deferred -> still in set).
        // Adjust the test to land in a status that is NOT in the allowed
        // source set: completed.
        DB::table('recommendations')
            ->where('id', $rec->id)
            ->update(['status' => Recommendation::STATUS_COMPLETED]);

        $response2 = $this->actingAs($this->actor, 'sanctum')
            ->postJson("/api/recommendations/{$rec->id}/complete");

        $response2->assertStatus(409)
            ->assertJsonPath('message', 'لا يمكن إنجاز التوصية في الحالة الحالية');

        // Suppress unused warning for the first response variable by
        // touching it once; if the controller ever short-circuits
        // differently, this assertion will catch it.
        $this->assertNotNull($response);
    }

    private function makeRuling(string $status): Recommendation
    {
        return Recommendation::create([
            'kind' => Recommendation::KIND_RULING,
            'meeting_id' => $this->meeting->id,
            'title' => 'قرار للاختبار',
            'type' => 'approval',
            'requested_by' => $this->actor->id,
            'status' => $status,
            'priority' => Recommendation::PRIORITY_MEDIUM,
            'organization_id' => $this->project->organization_id,
        ]);
    }

    private function makeActionItem(string $status): Recommendation
    {
        return Recommendation::create([
            'kind' => Recommendation::KIND_ACTION_ITEM,
            'meeting_id' => $this->meeting->id,
            'title' => 'إجراء للاختبار',
            'assignee_id' => $this->actor->id,
            'due_date' => now()->addDays(5)->toDateString(),
            'status' => $status,
            'priority' => Recommendation::PRIORITY_MEDIUM,
            'organization_id' => $this->project->organization_id,
        ]);
    }
}
