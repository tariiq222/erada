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
 *
 * Phase CFA-03 — Cluster tree governance-write widening:
 *   - changeStrategicStatus, forceClose, managePriority, assignOwner all
 *     allow AccessDecision::can(CLUSTER_TREE_MANAGE, $portfolio) as a
 *     second path if and only if the actor holds the matching module
 *     capability (STRATEGY_CHANGE_STATUS / STRATEGY_MANAGE_PRIORITY /
 *     STRATEGY_ASSIGN_OWNER) + CLUSTER_TREE_MANAGE on actor.organization_id.
 *     Two explicit checks before the rescue — neither primitive implies the
 *     other.
 *   - update / delete / create stay strict same-org (CRUD is OUT of CFA-03
 *     per CFA-00 owner decision; program linkProject also out of scope).
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
     * Missing either capability ⇒ deny. Phase CFA-03 widens the governance
     * write methods (managePriority / changeStrategicStatus / forceClose /
     * assignOwner) via CLUSTER_TREE_MANAGE; CRUD (update / delete / create)
     * stays strict same-org per CFA-00 owner decision.
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
     *
     * Phase CFA-03 — Cluster tree widening (governance writes):
     *   - same-org: STRATEGY_MANAGE_PRIORITY on portfolio
     *   - cross-org: STRATEGY_MANAGE_PRIORITY + CLUSTER_TREE_MANAGE on actor.org
     *     (engine's rescue branch verifies the ancestor walk + non-sensitive target)
     */
    public function managePriority(User $user, Portfolio $portfolio): bool
    {
        return $this->clusterManagedAbility($user, $portfolio, Capability::STRATEGY_MANAGE_PRIORITY);
    }

    /**
     * Change a portfolio's strategic status.
     *
     * Phase CFA-03 — Cluster tree widening (governance writes):
     *   - same-org: STRATEGY_CHANGE_STATUS on portfolio
     *   - cross-org: STRATEGY_CHANGE_STATUS + CLUSTER_TREE_MANAGE on actor.org
     */
    public function changeStrategicStatus(User $user, Portfolio $portfolio): bool
    {
        return $this->clusterManagedAbility($user, $portfolio, Capability::STRATEGY_CHANGE_STATUS);
    }

    /**
     * Force-close a portfolio.
     *
     * Phase CFA-03 — Cluster tree widening (governance writes):
     *   - same-org: STRATEGY_CHANGE_STATUS on portfolio
     *   - cross-org: STRATEGY_CHANGE_STATUS + CLUSTER_TREE_MANAGE on actor.org
     */
    public function forceClose(User $user, Portfolio $portfolio): bool
    {
        return $this->clusterManagedAbility($user, $portfolio, Capability::STRATEGY_CHANGE_STATUS);
    }

    /**
     * Assign a portfolio owner.
     *
     * Phase CFA-03 — Cluster tree widening (governance writes):
     *   - same-org: STRATEGY_ASSIGN_OWNER on portfolio
     *   - cross-org: STRATEGY_ASSIGN_OWNER + CLUSTER_TREE_MANAGE on actor.org
     *
     * Assignee-validation contract: when widening, the actor still picks the
     * assignee from the target org's user pool — same as a same-org
     * STRATEGY_ASSIGN_OWNER call. The cluster widening only authorizes the
     * ACTION, not the assignee selection. The controller enforces the
     * same-org check on the assignee via the standard
     * organizationIdForWrite() helper.
     */
    public function assignOwner(User $user, Portfolio $portfolio): bool
    {
        return $this->clusterManagedAbility($user, $portfolio, Capability::STRATEGY_ASSIGN_OWNER);
    }

    /**
     * Phase CFA-03 — Two-path cluster_tree.manage rescue for governance
     * abilities (managePriority / changeStrategicStatus / forceClose /
     * assignOwner).
     *
     * Mirrors the CFA-00 view() pattern: same-org via engine strict equality
     * + scoped-role check; cross-org via the engine's cluster_tree rescue
     * branch which verifies ancestor walk + non-sensitive target. Both
     * grants are required IN ADDITION TO the actor's authority on the
     * module write capability — neither primitive implies the other.
     *
     * Phase CFA-00 owner decision: program linkProject is OUT of scope for
     * CFA-03 (not approved for cluster widening). CRUD (create / update /
     * delete) is OUT of scope for the same reason. See CLUSTER_TREE_VIEW
     * phase 9-D-D1b for the read-only counterpart.
     */
    protected function clusterManagedAbility(User $user, Portfolio $portfolio, string $moduleCapability): bool
    {
        // Path 1: same-org via engine.
        if (AccessDecision::can($user, $moduleCapability, $portfolio)) {
            return true;
        }

        // Path 2: cross-org rescue — both grants required on actor.org.
        if (! AccessDecision::can($user, $moduleCapability)) {
            return false;
        }
        if (! AccessDecision::can($user, Capability::CLUSTER_TREE_MANAGE)) {
            return false;
        }

        return AccessDecision::can($user, Capability::CLUSTER_TREE_MANAGE, $portfolio);
    }
}
