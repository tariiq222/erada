<?php

namespace App\Modules\Strategy\Policies;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\User;
use App\Modules\Strategy\Models\Portfolio;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * PortfolioPolicy — Portfolio authorization policy.
 *
 * Engine-only: relies entirely on AccessDecision::can(). The legacy Spatie
 * flat / hasRole('pmo') logic was removed when engine=ON was finalized
 * (Phase E).
 *
 * Phase 9-D-D1b — Cluster tree read widening:
 *   - view() allows AccessDecision::can(CLUSTER_TREE_VIEW, $portfolio) as a
 *     second path if and only if the actor holds Capability::STRATEGY_VIEW +
 *     CLUSTER_TREE_VIEW on actor.organization_id. The engine's rescue branch
 *     verifies the ancestor walk + non-sensitive target.
 *   - update / delete / create / change_status / manage_priority /
 *     assign_owner / forceClose stay strict same-org (precheck guard).
 *   - Does not widen to gain write access in any other module.
 */
class PortfolioPolicy
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
     * List portfolios.
     */
    public function viewAny(User $user): bool
    {
        return AccessDecision::can($user, Capability::STRATEGY_VIEW);
    }

    /**
     * Show a single portfolio.
     *
     * Phase 9-D-D1b — Cluster tree widening applies to view() only.
     *
     * Decision paths:
     *  1) STRATEGY_VIEW on portfolio (same org): engine's same-org + role check.
     *  2) CLUSTER_TREE_VIEW on portfolio (cluster ancestor): engine's rescue
     *     branch verifies the ancestor walk + non-sensitive + scoped-role
     *     grant. Only fires if the actor holds Capability::STRATEGY_VIEW +
     *     CLUSTER_TREE_VIEW on actor.organization_id — two explicit checks
     *     before the rescue.
     *
     * Missing either capability ⇒ deny. Writes are unaffected (they go
     * through update / delete / create / managePriority / changeStrategicStatus
     * / assignOwner / forceClose).
     */
    public function view(User $user, Portfolio $portfolio): bool
    {
        // super_admin is handled in the engine (short-circuit in whyCan::step 1).
        // null-org actor is handled in the engine (org_isolation_denied in step 2).

        // Path 1: same-org STRATEGY_VIEW via engine.
        if (AccessDecision::can($user, Capability::STRATEGY_VIEW, $portfolio)) {
            return true;
        }

        // Path 2: cross-org cluster_tree widening — requires BOTH entitlements on actor.org.
        if (! AccessDecision::can($user, Capability::STRATEGY_VIEW)) {
            return false;
        }
        if (! AccessDecision::can($user, Capability::CLUSTER_TREE_VIEW)) {
            return false;
        }

        return AccessDecision::can($user, Capability::CLUSTER_TREE_VIEW, $portfolio);
    }

    /**
     * Create a portfolio.
     */
    public function create(User $user): bool
    {
        if (! $user->organization_id) {
            return false;
        }

        return AccessDecision::can($user, Capability::STRATEGY_CREATE);
    }

    /**
     * Update a portfolio.
     */
    public function update(User $user, Portfolio $portfolio): bool
    {
        return AccessDecision::can($user, Capability::STRATEGY_EDIT, $portfolio);
    }

    /**
     * Delete a portfolio.
     */
    public function delete(User $user, Portfolio $portfolio): bool
    {
        return AccessDecision::can($user, Capability::STRATEGY_DELETE, $portfolio);
    }

    /**
     * Restore a soft-deleted portfolio — Super Admin only (handled in before()).
     */
    public function restore(User $user, Portfolio $portfolio): bool
    {
        return false;
    }

    /**
     * Force-delete a portfolio — Super Admin only (handled in before()).
     */
    public function forceDelete(User $user, Portfolio $portfolio): bool
    {
        return false;
    }

    /**
     * Manage a portfolio's priority and weight.
     */
    public function managePriority(User $user, Portfolio $portfolio): bool
    {
        return AccessDecision::can($user, Capability::STRATEGY_MANAGE_PRIORITY, $portfolio);
    }

    /**
     * Change a portfolio's strategic status.
     */
    public function changeStrategicStatus(User $user, Portfolio $portfolio): bool
    {
        return AccessDecision::can($user, Capability::STRATEGY_CHANGE_STATUS, $portfolio);
    }

    /**
     * Force-close a portfolio.
     */
    public function forceClose(User $user, Portfolio $portfolio): bool
    {
        return AccessDecision::can($user, Capability::STRATEGY_CHANGE_STATUS, $portfolio);
    }

    /**
     * Assign a portfolio owner.
     */
    public function assignOwner(User $user, Portfolio $portfolio): bool
    {
        return AccessDecision::can($user, Capability::STRATEGY_ASSIGN_OWNER, $portfolio);
    }
}
