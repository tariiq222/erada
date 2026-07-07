<?php

namespace Tests\Feature\Meetings;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Models\Recommendation;
use App\Modules\Projects\Models\Project;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * RecommendationSelfApprovalTest
 *
 * Ppins the self-approval guard that RecommendationPolicy applies to
 * RULING-kind recommendations. The user who recorded (requested_by) a
 * ruling cannot also be the one who decides it (approve / reject / defer)
 * — even when they hold super_admin. The guard exists because the four-
 * eyes principle is the only thing standing between a requester and a
 * rubber-stamp approval on their own decision.
 *
 * Action-item kind has no self-approval semantic: the requester is by
 * design the same person who drives the action through accept/complete —
 * the guard does NOT apply there. This test pins that asymmetry.
 */
class RecommendationSelfApprovalTest extends TestCase
{
    use GrantsEngineCapability;
    use RefreshDatabase;

    private User $requester;

    private Department $dept;

    private Project $project;

    private Meeting $meeting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->dept = Department::factory()->create();
        $this->project = Project::factory()->create(['department_id' => $this->dept->id]);
        $this->requester = User::factory()->create([
            'department_id' => $this->dept->id,
            'organization_id' => $this->project->organization_id,
            'is_active' => true,
        ]);

        // Grant the requester BOTH the ruling-side (approve/reject/defer)
        // and the action_item-side (accept/complete) capabilities via the
        // engine. NO super_admin role — Gate::before() would otherwise
        // bypass the policy entirely and the self-approval block would be
        // untestable.
        $this->grantEngineCapability($this->requester, [
            Capability::RECOMMENDATIONS_APPROVE,
            Capability::RECOMMENDATIONS_REJECT,
            Capability::RECOMMENDATIONS_DEFER,
            Capability::RECOMMENDATIONS_ACCEPT,
            Capability::RECOMMENDATIONS_COMPLETE,
            Capability::RECOMMENDATIONS_EDIT,
        ], 'organization', $this->project->organization_id);

        $this->meeting = Meeting::factory()->create([
            'department_id' => $this->dept->id,
            'organization_id' => $this->project->organization_id,
            'organizer_id' => $this->requester->id,
            'status' => Meeting::STATUS_SCHEDULED,
        ]);
    }

    public function test_requester_cannot_self_approve_ruling(): void
    {
        $ruling = $this->createRuling(requestedBy: $this->requester->id);

        $response = $this->actingAs($this->requester, 'sanctum')
            ->postJson("/api/recommendations/{$ruling->id}/approve");

        $response->assertStatus(403);
        $this->assertSame(Recommendation::STATUS_PENDING, $ruling->fresh()->status);
    }

    public function test_requester_cannot_self_reject_ruling(): void
    {
        $ruling = $this->createRuling(requestedBy: $this->requester->id);

        $response = $this->actingAs($this->requester, 'sanctum')
            ->postJson("/api/recommendations/{$ruling->id}/reject", [
                'rationale' => 'محاولة رفض ذاتي',
            ]);

        $response->assertStatus(403);
        $this->assertSame(Recommendation::STATUS_PENDING, $ruling->fresh()->status);
    }

    public function test_requester_cannot_self_defer_ruling(): void
    {
        $ruling = $this->createRuling(requestedBy: $this->requester->id);

        $response = $this->actingAs($this->requester, 'sanctum')
            ->postJson("/api/recommendations/{$ruling->id}/defer", [
                'defer_reason' => 'محاولة تأجيل ذاتي',
            ]);

        $response->assertStatus(403);
        $this->assertSame(Recommendation::STATUS_PENDING, $ruling->fresh()->status);
    }

    public function test_different_user_can_approve_ruling(): void
    {
        // Sanity check: the policy must ALLOW a non-requester to approve.
        // This is the negative control for the self-approval block above.
        $other = User::factory()->create([
            'department_id' => $this->dept->id,
            'organization_id' => $this->project->organization_id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($other, Capability::RECOMMENDATIONS_APPROVE, 'organization', $this->project->organization_id);

        $ruling = $this->createRuling(requestedBy: $this->requester->id);

        $response = $this->actingAs($other, 'sanctum')
            ->postJson("/api/recommendations/{$ruling->id}/approve");

        $response->assertStatus(200);
        $this->assertSame(Recommendation::STATUS_APPROVED, $ruling->fresh()->status);
    }

    public function test_ruling_without_requester_can_be_approved_by_the_requester(): void
    {
        // Boundary case: a ruling with no requested_by (NULL) is not
        // attached to a requester, so the self-approval guard trivially
        // allows the transition. This protects against a NULL==NULL
        // regression.
        $ruling = $this->createRuling(requestedBy: null);

        $response = $this->actingAs($this->requester, 'sanctum')
            ->postJson("/api/recommendations/{$ruling->id}/approve");

        $response->assertStatus(200);
        $this->assertSame(Recommendation::STATUS_APPROVED, $ruling->fresh()->status);
    }

    public function test_action_item_kind_does_not_apply_self_approval_block_on_accept(): void
    {
        // Action_item accept() does not invoke the self-approval guard —
        // only the engine capability gate. The requester here is the same
        // as the acting user, which would normally block a ruling approval
        // but must NOT block a kind=action_item accept.
        $rec = Recommendation::create([
            'kind' => Recommendation::KIND_ACTION_ITEM,
            'meeting_id' => $this->meeting->id,
            'title' => 'إجراء ذاتي القبول',
            'requested_by' => $this->requester->id,
            'assignee_id' => $this->requester->id,
            'due_date' => now()->addDays(5)->toDateString(),
            'status' => Recommendation::STATUS_PROPOSED,
            'priority' => Recommendation::PRIORITY_MEDIUM,
            'organization_id' => $this->project->organization_id,
        ]);

        $response = $this->actingAs($this->requester, 'sanctum')
            ->postJson("/api/recommendations/{$rec->id}/accept");

        $response->assertStatus(200);
        $this->assertSame(Recommendation::STATUS_ACCEPTED, $rec->fresh()->status);
    }

    public function test_action_item_kind_does_not_apply_self_approval_block_on_reject(): void
    {
        // Action_item reject is shared with ruling at the controller, but
        // the policy only enables the self-approval block for kind=ruling.
        // Pin that here.
        $rec = Recommendation::create([
            'kind' => Recommendation::KIND_ACTION_ITEM,
            'meeting_id' => $this->meeting->id,
            'title' => 'إجراء ذاتي الرفض',
            'requested_by' => $this->requester->id,
            'assignee_id' => $this->requester->id,
            'due_date' => now()->addDays(5)->toDateString(),
            'status' => Recommendation::STATUS_PROPOSED,
            'priority' => Recommendation::PRIORITY_MEDIUM,
            'organization_id' => $this->project->organization_id,
        ]);

        $response = $this->actingAs($this->requester, 'sanctum')
            ->postJson("/api/recommendations/{$rec->id}/reject", [
                'rationale' => 'رفض إجراء ذاتي',
            ]);

        $response->assertStatus(200);
        $this->assertSame(Recommendation::STATUS_REJECTED, $rec->fresh()->status);
    }

    public function test_action_item_kind_does_not_apply_self_approval_block_on_defer(): void
    {
        $rec = Recommendation::create([
            'kind' => Recommendation::KIND_ACTION_ITEM,
            'meeting_id' => $this->meeting->id,
            'title' => 'إجراء ذاتي التأجيل',
            'requested_by' => $this->requester->id,
            'assignee_id' => $this->requester->id,
            'due_date' => now()->addDays(5)->toDateString(),
            'status' => Recommendation::STATUS_PROPOSED,
            'priority' => Recommendation::PRIORITY_MEDIUM,
            'organization_id' => $this->project->organization_id,
        ]);

        $response = $this->actingAs($this->requester, 'sanctum')
            ->postJson("/api/recommendations/{$rec->id}/defer", [
                'defer_reason' => 'تأجيل إجراء ذاتي',
            ]);

        $response->assertStatus(200);
        $this->assertSame(Recommendation::STATUS_DEFERRED, $rec->fresh()->status);
    }

    private function createRuling(?int $requestedBy = null): Recommendation
    {
        return Recommendation::create([
            'kind' => Recommendation::KIND_RULING,
            'meeting_id' => $this->meeting->id,
            'title' => 'قرار للاختبار',
            'type' => 'approval',
            'requested_by' => $requestedBy,
            'status' => Recommendation::STATUS_PENDING,
            'priority' => Recommendation::PRIORITY_MEDIUM,
            'organization_id' => $this->project->organization_id,
        ]);
    }
}
