<?php

namespace App\Modules\Strategy\Policies;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\User;
use App\Modules\Strategy\Models\Blocker;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * BlockerPolicy — Strategic Blocker authorization policy.
 *
 * Engine-only: relies entirely on AccessDecision::can(). Blockers carry
 * organization_id directly (copied from the polymorphic blockable at create
 * time) and act as ScopeAware, so AccessDecision can derive org from the
 * target.
 *
 * Phase 9-D-D1b — Cluster tree read widening:
 *   - view() allows AccessDecision::can(CLUSTER_TREE_VIEW, $blocker) as a
 *     second path if and only if the actor holds Capability::STRATEGY_VIEW
 *     + CLUSTER_TREE_VIEW on actor.organization_id.
 *
 * Phase CFA-03 — Cluster tree governance-action widening:
 *   - resolve / escalate allow AccessDecision::can(CLUSTER_TREE_MANAGE,
 *     $blocker) as a second path if and only if the actor holds
 *     Capability::STRATEGY_EDIT + CLUSTER_TREE_MANAGE on
 *     actor.organization_id. Two explicit checks before the rescue —
 *     neither primitive implies the other.
 *   - create / update / delete stay strict same-org (CRUD is OUT of CFA-03
 *     per CFA-00 owner decision).
 *   - Does not widen to gain write access in any other module.
 */
class BlockerPolicy
{
    use HandlesAuthorization;

    /**
     * Super Admin bypasses all abilities.
     */
    public function before(User $user, string $ability): ?bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return null;
    }

    /**
     * List blockers.
     */
    public function viewAny(User $user): bool
    {
        if (! $user->isSuperAdmin() && $user->organization_id === null) {
            return false;
        }

        return AccessDecision::can($user, Capability::STRATEGY_VIEW);
    }

    /**
     * Show a single blocker.
     *
     * Phase 9-D-D1b — Cluster tree widening applies to view() only.
     *
     * Decision paths:
     *  1) STRATEGY_VIEW on blocker (same org): engine's same-org + role check.
     *  2) CLUSTER_TREE_VIEW on blocker (cluster ancestor): engine's rescue
     *     branch verifies the ancestor walk + non-sensitive + scoped-role
     *     grant. Only fires if the actor holds Capability::STRATEGY_VIEW +
     *     CLUSTER_TREE_VIEW on actor.organization_id.
     *
     * Missing either capability ⇒ deny. Phase CFA-03 widens the governance
     * actions (resolve / escalate) via CLUSTER_TREE_MANAGE; CRUD
     * (update / delete / create) stays strict same-org per CFA-00 owner
     * decision.
     */
    public function view(User $user, Blocker $blocker): bool
    {
        if (AccessDecision::can($user, Capability::STRATEGY_VIEW, $blocker)) {
            return true;
        }

        if (! AccessDecision::can($user, Capability::STRATEGY_VIEW)) {
            return false;
        }
        if (! AccessDecision::can($user, Capability::CLUSTER_TREE_VIEW)) {
            return false;
        }

        return AccessDecision::can($user, Capability::CLUSTER_TREE_VIEW, $blocker);
    }

    /**
     * Create a blocker — requires actor.org + STRATEGY_CREATE.
     */
    public function create(User $user): bool
    {
        if (! $user->organization_id) {
            return false;
        }

        return AccessDecision::can($user, Capability::STRATEGY_CREATE);
    }

    /**
     * Update a blocker — strict same-org.
     */
    public function update(User $user, Blocker $blocker): bool
    {
        return AccessDecision::can($user, Capability::STRATEGY_EDIT, $blocker);
    }

    /**
     * Delete a blocker — strict same-org.
     */
    public function delete(User $user, Blocker $blocker): bool
    {
        return AccessDecision::can($user, Capability::STRATEGY_DELETE, $blocker);
    }

    /**
     * Resolve a blocker.
     *
     * Phase CFA-03 — Cluster tree widening (governance actions):
     *   - same-org: STRATEGY_EDIT on blocker
     *   - cross-org: STRATEGY_EDIT + CLUSTER_TREE_MANAGE on actor.org
     */
    public function resolve(User $user, Blocker $blocker): bool
    {
        return $this->clusterManagedAbility($user, $blocker, Capability::STRATEGY_EDIT);
    }

    /**
     * Escalate a blocker.
     *
     * Phase CFA-03 — Cluster tree widening (governance actions):
     *   - same-org: STRATEGY_EDIT on blocker
     *   - cross-org: STRATEGY_EDIT + CLUSTER_TREE_MANAGE on actor.org
     */
    public function escalate(User $user, Blocker $blocker): bool
    {
        return $this->clusterManagedAbility($user, $blocker, Capability::STRATEGY_EDIT);
    }

    /**
     * Phase CFA-03 — Two-path cluster_tree.manage rescue for governance
     * actions on blockers (resolve / escalate).
     *
     * Mirrors the CFA-00 view() pattern: same-org via engine strict equality
     * + scoped-role check; cross-org via the engine's cluster_tree rescue
     * branch which verifies ancestor walk + non-sensitive target. Both
     * grants are required IN ADDITION TO the actor's authority on the
     * module write capability — neither primitive implies the other.
     */
    protected function clusterManagedAbility(User $user, Blocker $blocker, string $moduleCapability): bool
    {
        // Path 1: same-org via engine.
        if (AccessDecision::can($user, $moduleCapability, $blocker)) {
            return true;
        }

        // Path 2: cross-org rescue — both grants required on actor.org.
        if (! AccessDecision::can($user, $moduleCapability)) {
            return false;
        }
        if (! AccessDecision::can($user, Capability::CLUSTER_TREE_MANAGE)) {
            return false;
        }

        return AccessDecision::can($user, Capability::CLUSTER_TREE_MANAGE, $blocker);
    }
}
