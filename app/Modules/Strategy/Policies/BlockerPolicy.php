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
 *   - create / update / delete / resolve / escalate stay strict same-org
 *     (precheck guard).
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
     * Missing either capability ⇒ deny. Writes are unaffected (they go
     * through update / delete / create / resolve / escalate).
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
     * Resolve a blocker — strict same-org.
     */
    public function resolve(User $user, Blocker $blocker): bool
    {
        return AccessDecision::can($user, Capability::STRATEGY_EDIT, $blocker);
    }

    /**
     * Escalate a blocker — strict same-org.
     */
    public function escalate(User $user, Blocker $blocker): bool
    {
        return AccessDecision::can($user, Capability::STRATEGY_EDIT, $blocker);
    }
}
