<?php

namespace App\Modules\RiskManagement\Scopes;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\RiskManagement\Services\RiskAuthorizationService;
use Illuminate\Database\Eloquent\Builder;

/**
 * UserRiskScope — applies the caller's risk visibility to a risks query.
 *
 * Additive (OR) model, mirroring UserProjectScope. After organization isolation,
 * a non-super-admin sees a risk when any of these holds:
 *   - direct relation: creator, owner, or listed stakeholder;
 *   - an active scoped role granting risks.view on the risk's department (or an
 *     ancestor, expanded to the subtree);
 *   - an org-level functional role granting risks.view (whole organization);
 *   - membership of the risks governing department (whole organization).
 *
 * Before this scope the risk list applied only an organization filter, so any
 * user with risks.view over-fetched every risk in the org. This narrows it.
 *
 * Phase CFA-05 — Cluster Full Authority widening (read-only):
 *   - When the actor holds BOTH Capability::RISKS_VIEW + CLUSTER_TREE_VIEW on
 *     actor.organization_id, the strict same-org floor widens to include
 *     descendant organizations via Organization::descendantIds().
 *   - Cluster widening is ADDITIVE: when both grants are held, the actor.org
 *     floor is replaced with [actor.org] + descendants; the remaining OR-logic
 *     (direct relation, department subtree, governing-department) is preserved
 *     as-is.
 *   - Per CFA-00 owner decision (2026-07-09): NO write widening here. CRUD
 *     (create/update/delete) stays strict same-org; reassess and
 *     change_status widen via RISKS_REASSESS + CLUSTER_TREE_MANAGE and
 *     RISKS_CHANGE_STATUS + CLUSTER_TREE_MANAGE respectively (handled in
 *     RiskPolicy, not here). Reporting exports widen via RISKS_VIEW_REPORTS
 *     + CLUSTER_TREE_EXPORT (handled in RiskDashboardController).
 */
class UserRiskScope
{
    public function apply(Builder $query, User $user): Builder
    {
        if ($user->isSuperAdmin()) {
            return $query;
        }

        // Phase CFA-05 — Cluster widening floor.
        // Default: strict same-org via actor.organization_id. When the actor
        // holds BOTH RISKS_VIEW + CLUSTER_TREE_VIEW on actor.org, the floor
        // widens to include descendant organizations (via parent_id BFS).
        // The remainder of the OR-logic below is preserved unchanged.
        $visibleOrgIds = $this->clusterVisibleOrgIds($user);
        if ($visibleOrgIds !== []) {
            $query->whereIn('organization_id', $visibleOrgIds);
        }

        $svc = app(RiskAuthorizationService::class);

        // Whole-organization access: an org-level functional grant (admin) or
        // the risks governing department (overseer). Org isolation above still
        // holds. The legacy flat view_risks Spatie fallback has been removed
        // — the engine path is the only authz source.
        if (AccessDecision::grantsAtOrganization($user, Capability::RISKS_VIEW)
            || $svc->governs($user)) {
            return $query;
        }

        // Otherwise: direct relation OR department-subtree grants.
        $scopes = AccessDecision::grantingScopes($user, Capability::RISKS_VIEW);
        $deptIds = AccessDecision::subtreeDepartmentIds($scopes['department'] ?? []);

        return $query->where(function (Builder $q) use ($user, $deptIds) {
            $q->where('created_by', $user->id)
                ->orWhere('owner_id', $user->id)
                ->orWhereJsonContains('stakeholder_ids', $user->id);

            if ($deptIds !== []) {
                $q->orWhereIn('department_id', $deptIds);
            }
        });
    }

    /**
     * Phase CFA-05 — Org floor for the cluster_tree widening (read-only).
     *
     * Returns the list of organization ids the actor may see under the
     * cluster_tree policy for Risks reads.
     *
     *   - Default: [actor.organization_id] only (strict same-org) when EITHER
     *     RISKS_VIEW or CLUSTER_TREE_VIEW is missing on actor.org. Preserves
     *     the pre-CFA-05 same-org behavior for users who do not hold both
     *     grants — the strict-equality gate remains in force.
     *
     *   - Widening (read-only): when the actor holds BOTH
     *     Capability::RISKS_VIEW + Capability::CLUSTER_TREE_VIEW on
     *     actor.organization_id, descendant organizations (via parent_id
     *     BFS) are added to the list. CFA-05 is read-only at the scope
     *     level — no widening to write paths (CRUD stays strict same-org
     *     per CFA-00 owner decision).
     *
     * Returns an empty array for null-org actors — the whereIn below is
     * skipped, leaving the org filter to be enforced by the engine's
     * strict-equality gate (per-target) at AccessDecision::can level.
     * super_admin is short-circuited earlier in apply() and never reaches
     * this helper.
     *
     * @return list<int>
     */
    protected function clusterVisibleOrgIds(User $user): array
    {
        if ($user->organization_id === null) {
            return [];
        }

        $orgId = (int) $user->organization_id;
        $visible = [$orgId];

        // Both grants required to widen cluster_tree. Missing either ⇒ strict same-org.
        $hasRisksView = AccessDecision::can($user, Capability::RISKS_VIEW);
        $hasClusterTreeView = AccessDecision::can($user, Capability::CLUSTER_TREE_VIEW);
        if (! $hasRisksView || ! $hasClusterTreeView) {
            return $visible;
        }

        $org = Organization::query()->find($orgId);
        $descendants = $org instanceof Organization ? $org->descendantIds() : [];

        return array_values(array_unique(array_merge($visible, $descendants)));
    }
}
