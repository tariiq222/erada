<?php

namespace App\Modules\Strategy\Policies;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\User;
use App\Modules\Strategy\Models\Program;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * ProgramPolicy — Program authorization policy.
 *
 * Engine-only: relies entirely on AccessDecision::can(). The legacy Spatie
 * flat / FK-comparison logic was removed when engine=ON was finalized
 * (Phase E).
 *
 * Phase 9-D-D1b — Cluster tree read widening:
 *   - view() / viewReports() allow AccessDecision::can(CLUSTER_TREE_VIEW, $program)
 *     as a second path if and only if the actor holds Capability::STRATEGY_VIEW
 *     + CLUSTER_TREE_VIEW on actor.organization_id.
 *   - update / delete / create / changePortfolio / manageWeight /
 *     manageProjects / assignProgramManager / assignExecutiveSponsor /
 *     linkProject stay strict same-org.
 *   - Does not widen to gain write access in any other module.
 */
class ProgramPolicy
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
     * List programs.
     */
    public function viewAny(User $user): bool
    {
        return AccessDecision::can($user, Capability::STRATEGY_VIEW);
    }

    /**
     * Show a single program.
     *
     * Phase 9-D-D1b — Cluster tree widening applies to view() only.
     *
     * Decision paths:
     *  1) STRATEGY_VIEW on program (same org): engine's same-org + role check.
     *  2) CLUSTER_TREE_VIEW on program (cluster ancestor): engine's rescue
     *     branch verifies the ancestor walk + non-sensitive + scoped-role
     *     grant. Only fires if the actor holds Capability::STRATEGY_VIEW +
     *     CLUSTER_TREE_VIEW on actor.organization_id.
     *
     * Missing either capability ⇒ deny. Writes are unaffected (they go
     * through update / delete / create / changePortfolio / manageWeight /
     * manageProjects / assignProgramManager / assignExecutiveSponsor /
     * linkProject).
     */
    public function view(User $user, Program $program): bool
    {
        // Path 1: same-org STRATEGY_VIEW via engine.
        if (AccessDecision::can($user, Capability::STRATEGY_VIEW, $program)) {
            return true;
        }

        // Path 2: cross-org cluster_tree widening — requires BOTH entitlements on actor.org.
        if (! AccessDecision::can($user, Capability::STRATEGY_VIEW)) {
            return false;
        }
        if (! AccessDecision::can($user, Capability::CLUSTER_TREE_VIEW)) {
            return false;
        }

        return AccessDecision::can($user, Capability::CLUSTER_TREE_VIEW, $program);
    }

    /**
     * Create a program.
     */
    public function create(User $user): bool
    {
        if (! $user->organization_id) {
            return false;
        }

        return AccessDecision::can($user, Capability::STRATEGY_CREATE);
    }

    /**
     * Update a program.
     */
    public function update(User $user, Program $program): bool
    {
        return AccessDecision::can($user, Capability::STRATEGY_EDIT, $program);
    }

    /**
     * Delete a program.
     */
    public function delete(User $user, Program $program): bool
    {
        return AccessDecision::can($user, Capability::STRATEGY_DELETE, $program);
    }

    /**
     * Restore a soft-deleted program — Super Admin only (handled in before()).
     */
    public function restore(User $user, Program $program): bool
    {
        return false;
    }

    /**
     * Force-delete a program — Super Admin only (handled in before()).
     */
    public function forceDelete(User $user, Program $program): bool
    {
        return false;
    }

    /**
     * Change a program's parent portfolio or weight — requires manage_priority.
     */
    public function changePortfolio(User $user, Program $program): bool
    {
        return AccessDecision::can($user, Capability::STRATEGY_MANAGE_PRIORITY, $program);
    }

    /**
     * Manage a program's weight.
     */
    public function manageWeight(User $user, Program $program): bool
    {
        return AccessDecision::can($user, Capability::STRATEGY_MANAGE_PRIORITY, $program);
    }

    /**
     * Manage the projects inside a program.
     */
    public function manageProjects(User $user, Program $program): bool
    {
        return AccessDecision::can($user, Capability::STRATEGY_MANAGE_PROJECTS, $program);
    }

    /**
     * Assign a program manager.
     */
    public function assignProgramManager(User $user, Program $program): bool
    {
        return AccessDecision::can($user, Capability::STRATEGY_ASSIGN_OWNER, $program);
    }

    /**
     * Assign an executive sponsor.
     */
    public function assignExecutiveSponsor(User $user, Program $program): bool
    {
        return AccessDecision::can($user, Capability::STRATEGY_ASSIGN_OWNER, $program);
    }

    /**
     * Link or unlink a project to/from the program.
     */
    public function linkProject(User $user, Program $program): bool
    {
        return $this->manageProjects($user, $program);
    }

    /**
     * View a program's reports and indicators.
     */
    public function viewReports(User $user, Program $program): bool
    {
        return AccessDecision::can($user, Capability::STRATEGY_VIEW, $program);
    }
}
