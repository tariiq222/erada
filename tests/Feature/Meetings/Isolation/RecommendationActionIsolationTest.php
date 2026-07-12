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
 * RecommendationActionIsolationTest - Phase 5.C: org-A user cannot perform
 * lifecycle transitions on org-B recommendations.
 *
 * POST /api/recommendations/{rec}/approve|reject|defer|complete|accept all
 * delegate authorize() to RecommendationPolicy::approve/reject/defer or
 * `update` (for complete/accept). Phase 5.B's precheck() floors cross-org
 * actions; the new policy code is what we're pinning at the HTTP boundary.
 *
 * Self-approval block preservation is also pinned here — the requester
 * cannot approve/reject/defer their own ruling.
 */
class RecommendationActionIsolationTest extends TestCase
{
    use GrantsEngineCapability;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function makeRuling(Organization $org, ?User $requester = null, ?Department $dept = null): Recommendation
    {
        $dept ??= Department::factory()->create(['organization_id' => $org->id]);
        $organizer = User::factory()->create(['organization_id' => $org->id]);
        $meeting = Meeting::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'organizer_id' => $organizer->id,
        ]);

        return Recommendation::factory()->ruling()->create([
            'organization_id' => $org->id,
            'meeting_id' => $meeting->id,
            'requested_by' => $requester?->id,
        ]);
    }

    private function makeActionItem(Organization $org, ?User $assignee = null, ?Department $dept = null): Recommendation
    {
        $dept ??= Department::factory()->create(['organization_id' => $org->id]);
        $organizer = User::factory()->create(['organization_id' => $org->id]);
        $meeting = Meeting::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'organizer_id' => $organizer->id,
        ]);

        return Recommendation::factory()->create([
            'organization_id' => $org->id,
            'meeting_id' => $meeting->id,
            'kind' => Recommendation::KIND_ACTION_ITEM,
            'status' => Recommendation::STATUS_PROPOSED,
            'assignee_id' => $assignee?->id ?? User::factory()->create(['organization_id' => $org->id])->id,
        ]);
    }

    // ========== Cross-org deny on every transition ==========

    public function test_org_a_cannot_approve_org_b_ruling(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $rulingB = $this->makeRuling($orgB);

        $actor = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);
        $this->grantEngineCapability($actor, Capability::RECOMMENDATIONS_APPROVE);

        $response = $this->actingAs($actor, 'sanctum')
            ->postJson("/api/recommendations/{$rulingB->id}/approve", [
                'rationale' => 'cross-org approve attempt',
            ]);

        $response->assertStatus(403);
        $this->assertSame(Recommendation::STATUS_PENDING, $rulingB->fresh()->status);
    }

    public function test_org_a_cannot_reject_org_b_ruling(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $rulingB = $this->makeRuling($orgB);

        $actor = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);
        $this->grantEngineCapability($actor, Capability::RECOMMENDATIONS_REJECT);

        $response = $this->actingAs($actor, 'sanctum')
            ->postJson("/api/recommendations/{$rulingB->id}/reject", [
                'rationale' => 'cross-org reject attempt',
            ]);

        $response->assertStatus(403);
        $this->assertSame(Recommendation::STATUS_PENDING, $rulingB->fresh()->status);
    }

    public function test_org_a_cannot_defer_org_b_ruling(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $rulingB = $this->makeRuling($orgB);

        $actor = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);
        $this->grantEngineCapability($actor, Capability::RECOMMENDATIONS_DEFER);

        $response = $this->actingAs($actor, 'sanctum')
            ->postJson("/api/recommendations/{$rulingB->id}/defer", [
                'defer_reason' => 'cross-org defer attempt',
                'deferred_until' => now()->addWeek()->toDateString(),
            ]);

        $response->assertStatus(403);
        $this->assertSame(Recommendation::STATUS_PENDING, $rulingB->fresh()->status);
    }

    public function test_org_a_cannot_complete_org_b_action_item(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $itemB = $this->makeActionItem($orgB);
        // Move the action item to ACCEPTED first so the complete gate accepts it.
        $itemB->update(['status' => Recommendation::STATUS_ACCEPTED]);

        $actor = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);
        $this->grantEngineCapability($actor, Capability::RECOMMENDATIONS_COMPLETE);

        $response = $this->actingAs($actor, 'sanctum')
            ->postJson("/api/recommendations/{$itemB->id}/complete");

        $response->assertStatus(403);
        $this->assertSame(Recommendation::STATUS_ACCEPTED, $itemB->fresh()->status);
    }

    public function test_org_a_cannot_accept_org_b_action_item(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $itemB = $this->makeActionItem($orgB);

        $actor = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);
        $this->grantEngineCapability($actor, Capability::RECOMMENDATIONS_ACCEPT);

        $response = $this->actingAs($actor, 'sanctum')
            ->postJson("/api/recommendations/{$itemB->id}/accept");

        $response->assertStatus(403);
        $this->assertSame(Recommendation::STATUS_PROPOSED, $itemB->fresh()->status);
    }

    // ========== super_admin bypasses org floor ==========

    public function test_super_admin_can_approve_any_ruling(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $rulingB = $this->makeRuling($orgB);

        $superAdmin = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);
        $this->grantCanonicalSuperAdmin($superAdmin);

        $response = $this->actingAs($superAdmin, 'sanctum')
            ->postJson("/api/recommendations/{$rulingB->id}/approve", [
                'rationale' => 'super_admin approves',
            ]);

        $response->assertStatus(200);
        $this->assertSame(Recommendation::STATUS_APPROVED, $rulingB->fresh()->status);
    }

    public function test_super_admin_can_reject_any_ruling(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $rulingB = $this->makeRuling($orgB);

        $superAdmin = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);
        $this->grantCanonicalSuperAdmin($superAdmin);

        $response = $this->actingAs($superAdmin, 'sanctum')
            ->postJson("/api/recommendations/{$rulingB->id}/reject", [
                'rationale' => 'super_admin rejects',
            ]);

        $response->assertStatus(200);
        $this->assertSame(Recommendation::STATUS_REJECTED, $rulingB->fresh()->status);
    }

    public function test_super_admin_can_defer_any_ruling(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $rulingB = $this->makeRuling($orgB);

        $superAdmin = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);
        $this->grantCanonicalSuperAdmin($superAdmin);

        $response = $this->actingAs($superAdmin, 'sanctum')
            ->postJson("/api/recommendations/{$rulingB->id}/defer", [
                'defer_reason' => 'super_admin defers',
                'deferred_until' => now()->addWeek()->toDateString(),
            ]);

        $response->assertStatus(200);
        $this->assertSame(Recommendation::STATUS_DEFERRED, $rulingB->fresh()->status);
    }

    // ========== Self-approval block preserved ==========

    public function test_self_approval_block_preserved_for_requester(): void
    {
        $orgA = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);

        $actor = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);
        $this->grantEngineCapability($actor, Capability::RECOMMENDATIONS_APPROVE);

        // Ruling raised by the actor himself.
        $selfRuling = $this->makeRuling($orgA, $actor, $deptA);

        $response = $this->actingAs($actor, 'sanctum')
            ->postJson("/api/recommendations/{$selfRuling->id}/approve", [
                'rationale' => 'self-approve attempt',
            ]);

        // The self-approval block returns 403 (the policy returns false on
        // isSelfApproval). Status must not have changed.
        $response->assertStatus(403);
        $this->assertSame(Recommendation::STATUS_PENDING, $selfRuling->fresh()->status);
    }

    public function test_self_rejection_block_preserved_for_requester(): void
    {
        $orgA = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);

        $actor = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);
        $this->grantEngineCapability($actor, Capability::RECOMMENDATIONS_REJECT);

        $selfRuling = $this->makeRuling($orgA, $actor, $deptA);

        $response = $this->actingAs($actor, 'sanctum')
            ->postJson("/api/recommendations/{$selfRuling->id}/reject", [
                'rationale' => 'self-reject attempt',
            ]);

        $response->assertStatus(403);
        $this->assertSame(Recommendation::STATUS_PENDING, $selfRuling->fresh()->status);
    }
}
