<?php

namespace App\Modules\Projects\Policies;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Services\ProjectAuthorizationService;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * ProjectPolicy - Project authorization policy.
 *
 * Engine-only: relies entirely on AccessDecision::can().
 *
 * Phase CFA-04 — Cluster Full Authority widening:
 *   - view() allows AccessDecision::can(CLUSTER_TREE_VIEW, $project) as a
 *     second path if and only if the actor holds Capability::PROJECTS_VIEW +
 *     CLUSTER_TREE_VIEW on actor.organization_id. The engine's rescue branch
 *     verifies the ancestor walk + non-sensitive target.
 *   - Status / PDCA transitions (update with status / pdca-phase fields)
 *     widen via PROJECTS_EDIT + CLUSTER_TREE_MANAGE on actor.org — handled
 *     by the governance-write helper clusterManagedUpdate() below.
 *   - Per CFA-00 owner decision (2026-07-09): CRUD (update/delete/create)
 *     stays strict same-org EXCEPT for status / PDCA transitions (which are
 *     a governance-write subset, not arbitrary CRUD).
 *   - Per CFA-00 owner decision: NO project role/member assignment widening
 *     (assignProjectRoles stays strict same-org).
 *   - Direction R / Direction B / KPI 9-D-D1a / Strategy 9-D-D1b / CFA-01..03
 *     all preserved (no engine change).
 */
class ProjectPolicy
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
     * List projects.
     */
    public function viewAny(User $user): bool
    {
        return AccessDecision::can($user, Capability::PROJECTS_VIEW);
    }

    /**
     * Show a single project.
     *
     * Phase CFA-04 — Cluster tree widening applies to view() only.
     *
     * Decision paths:
     *  1) PROJECTS_VIEW on project (same org): engine's same-org + role check.
     *  2) CLUSTER_TREE_VIEW on project (cluster ancestor): engine's rescue
     *     branch verifies the ancestor walk + non-sensitive + scoped-role
     *     grant. Only fires if the actor holds Capability::PROJECTS_VIEW +
     *     CLUSTER_TREE_VIEW on actor.organization_id — two explicit checks
     *     before the rescue.
     *
     * Missing either capability ⇒ deny. Writes (update / delete / create /
     * assignProjectRoles) are unaffected — they go through their own
     * dedicated abilities below.
     */
    public function view(User $user, Project $project): bool
    {
        // super_admin is handled in the engine (short-circuit in whyCan::step 1).
        // null-org actor is handled in the engine (org_isolation_denied in step 2).

        // Path 1: same-org PROJECTS_VIEW via engine.
        if (AccessDecision::can($user, Capability::PROJECTS_VIEW, $project)) {
            return true;
        }

        // Path 2: cross-org cluster_tree widening — requires BOTH entitlements on actor.org.
        if (! AccessDecision::can($user, Capability::PROJECTS_VIEW)) {
            return false;
        }
        if (! AccessDecision::can($user, Capability::CLUSTER_TREE_VIEW)) {
            return false;
        }

        return AccessDecision::can($user, Capability::CLUSTER_TREE_VIEW, $project);
    }

    /**
     * Create a project — public gate (can the actor create at all?).
     *
     * Includes: org-level functional role, or a departmental role granting
     * projects.create (manager / member of a department can create inside
     * their department), or governing-department membership on a type.
     * Context-aware decision (type + target department) is made in
     * StoreProjectRequest::authorize via ProjectAuthorizationService::canCreate.
     */
    public function create(User $user): bool
    {
        return app(ProjectAuthorizationService::class)->canCreateAny($user);
    }

    /**
     * Update a project.
     *
     * Phase CFA-04 — Cluster widening applies ONLY to status / PDCA
     * transitions (governance writes), NOT to arbitrary CRUD. The two-path
     * clusterManagedUpdate() helper covers the governance-write subset;
     * any non-status / non-PDCA update is rejected for cross-org actors
     * even with both grants held.
     *
     * The controller is responsible for routing status / PDCA updates
     * through clusterManagedUpdate() and rejecting all other field
     * updates on cross-org requests. See UpdateProjectRequest.
     */
    public function update(User $user, Project $project): bool
    {
        return AccessDecision::can($user, Capability::PROJECTS_EDIT, $project);
    }

    /**
     * Phase CFA-04 — Status / PDCA transition governance write.
     *
     * Same-org path: PROJECTS_EDIT on project (engine same-org + role check).
     *
     * Cross-org path: PROJECTS_EDIT + CLUSTER_TREE_MANAGE on actor.org +
     * engine rescue branch verifies ancestor walk + non-sensitive target.
     *
     * Two explicit capability checks before the rescue — neither primitive
     * implies the other. Sensitive-target floor preserved (SensitivelyScoped
     * + isSensitive=true is final).
     *
     * Called from UpdateProjectRequest::authorize (for `status` and
     * `current_pdca_phase` field-only updates) and from the PDCA
     * controller endpoint. Other field updates continue to use update()
     * which stays strict same-org.
     */
    public function updateStatus(User $user, Project $project): bool
    {
        return $this->clusterManagedUpdate($user, $project, Capability::PROJECTS_EDIT);
    }

    /**
     * Two-path cluster_tree.manage rescue for governance writes on
     * Projects (status / PDCA transitions only).
     *
     * Mirrors the CFA-00 view() pattern: same-org via engine strict
     * equality + scoped-role check; cross-org via the engine's
     * cluster_tree rescue branch which verifies ancestor walk +
     * non-sensitive target. Both grants are required IN ADDITION TO
     * the actor's authority on the module write capability — neither
     * primitive implies the other.
     */
    protected function clusterManagedUpdate(User $user, Project $project, string $moduleCapability): bool
    {
        // Path 1: same-org via engine.
        if (AccessDecision::can($user, $moduleCapability, $project)) {
            return true;
        }

        // Path 2: cross-org rescue — both grants required on actor.org.
        if (! AccessDecision::can($user, $moduleCapability)) {
            return false;
        }
        if (! AccessDecision::can($user, Capability::CLUSTER_TREE_MANAGE)) {
            return false;
        }

        return AccessDecision::can($user, Capability::CLUSTER_TREE_MANAGE, $project);
    }

    /**
     * Delete a project.
     *
     * Per CFA-00 owner decision: delete stays strict same-org. No cluster
     * widening for delete (the cluster rescue only applies to status /
     * PDCA transitions via updateStatus).
     */
    public function delete(User $user, Project $project): bool
    {
        return AccessDecision::can($user, Capability::PROJECTS_DELETE, $project);
    }

    /**
     * Restore a soft-deleted project.
     */
    public function restore(User $user, Project $project): bool
    {
        return $this->delete($user, $project);
    }

    /**
     * Force-delete a project — Super Admin only (handled in before()).
     */
    public function forceDelete(User $user, Project $project): bool
    {
        return false;
    }

    /**
     * Assign project roles to members.
     *
     * Single source of truth for the project-team management capability —
     * unified across the Projects `/projects/{id}/members/*` route family
     * and the Core `ScopedRoleController` `/projects/{id}/roles/*` alias.
     * Replaces the prior `PROJECTS_MANAGE_MEMBERS` capability (deleted
     * 2026-07-06) which was registered and seeded but never enforced.
     *
     * Per CFA-00 owner decision: assignProjectRoles stays strict same-org.
     * NO cluster widening for project role / member assignment. Cluster
     * PMOs monitor projects via view + can change status / PDCA, but do
     * NOT assign team members cross-org.
     */
    public function assignProjectRoles(User $user, Project $project): bool
    {
        return AccessDecision::can($user, Capability::PROJECTS_ASSIGN_ROLES, $project);
    }
}
