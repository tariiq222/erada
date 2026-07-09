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
 * Phase CFA-02 — Cluster tree widening (export-only):
 *   - actor holding Capability::KPIS_EXPORT + Capability::CLUSTER_TREE_EXPORT on
 *     actor.organization_id ⇒ when the calling controller passes purpose='export'
 *     to applyToKpis, the filter widens to include descendant organizations.
 *   - the two widening pairs are independent — a user can be widened for reads
 *     but not for exports, or vice versa, depending on which grants they hold.
 *   - does not widen to siblings. read-only at the scope layer (writes remain
 *     strict same-org via KpiOrgGuard precheck).
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
     *
     * @param  string|null  $purpose  Optional purpose flag — controls which
     *                                cluster_tree widening pair applies.
     *                                null|'view' ⇒ 9-D-D1a read widening
     *                                (KPIS_VIEW + CLUSTER_TREE_VIEW).
     *                                'export' ⇒ CFA-02 export widening
     *                                (KPIS_EXPORT + CLUSTER_TREE_EXPORT).
     *                                Each purpose is independent — the same
     *                                actor can be widened for views without
     *                                being widened for exports, per CFA-00.
     */
    public function applyToKpis(Builder $query, User $actor, ?string $purpose = null): Builder
    {
        if ($actor->isSuperAdmin()) {
            return $query;
        }

        if ($actor->organization_id === null) {
            return $query->whereRaw('false');
        }

        return $query->whereIn('kpis.organization_id', $this->clusterVisibleOrgIds($actor, $purpose));
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
     * Two widening pairs are honored (independent, additive per purpose):
     *
     * - Read widening (9-D-D1a): when $purpose is null or 'view', widens to
     *   descendant organizations if and only if the actor holds
     *   Capability::KPIS_VIEW + Capability::CLUSTER_TREE_VIEW on actor.org.
     *   This is the existing pre-CFA-02 behavior — preserved byte-for-byte
     *   for all ClusterTreeUserKpiScopeTest + ClusterTreeKpiPolicyTest tests.
     *
     * - Export widening (CFA-02): when $purpose is 'export', widens to
     *   descendant organizations if and only if the actor holds
     *   Capability::KPIS_EXPORT + Capability::CLUSTER_TREE_EXPORT on actor.org.
     *   This is the new CFA-02 widening — strict CFA-00 contract.
     *
     * Default: [actor.organization_id] only (strict same-org) when neither
     * pair matches — preserves the pre-9-D-D1a UserKpiScope behavior.
     *
     * @return list<int>
     */
    protected function clusterVisibleOrgIds(User $actor, ?string $purpose = null): array
    {
        if ($actor->organization_id === null) {
            return [];
        }

        $orgId = (int) $actor->organization_id;
        $visible = [$orgId];

        // Each pair is independent — 'export' from the export controller does
        // NOT widen the view path, and vice versa.
        [$hasModuleCap, $hasClusterTreeCap] = match ($purpose) {
            'export' => [
                AccessDecision::can($actor, Capability::KPIS_EXPORT),
                AccessDecision::can($actor, Capability::CLUSTER_TREE_EXPORT),
            ],
            default => [
                AccessDecision::can($actor, Capability::KPIS_VIEW),
                AccessDecision::can($actor, Capability::CLUSTER_TREE_VIEW),
            ],
        };

        if (! $hasModuleCap || ! $hasClusterTreeCap) {
            return $visible;
        }

        $org = Organization::query()->find($orgId);
        if (! $org instanceof Organization) {
            return $visible;
        }

        return array_values(array_unique(array_merge($visible, $org->descendantIds())));
    }
}
