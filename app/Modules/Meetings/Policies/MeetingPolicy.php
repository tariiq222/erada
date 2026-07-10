<?php

namespace App\Modules\Meetings\Policies;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\User;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Support\MeetingOrgGuard;

/**
 * MeetingPolicy - Phase 5.B: per-record org-isolation for Meeting.
 *
 * نمط موحّد مع KpiPolicy / EmployeeProfilePolicy:
 *   - super_admin ⇒ true دائماً (via before()).
 *   - actor بلا organization_id ⇒ deny (fail-closed).
 *   - meeting من منظمة أخرى ⇒ deny.
 *   - meeting بلا organization_id ⇒ deny (orphan).
 *
 * لا تعتمد على Spatie direct. الـ Capability constants تمر عبر AccessDecision
 * ليتحقّق المحرك من الأدوار السياقية.
 */
class MeetingPolicy
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

        return AccessDecision::can($user, Capability::MEETINGS_VIEW);
    }

    /**
     * Phase CFA-06 — Cluster tree widening applies to view() only.
     *
     * Decision paths:
     *  1) MEETINGS_VIEW on meeting (same org): engine's same-org + role check.
     *  2) CLUSTER_TREE_VIEW on meeting (cluster ancestor): engine's rescue
     *     branch verifies the ancestor walk + non-sensitive + scoped-role
     *     grant. Only fires if the actor holds Capability::MEETINGS_VIEW +
     *     Capability::CLUSTER_TREE_VIEW on actor.organization_id — two
     *     explicit checks before the rescue.
     *
     * Missing either capability ⇒ deny. Writes (update / delete / start /
     * complete / cancel) are unaffected by cluster_tree and go through
     * precheck() / authorize().
     */
    public function view(User $user, Meeting $meeting): bool
    {
        // super_admin is handled in the engine (short-circuit in whyCan::step 1).
        // null-org actor is handled in the engine (org_isolation_denied in step 2).

        // Path 1: same-org MEETINGS_VIEW via engine.
        if (AccessDecision::can($user, Capability::MEETINGS_VIEW, $meeting)) {
            return true;
        }

        // Path 2: cross-org cluster_tree widening — requires BOTH entitlements on actor.org.
        if (! AccessDecision::can($user, Capability::MEETINGS_VIEW)) {
            return false;
        }
        if (! AccessDecision::can($user, Capability::CLUSTER_TREE_VIEW)) {
            return false;
        }

        return AccessDecision::can($user, Capability::CLUSTER_TREE_VIEW, $meeting);
    }

    public function create(User $user): bool
    {
        if (! $user->isSuperAdmin() && $user->organization_id === null) {
            return false;
        }

        return AccessDecision::can($user, Capability::MEETINGS_CREATE);
    }

    public function update(User $user, Meeting $meeting): bool
    {
        if (! $this->precheck($user, $meeting)) {
            return false;
        }

        return AccessDecision::can($user, Capability::MEETINGS_EDIT, $meeting);
    }

    public function delete(User $user, Meeting $meeting): bool
    {
        if (! $this->precheck($user, $meeting)) {
            return false;
        }

        return AccessDecision::can($user, Capability::MEETINGS_DELETE, $meeting);
    }

    /**
     * precheck: actor/org gate + same-org عبر MeetingOrgGuard.
     */
    protected function precheck(User $user, Meeting $meeting): bool
    {
        if (! $user->isSuperAdmin() && $user->organization_id === null) {
            return false;
        }

        return app(MeetingOrgGuard::class)->sameOrganizationForMeeting($user, $meeting);
    }
}
