<?php

namespace App\Modules\OVR\Scopes;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\OVR\Models\IncidentReport;
use Illuminate\Database\Eloquent\Builder;

/**
 * UserOvrScope - the unified org-isolation filter for OVR cluster aggregate queries.
 *
 * Phase CFA-09 — Cluster aggregate reporting (NEVER raw).
 *
 * Single source of truth for the OVR org-floor widening on AGGREGATE endpoints
 * (clusterStats / clusterExport). Mirrors UserKpiScope (Phase 9-D-D1a + CFA-02)
 * and UserStrategyScope (Phase 9-D-D1b).
 *
 * Behavior:
 *   - super_admin: no filter (sees everything).
 *   - actor without organization_id: whereRaw('false') — fail-closed (0 rows).
 *   - actor with OVR_VIEW_STATISTICS + CLUSTER_TREE_VIEW on actor.org: floor
 *     widens to actor.org + descendant organizations (BFS via parent_id,
 *     depth cap 32, fail-closed on cycle).
 *   - actor with OVR_EXPORT + CLUSTER_TREE_EXPORT on actor.org: same widening
 *     applies for aggregate export only.
 *   - missing either grant ⇒ strict same-org floor ([actor.org] only).
 *
 * TWO NON-NEGOTIABLE INVARIANTS — verified by ClusterTreeOvrConfidentialFloorInvariantTest:
 *   1. **NEVER widens raw read endpoints.** This scope is ONLY invoked by
 *      clusterStats() and clusterExport(). Raw index/show/recent stay org-
 *      strict (the existing scopeForOrganization + scopeVisibleTo path).
 *   2. **is_confidential floor preserved.** Any aggregate query against this
 *      scope MUST also filter `is_confidential = false`. Confidential reports
 *      do not surface in cluster counts even if the cluster actor would
 *      otherwise see them — the cluster widening grants do NOT carry
 *      OVR_CONFIDENTIAL.
 *
 * CFA-00 strict contract (cluster_tree only widens aggregates):
 *   - view() / viewAny() / show() / index() / recent() / update() / delete() /
 *     changeStatus() / assign() / comment() / viewInternalComments() — all
 *     stay strict same-org via the existing per-row policy gates.
 *   - viewStatistics() (existing endpoint) stays strict same-org — the
 *     existing /api/ovr/incidents/stats endpoint is NOT cluster-widened.
 *   - export() (existing endpoint) stays strict same-org — the existing
 *     /api/ovr/incidents/export endpoint is NOT cluster-widened.
 *   - clusterStats() — NEW endpoint at /api/ovr/incidents/cluster-stats,
 *     widens to descendants via this scope.
 *   - clusterExport() — NEW endpoint at /api/ovr/incidents/cluster-export,
 *     widens to descendants via this scope.
 *
 * The two widening pairs (stats pair + export pair) are independent, matching
 * the CFA-02 KPI export pattern. A user may hold one or both, depending on
 * the grants their scoped role carries.
 */
class UserOvrScope
{
    /**
     * Build the org-id list an actor may aggregate across, for the stats pair.
     *
     * Returns [actor.organization_id] when EITHER grant is missing on actor.org
     * (strict same-org floor). Returns actor.org + descendant ids when BOTH
     * OVR_VIEW_STATISTICS + CLUSTER_TREE_VIEW are held.
     *
     * Returns [] for null-org actors — the caller must short-circuit on that
     * and either return 0 rows or fail closed.
     *
     * @return list<int>
     */
    public function clusterStatsVisibleOrgIds(User $actor): array
    {
        return $this->clusterVisibleOrgIdsFor(
            $actor,
            Capability::OVR_VIEW_STATISTICS,
            Capability::CLUSTER_TREE_VIEW
        );
    }

    /**
     * Build the org-id list an actor may aggregate across, for the export pair.
     *
     * Returns [actor.organization_id] when EITHER grant is missing on actor.org.
     * Returns actor.org + descendant ids when BOTH OVR_EXPORT +
     * CLUSTER_TREE_EXPORT are held.
     *
     * @return list<int>
     */
    public function clusterExportVisibleOrgIds(User $actor): array
    {
        return $this->clusterVisibleOrgIdsFor(
            $actor,
            Capability::OVR_EXPORT,
            Capability::CLUSTER_TREE_EXPORT
        );
    }

    /**
     * Apply the cluster-stats floor to an IncidentReport aggregate query.
     *
     * Adds the org-id IN (...) clause AND the `is_confidential = false` filter
     * (the cluster actor does NOT hold OVR_CONFIDENTIAL by construction, so
     * confidential reports must not surface in the aggregate count).
     *
     * super_admin: no floor. The caller is responsible for honoring their own
     * confidentiality rules elsewhere (super_admin bypasses the policy before()
     * hook and the per-row mayViewConfidential check).
     *
     * null-org actor: whereRaw('false') — fail-closed.
     */
    public function applyToIncidentReportsForStats(Builder $query, User $actor): Builder
    {
        if ($actor->isSuperAdmin()) {
            return $query;
        }

        if ($actor->organization_id === null) {
            return $query->whereRaw('false');
        }

        $reportsTable = (new IncidentReport)->getTable();

        return $query
            ->whereIn("{$reportsTable}.organization_id", $this->clusterStatsVisibleOrgIds($actor))
            // Confidential floor preserved (CFA-00 stop condition #2).
            ->where("{$reportsTable}.is_confidential", false);
    }

    /**
     * Apply the cluster-export floor to an IncidentReport aggregate query.
     * Same rules as applyToIncidentReportsForStats, gated on the export pair.
     */
    public function applyToIncidentReportsForExport(Builder $query, User $actor): Builder
    {
        if ($actor->isSuperAdmin()) {
            return $query;
        }

        if ($actor->organization_id === null) {
            return $query->whereRaw('false');
        }

        $reportsTable = (new IncidentReport)->getTable();

        return $query
            ->whereIn("{$reportsTable}.organization_id", $this->clusterExportVisibleOrgIds($actor))
            ->where("{$reportsTable}.is_confidential", false);
    }

    /**
     * Compute the cluster-widened org-id list for a given capability pair.
     *
     * Both grants required INDEPENDENTLY (AccessDecision::can checks the
     * actor's scoped roles on actor.organization_id — neither capability
     * implies the other). Missing either ⇒ strict same-org.
     *
     * super_admin short-circuits earlier in the public helpers; this
     * method assumes a regular actor with a non-null organization.
     *
     * @return list<int>
     */
    private function clusterVisibleOrgIdsFor(User $actor, string $moduleCap, string $clusterCap): array
    {
        if ($actor->organization_id === null) {
            return [];
        }

        $orgId = (int) $actor->organization_id;
        $visible = [$orgId];

        $hasModule = AccessDecision::can($actor, $moduleCap);
        $hasCluster = AccessDecision::can($actor, $clusterCap);
        if (! $hasModule || ! $hasCluster) {
            return $visible;
        }

        $org = Organization::query()->find($orgId);
        if (! $org instanceof Organization) {
            return $visible;
        }

        return array_values(array_unique(array_merge($visible, $org->descendantIds())));
    }
}
