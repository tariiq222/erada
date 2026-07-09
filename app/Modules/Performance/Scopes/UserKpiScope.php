<?php

namespace App\Modules\Performance\Scopes;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * UserKpiScope - the unified filter isolating KPI lists at the organization level.
 *
 * This is the single place that applies the organization_id filter to
 * Kpi / KpiMeasurement / KpiLink queries. It is not re-implemented in any
 * Controller. When building any Eloquent query for these entities on a
 * Builder, the matching applyTo* method must be called.
 *
 * Behavior (per variant):
 *   - super_admin: no filter.
 *   - actor without organization_id: whereRaw('false') — fail-closed (sees nothing).
 *   - regular actor: organization_id filter directly on the table column.
 *
 * Phase 9-D-D1a — Cluster tree widening (read-only):
 *   - actor holding Capability::KPIS_VIEW + Capability::CLUSTER_TREE_VIEW on
 *     actor.organization_id ⇒ the filter widens to include descendant organizations.
 *   - widening conditions: no wildcard, no is_admin_role shortcut, read-only.
 *   - does not widen to siblings (one-directional: user.org ⇒ descendants).
 *
 * Does not rely on the department hierarchy chain; the AccessDecision engine
 * handles the hierarchical detail via scope-chain. This Scope is only
 * responsible for the horizontal cut to the user's organization (org isolation
 * floor) plus the cluster_tree widening.
 *
 * Phase 4 — Performance Org-Isolation: created to strengthen the horizontal
 * isolation of the Performance module after scopeToCurrentOrganization was
 * found duplicated inline across 3 controllers.
 */
class UserKpiScope
{
    /**
     * Filter a Kpi query (the KPIs themselves).
     * Used in KpiController::filteredKpiQuery, contextKpis, and others.
     */
    public function applyToKpis(Builder $query, User $actor): Builder
    {
        if ($actor->isSuperAdmin()) {
            return $query;
        }

        if ($actor->organization_id === null) {
            return $query->whereRaw('false');
        }

        return $query->whereIn('kpis.organization_id', $this->clusterVisibleOrgIds($actor));
    }

    /**
     * Filter a KpiMeasurement query via its parent KPI.
     * The filter is applied on the kpi.organization_id relation because
     * measurements are always read through a KPI context.
     */
    public function applyToMeasurements(Builder $query, User $actor): Builder
    {
        if ($actor->isSuperAdmin()) {
            return $query;
        }

        if ($actor->organization_id === null) {
            return $query->whereRaw('false');
        }

        $visibleOrgIds = $this->clusterVisibleOrgIds($actor);

        return $query->whereHas(
            'kpi',
            fn (Builder $k) => $k->whereIn('kpis.organization_id', $visibleOrgIds)
        );
    }

    /**
     * Filter a KpiLink query via its parent KPI.
     */
    public function applyToLinks(Builder $query, User $actor): Builder
    {
        if ($actor->isSuperAdmin()) {
            return $query;
        }

        if ($actor->organization_id === null) {
            return $query->whereRaw('false');
        }

        $visibleOrgIds = $this->clusterVisibleOrgIds($actor);

        return $query->whereHas(
            'kpi',
            fn (Builder $k) => $k->whereIn('kpis.organization_id', $visibleOrgIds)
        );
    }

    /**
     * List of organization ids visible to the user under the cluster_tree policy.
     *
     * - Default: [actor.organization_id] only (strict same-org). Preserves the
     *   pre-9-D-D1a UserKpiScope behavior (a user without capability grants sees
     *   only their own organization) to avoid breaking existing regression tests.
     *
     * - Widening (read-only): if and only if the actor holds
     *   Capability::KPIS_VIEW + Capability::CLUSTER_TREE_VIEW on actor.organization_id,
     *   descendant organizations (via parent_id) are added to the list.
     *
     * Widening conditions:
     *   - KPIS_VIEW + CLUSTER_TREE_VIEW: both required (CLUSTER_TREE_VIEW alone is
     *     not enough — the engine capability is required to ensure the actor is
     *     entitled to see KPIs at all).
     *   - Does not rely on is_admin_role. Does not widen to siblings.
     *   - Does not rely on a materialized path — uses parent_id + visited set + depth cap 32.
     *
     * @return list<int>
     */
    protected function clusterVisibleOrgIds(User $actor): array
    {
        if ($actor->organization_id === null) {
            return [];
        }

        $orgId = (int) $actor->organization_id;
        $visible = [$orgId];

        // Both capabilities are required to widen cluster_tree. Missing either ⇒ strict same-org.
        $hasKpisView = AccessDecision::can($actor, Capability::KPIS_VIEW);
        $hasClusterTreeView = AccessDecision::can($actor, Capability::CLUSTER_TREE_VIEW);
        if (! $hasKpisView || ! $hasClusterTreeView) {
            return $visible;
        }

        $org = Organization::query()->find($orgId);
        if (! $org instanceof Organization) {
            return $visible;
        }

        return array_values(array_unique(array_merge($visible, $org->descendantIds())));
    }
}
