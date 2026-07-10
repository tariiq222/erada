<?php

namespace App\Modules\Meetings\Scopes;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * UserMeetingScope - Unified org-isolation filter for the Meetings module's
 * list queries (meetings, agenda items, attendees, categories, settings).
 *
 * Single source of truth for the horizontal org floor across all five
 * query variants; never re-implemented inline in any Controller.
 *
 * Per-variant behavior (preserved from Phase 5.A):
 *   - super_admin: no filter (sees everything).
 *   - actor without organization_id: whereRaw('1 = 0') — fail-closed.
 *   - normal actor: organization_id filter applied directly on the column,
 *     or via the meeting parent for agenda items / attendees (pivot).
 *
 * Does NOT depend on the department hierarchy — the AccessDecision engine
 * resolves department subtree via scope-chain. This Scope owns ONLY the
 * horizontal same-org floor.
 *
 * Phase CFA-06 — Meetings cluster_tree read widening (READ ONLY):
 *   - When the actor holds BOTH Capability::MEETINGS_VIEW and
 *     Capability::CLUSTER_TREE_VIEW on actor.organization_id, the strict
 *     same-org floor widens to include descendant organizations via
 *     Organization::descendantIds() (BFS via parent_id, depth cap 32,
 *     fail-closed on cycle).
 *   - Cluster widening is ADDITIVE: when both grants are held, the
 *     org-id list replaces [actor.org] with [actor.org] + descendants.
 *     Same-org strictness remains intact when either grant is missing.
 *   - Per CFA-00 owner decision (2026-07-09): NO meeting start/complete/
 *     cancel widening (operational, not governance). Writes (CRUD on
 *     Meeting and all state transitions) STAY strict same-org.
 *   - The cluster widening never widens to the meeting_resolutions
 *     lifecycle (Direction R integrity preserved) or to the
 *     recommendation approve/reject/defer/accept/complete transitions
 *     (Direction B integrity preserved).
 */
class UserMeetingScope
{
    /**
     * Phase CFA-06 — Org floor for the cluster_tree widening (read-only).
     *
     * Returns the list of organization ids the actor may see under the
     * cluster_tree policy for Meetings reads.
     *
     *   - Default: [actor.organization_id] only (strict same-org) when
     *     EITHER MEETINGS_VIEW or CLUSTER_TREE_VIEW is missing on actor.org.
     *     Preserves the pre-CFA-06 same-org behavior for users who do
     *     not hold both grants — the strict-equality gate remains in force.
     *
     *   - Widening (read-only): when the actor holds BOTH
     *     Capability::MEETINGS_VIEW + Capability::CLUSTER_TREE_VIEW on
     *     actor.organization_id, descendant organizations (via parent_id
     *     BFS) are added to the list. CFA-06 is read-only — no widening
     *     to write paths (CRUD stays strict same-org per CFA-00 owner
     *     decision).
     *
     * Returns an empty array for null-org actors — the engine then fails
     * closed at the strict-equality gate. super_admin is short-circuited
     * earlier in applyToMeetings() and never reaches this helper.
     *
     * @return list<int>
     */
    public function clusterVisibleOrgIds(User $user): array
    {
        if ($user->organization_id === null) {
            return [];
        }

        $orgId = (int) $user->organization_id;
        $visible = [$orgId];

        // Both grants required to widen cluster_tree. Missing either ⇒ strict same-org.
        $hasMeetingsView = AccessDecision::can($user, Capability::MEETINGS_VIEW);
        $hasClusterTreeView = AccessDecision::can($user, Capability::CLUSTER_TREE_VIEW);
        if (! $hasMeetingsView || ! $hasClusterTreeView) {
            return $visible;
        }

        $org = Organization::query()->find($orgId);
        if (! $org instanceof Organization) {
            return $visible;
        }

        return array_values(array_unique(array_merge($visible, $org->descendantIds())));
    }

    /**
     * Filter for Meeting queries.
     *
     * Phase CFA-06 — Cluster widening applies here too: when the actor holds
     * both MEETINGS_VIEW + CLUSTER_TREE_VIEW, the org floor widens to
     * [actor.org] + descendants via clusterVisibleOrgIds(). When either
     * grant is missing, the strict same-org filter is preserved exactly
     * (preserves pre-CFA-06 behavior for users without cluster_tree).
     */
    public function applyToMeetings(Builder $query, User $actor): Builder
    {
        if ($actor->isSuperAdmin()) {
            return $query;
        }

        if ($actor->organization_id === null) {
            return $query->whereRaw('1 = 0');
        }

        // Phase CFA-06 — Cluster widening at the list floor.
        // Default is same-org only. When MEETINGS_VIEW + CLUSTER_TREE_VIEW
        // are both held, descendant organizations are added (read-only).
        $visibleOrgIds = $this->clusterVisibleOrgIds($actor);
        if ($visibleOrgIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('meetings.organization_id', $visibleOrgIds);
    }

    /**
     * فلتر استعلام MeetingAgendaItem عبر meeting الأب.
     * العمود organization_id موجود في meeting_agenda_items مباشرة،
     * لكن نمرّ عبر العلاقة لأبقى متّسقاً مع المرجع الأعلى (meeting).
     */
    public function applyToAgendaItems(Builder $query, User $actor): Builder
    {
        return $query->whereHas(
            'meeting',
            fn (Builder $m) => $this->applyToMeetings($m, $actor)
        );
    }

    /**
     * فلتر استعلام MeetingAttendee (pivot) عبر meeting الأب.
     * pivot لا يحمل organization_id مباشرة، فالاشتقاق يتمّ عبر العلاقة.
     */
    public function applyToAttendees(Builder $query, User $actor): Builder
    {
        return $query->whereHas(
            'meeting',
            fn (Builder $m) => $this->applyToMeetings($m, $actor)
        );
    }

    /**
     * فلتر استعلام MeetingCategory.
     */
    public function applyToCategories(Builder $query, User $actor): Builder
    {
        if ($actor->isSuperAdmin()) {
            return $query;
        }

        if ($actor->organization_id === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('meeting_categories.organization_id', $actor->organization_id);
    }

    /**
     * فلتر استعلام MeetingSettings (صف واحد لكل org عادةً).
     */
    public function applyToSettings(Builder $query, User $actor): Builder
    {
        if ($actor->isSuperAdmin()) {
            return $query;
        }

        if ($actor->organization_id === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('meeting_settings.organization_id', $actor->organization_id);
    }
}
