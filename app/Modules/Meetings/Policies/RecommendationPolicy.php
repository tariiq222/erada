<?php

namespace App\Modules\Meetings\Policies;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\User;
use App\Modules\Meetings\Models\Recommendation;
use App\Modules\Meetings\Support\MeetingOrgGuard;

/**
 * RecommendationPolicy
 *
 * Direction B (Phase R1): rulings and action_items share this single
 * policy. The capability the engine consults depends on which transition
 * is being attempted:
 *
 *   approve(ruling)  ->  Capability::RECOMMENDATIONS_APPROVE
 *   reject(ruling)    ->  Capability::RECOMMENDATIONS_REJECT
 *   defer(ruling)     ->  Capability::RECOMMENDATIONS_DEFER
 *
 *   accept(action_item) -> Capability::RECOMMENDATIONS_ACCEPT (via update())
 *   reject(action_item) -> Capability::RECOMMENDATIONS_REJECT (shared with ruling)
 *   defer(action_item)  -> Capability::RECOMMENDATIONS_DEFER  (shared with ruling)
 *   complete(action_item) -> Capability::RECOMMENDATIONS_COMPLETE (via update())
 *
 * Self-approval block (ruling only): the user who recorded a recommendation
 * (requested_by) cannot also be the one who decides it (approve/reject/defer).
 * Mirrors the legacy DecisionController guard. Action items don't have the
 * same notion — the assignee is a worker, not the requester.
 *
 * Phase 5.B: per-record org-isolation عبر MeetingOrgGuard + null-org fail-closed.
 *
 * Phase CFA-06 — Cluster tree read widening:
 *   - view() allows AccessDecision::can(CLUSTER_TREE_VIEW, $rec) as a second
 *     path if and only if the actor holds Capability::RECOMMENDATIONS_VIEW +
 *     Capability::CLUSTER_TREE_VIEW on actor.organization_id. The engine's
 *     rescue branch verifies the ancestor walk + non-sensitive target.
 *   - approve / reject / defer / accept / complete stay strict same-org
 *     (Direction B integrity preserved per CFA-00 owner decision).
 *   - create / update / delete stay strict same-org.
 */
class RecommendationPolicy
{
    /**
     * Super Admin يتجاوز كل الصلاحيات.
     */
    public function before(User $user, string $ability): ?bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return null;
    }

    public function viewAny(User $user): bool
    {
        if (! $user->isSuperAdmin() && $user->organization_id === null) {
            return false;
        }

        return AccessDecision::can($user, Capability::RECOMMENDATIONS_VIEW);
    }

    /**
     * Phase CFA-06 — Cluster tree widening applies to view() only.
     *
     * Decision paths:
     *  1) RECOMMENDATIONS_VIEW on recommendation (same org): engine's
     *     same-org + role check via the meeting-parent scope chain.
     *  2) CLUSTER_TREE_VIEW on recommendation (cluster ancestor): engine's
     *     rescue branch verifies the ancestor walk + non-sensitive +
     *     scoped-role grant. Only fires if the actor holds
     *     Capability::RECOMMENDATIONS_VIEW + Capability::CLUSTER_TREE_VIEW
     *     on actor.organization_id — two explicit checks before the rescue.
     *
     * Missing either capability ⇒ deny. The Direction B ruling/action_item
     * transitions (approve / reject / defer / accept / complete) are
     * untouched: they keep precheck() and the self-approval block intact.
     */
    public function view(User $user, Recommendation $rec): bool
    {
        // super_admin is handled in the engine (short-circuit in whyCan::step 1).
        // null-org actor is handled in the engine (org_isolation_denied in step 2).

        // Path 1: same-org RECOMMENDATIONS_VIEW via engine.
        if (AccessDecision::can($user, Capability::RECOMMENDATIONS_VIEW, $rec)) {
            return true;
        }

        // Path 2: cross-org cluster_tree widening — requires BOTH entitlements on actor.org.
        if (! AccessDecision::can($user, Capability::RECOMMENDATIONS_VIEW)) {
            return false;
        }
        if (! AccessDecision::can($user, Capability::CLUSTER_TREE_VIEW)) {
            return false;
        }

        return AccessDecision::can($user, Capability::CLUSTER_TREE_VIEW, $rec);
    }

    public function create(User $user): bool
    {
        if (! $user->isSuperAdmin() && $user->organization_id === null) {
            return false;
        }

        return AccessDecision::can($user, Capability::RECOMMENDATIONS_CREATE);
    }

    public function update(User $user, Recommendation $rec): bool
    {
        if (! $this->precheck($user, $rec)) {
            return false;
        }

        return AccessDecision::can($user, Capability::RECOMMENDATIONS_EDIT, $rec);
    }

    public function delete(User $user, Recommendation $rec): bool
    {
        if (! $this->precheck($user, $rec)) {
            return false;
        }

        return AccessDecision::can($user, Capability::RECOMMENDATIONS_DELETE, $rec);
    }

    /**
     * Ruling-kind: pending/deferred -> approved.
     * Action_item kind has no "approve" — callers must use accept() instead.
     */
    public function approve(User $user, Recommendation $rec): bool
    {
        if (! $this->precheck($user, $rec)) {
            return false;
        }

        if ($rec->kind === Recommendation::KIND_RULING) {
            if (! AccessDecision::can($user, Capability::RECOMMENDATIONS_APPROVE, $rec)) {
                return false;
            }

            return ! $this->isSelfApproval($user, $rec);
        }

        // Action-item accept() routes through update() (RECOMMENDATIONS_EDIT)
        // and accepts proposed/deferred -> accepted.
        return AccessDecision::can($user, Capability::RECOMMENDATIONS_ACCEPT, $rec);
    }

    /**
     * Reject is shared by both kinds; capability is the same.
     */
    public function reject(User $user, Recommendation $rec): bool
    {
        if (! $this->precheck($user, $rec)) {
            return false;
        }

        if (! AccessDecision::can($user, Capability::RECOMMENDATIONS_REJECT, $rec)) {
            return false;
        }

        // Self-rejection block applies only to rulings (a requester cannot
        // also be the one who rejects their own requested decision).
        if ($rec->kind === Recommendation::KIND_RULING) {
            return ! $this->isSelfApproval($user, $rec);
        }

        return true;
    }

    /**
     * Defer is shared by both kinds; capability is the same.
     */
    public function defer(User $user, Recommendation $rec): bool
    {
        if (! $this->precheck($user, $rec)) {
            return false;
        }

        if (! AccessDecision::can($user, Capability::RECOMMENDATIONS_DEFER, $rec)) {
            return false;
        }

        if ($rec->kind === Recommendation::KIND_RULING) {
            return ! $this->isSelfApproval($user, $rec);
        }

        return true;
    }

    /**
     * precheck: actor/org gate + same-org عبر MeetingOrgGuard.
     */
    protected function precheck(User $user, Recommendation $rec): bool
    {
        if (! $user->isSuperAdmin() && $user->organization_id === null) {
            return false;
        }

        return app(MeetingOrgGuard::class)->sameOrganizationForRecommendation($user, $rec);
    }

    /**
     * Self-approval block helper. A ruling raised by the current user
     * cannot also be approved/rejected/deferred by the same user — they
     * must hand it off to another approver.
     */
    private function isSelfApproval(User $user, Recommendation $rec): bool
    {
        return $rec->requested_by !== null
            && (int) $rec->requested_by === (int) $user->id;
    }
}
