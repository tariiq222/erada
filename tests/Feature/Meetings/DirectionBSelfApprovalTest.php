<?php

namespace Tests\Feature\Meetings;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Models\Recommendation;
use App\Modules\Meetings\Policies\RecommendationPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * DirectionBSelfApprovalTest - Phase CFA-06 REGRESSION.
 *
 * Pins the Direction B self-approval block AFTER Phase CFA-06 cluster_tree
 * read widening:
 *
 *   1) A user who recorded a ruling-kind recommendation (requested_by)
 *      CANNOT also be the one who decides it — neither approve, reject,
 *      nor defer. The four-eyes principle is preserved across CFA-06.
 *
 *   2) The self-approval block is NOT weakened by cluster_tree widening:
 *      a cluster user with RECOMMENDATIONS_VIEW + CLUSTER_TREE_VIEW can
 *      READ a cross-org ruling (view() returns true) but CANNOT approve
 *      / reject / defer it (precheck() denies the cross-org org mismatch).
 *
 *   3) Action-item kind: self-approval block does NOT apply (the requester
 *      is the same person who drives the action through accept/reject/
 *      defer/complete by design — these are not four-eyes decisions).
 *
 *   4) Direction B transitions stay org-strict: approve / reject / defer /
 *      accept / complete never widen through cluster_tree. Only the READ
 *      path widens.
 *
 * This test exists so any future change that tries to weaken the
 * self-approval block, or accidentally widens the Direction B ruling
 * lifecycle, will fail here.
 */
class DirectionBSelfApprovalTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    public function test_ruling_lifecycle_statuses_remain_unchanged_after_cfa06(): void
    {
        // Direction B ruling lifecycle constants are unchanged.
        // statusValues() union is: proposed, accepted, pending, approved,
        // rejected, deferred, completed.
        $this->assertSame(
            ['proposed', 'accepted', 'pending', 'approved', 'rejected', 'deferred', 'completed'],
            Recommendation::statusValues()
        );

        // CFA-06 does not introduce any new transition on the ruling side.
        // Pin the existing transition matrix here.
        $rulingPending = new Recommendation([
            'kind' => Recommendation::KIND_RULING,
            'status' => Recommendation::STATUS_PENDING,
        ]);
        $this->assertTrue($rulingPending->canTransitionTo(Recommendation::STATUS_APPROVED));
        $this->assertTrue($rulingPending->canTransitionTo(Recommendation::STATUS_REJECTED));
        $this->assertTrue($rulingPending->canTransitionTo(Recommendation::STATUS_DEFERRED));

        // No new transitions: from pending, the legacy matrix remains closed.
        $this->assertFalse($rulingPending->canTransitionTo(Recommendation::STATUS_ACCEPTED));
        $this->assertFalse($rulingPending->canTransitionTo(Recommendation::STATUS_COMPLETED));
        $this->assertFalse($rulingPending->canTransitionTo(Recommendation::STATUS_PROPOSED));
    }

    public function test_self_approval_block_survives_cluster_tree_widening_on_view(): void
    {
        // Build a cluster: cluster -> hospital.
        $cluster = Organization::factory()->cluster()->create();
        $hospital = Organization::factory()->hospital()->childOf($cluster)->create();

        // The ruling is recorded in the CHILD hospital.
        $hospitalOrganizer = User::factory()->create(['organization_id' => $hospital->id]);
        $meeting = Meeting::factory()->create([
            'organization_id' => $hospital->id,
            'organizer_id' => $hospitalOrganizer->id,
            'status' => Meeting::STATUS_IN_PROGRESS,
        ]);

        $requester = User::factory()->create([
            'organization_id' => $hospital->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($requester, [
            Capability::RECOMMENDATIONS_APPROVE,
            Capability::RECOMMENDATIONS_REJECT,
            Capability::RECOMMENDATIONS_DEFER,
            Capability::RECOMMENDATIONS_ACCEPT,
            Capability::RECOMMENDATIONS_COMPLETE,
            Capability::RECOMMENDATIONS_EDIT,
            Capability::RECOMMENDATIONS_VIEW,
        ], 'organization', $hospital->id);

        $ruling = Recommendation::create([
            'kind' => Recommendation::KIND_RULING,
            'meeting_id' => $meeting->id,
            'title' => 'قرار للاختبار',
            'type' => 'approval',
            'requested_by' => $requester->id,
            'status' => Recommendation::STATUS_PENDING,
            'priority' => Recommendation::PRIORITY_MEDIUM,
            'organization_id' => $hospital->id,
        ]);

        // A cluster user with both RECOMMENDATIONS_VIEW + CLUSTER_TREE_VIEW
        // can READ the ruling (CFA-06 widening on view).
        $clusterUser = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($clusterUser, [
            Capability::RECOMMENDATIONS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $policy = new RecommendationPolicy;

        // Cluster user: view widens.
        $this->assertTrue($policy->view($clusterUser, $ruling));

        // BUT the cluster user's approval / reject / defer stay org-strict
        // (precheck denies because cluster user's org != ruling's org).
        // Direction B integrity preserved — the cluster_tree widening does
        // not leak write access.
        $this->assertFalse($policy->approve($clusterUser, $ruling));
        $this->assertFalse($policy->reject($clusterUser, $ruling));
        $this->assertFalse($policy->defer($clusterUser, $ruling));
        // update/create/delete also stay org-strict.
        $this->assertFalse($policy->update($clusterUser, $ruling));

        // The same user, on a same-org ruling, can do everything within
        // the ruling lifecycle except self-approval.
        $sameOrgRuling = Recommendation::create([
            'kind' => Recommendation::KIND_RULING,
            'meeting_id' => $meeting->id,
            'title' => 'قرار آخر في نفس المنظمة',
            'type' => 'approval',
            'requested_by' => $hospitalOrganizer->id, // someone ELSE, so the cluster user can decide
            'status' => Recommendation::STATUS_PENDING,
            'priority' => Recommendation::PRIORITY_MEDIUM,
            'organization_id' => $hospital->id,
        ]);

        // Cluster user doing this same-org is fine because the cluster widens
        // the org floor. The cluster user's actor.org (cluster) is the
        // ancestor of hospital, and engine's rescue admits. We DO NOT grant
        // approve on this user — precheck still denies for the cluster org
        // because precheck calls MeetingOrgGuard::sameOrganizationForRecommendation
        // which is strict same-org. This matches the documented CFA-00
        // owner decision: writes stay strict same-org.
        $this->assertFalse($policy->approve($clusterUser, $sameOrgRuling));
    }

    public function test_ruling_kind_self_approval_block_preserved_through_cfa06(): void
    {
        // Sanity pin: even when the requester holds every ruling lifecycle
        // capability, the self-approval block denies the transition. This
        // is the documented four-eyes guard from RecommendationPolicy::isSelfApproval.
        $org = Organization::factory()->create();
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [
            Capability::RECOMMENDATIONS_APPROVE,
            Capability::RECOMMENDATIONS_REJECT,
            Capability::RECOMMENDATIONS_DEFER,
        ], 'organization', $org->id);

        $meeting = Meeting::factory()->create([
            'organization_id' => $org->id,
            'organizer_id' => $user->id,
        ]);

        $ruling = Recommendation::create([
            'kind' => Recommendation::KIND_RULING,
            'meeting_id' => $meeting->id,
            'title' => 'قرار للاختبار',
            'type' => 'approval',
            'requested_by' => $user->id,
            'status' => Recommendation::STATUS_PENDING,
            'priority' => Recommendation::PRIORITY_MEDIUM,
            'organization_id' => $meeting->organization_id,
        ]);

        $policy = new RecommendationPolicy;
        // Self-approval block denies regardless of capability grants.
        $this->assertFalse($policy->approve($user, $ruling));
        $this->assertFalse($policy->reject($user, $ruling));
        $this->assertFalse($policy->defer($user, $ruling));
    }

    public function test_action_item_kind_has_no_self_approval_block(): void
    {
        // Asymmetry: action_item accept/reject/defer driven by the
        // requester is by design, no self-approval guard.
        $org = Organization::factory()->create();
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [
            Capability::RECOMMENDATIONS_ACCEPT,
            Capability::RECOMMENDATIONS_REJECT,
            Capability::RECOMMENDATIONS_DEFER,
        ], 'organization', $org->id);

        $meeting = Meeting::factory()->create([
            'organization_id' => $org->id,
            'organizer_id' => $user->id,
        ]);

        $actionItem = Recommendation::create([
            'kind' => Recommendation::KIND_ACTION_ITEM,
            'meeting_id' => $meeting->id,
            'title' => 'إجراء للاختبار',
            'requested_by' => $user->id,
            'assignee_id' => $user->id,
            'status' => Recommendation::STATUS_PROPOSED,
            'priority' => Recommendation::PRIORITY_MEDIUM,
            'organization_id' => $meeting->organization_id,
        ]);

        $policy = new RecommendationPolicy;

        // The action_item policy actions route through update() (the
        // accept/reject/defer/complete surface) — they should not trigger
        // the self-approval block.
        $this->assertTrue($policy->approve($user, $actionItem)); // routes to RECOMMENDATIONS_ACCEPT
        $this->assertTrue($policy->reject($user, $actionItem)); // shared reject
        $this->assertTrue($policy->defer($user, $actionItem));  // shared defer
    }
}
