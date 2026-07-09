<?php

namespace App\Modules\Strategy\Scopes;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * UserStrategyScope - the unified filter isolating Strategy lists at the
 * organization level (Portfolio / Program / Review / Blocker).
 *
 * Single source of truth for the strategy org-isolation floor. Mirrors
 * App\Modules\Performance\Scopes\UserKpiScope (Phase 9-D-D1a) and applies
 * the same cluster_tree read widening rules:
 *
 *   - super_admin: no filter.
 *   - actor without organization_id: whereRaw('false') — fail-closed.
 *   - regular actor: organization_id IN (clusterVisibleOrgIds).
 *
 * Phase 9-D-D1b — Cluster tree widening (read-only):
 *   - actor holding Capability::STRATEGY_VIEW + Capability::CLUSTER_TREE_VIEW
 *     on actor.organization_id ⇒ the filter widens to include descendant
 *     organizations (via parent_id), so the actor can list Portfolios /
 *     Programs / Reviews / Blockers belonging to descendant organizations
 *     in addition to their own.
 *   - Both capabilities are required. Missing either ⇒ strict same-org.
 *   - Does not widen to siblings. Does not widen to parent. No shortcut.
 *   - Does not widen to mutations (create / update / delete / priority /
 *     status / link / unlink / resolve / escalate) — those paths keep
 *     their pre-9-D-D1b strict same-org guard via assertSameOrganization.
 *
 * Phase 9-D-D1b does not introduce a new ScopeAware subtype or modify
 * the engine: it only delegates to Organization::descendantIds() and
 * AccessDecision::can() the same way UserKpiScope does.
 */
class UserStrategyScope
{
    /**
     * Filter a Portfolio query (the portfolios themselves).
     * Mirrors the same column as Kpi: organization_id.
     */
    public function applyToPortfolios(EloquentBuilder|QueryBuilder $query, User $actor): EloquentBuilder|QueryBuilder
    {
        if ($actor->isSuperAdmin()) {
            return $query;
        }

        if ($actor->organization_id === null) {
            return $query->whereRaw('false');
        }

        return $query->whereIn('portfolios.organization_id', $this->clusterVisibleOrgIds($actor));
    }

    /**
     * Filter a Program query. Programs carry organization_id directly
     * (copied from their parent Portfolio at create time) and that column
     * is the authoritative horizontal isolation key.
     */
    public function applyToPrograms(EloquentBuilder|QueryBuilder $query, User $actor): EloquentBuilder|QueryBuilder
    {
        if ($actor->isSuperAdmin()) {
            return $query;
        }

        if ($actor->organization_id === null) {
            return $query->whereRaw('false');
        }

        return $query->whereIn('programs.organization_id', $this->clusterVisibleOrgIds($actor));
    }

    /**
     * Filter a Review query. Reviews carry organization_id directly on
     * their own table (copied from the polymorphic reviewable at create
     * time), so the same horizontal key applies.
     */
    public function applyToReviews(EloquentBuilder|QueryBuilder $query, User $actor): EloquentBuilder|QueryBuilder
    {
        if ($actor->isSuperAdmin()) {
            return $query;
        }

        if ($actor->organization_id === null) {
            return $query->whereRaw('false');
        }

        return $query->whereIn('reviews.organization_id', $this->clusterVisibleOrgIds($actor));
    }

    /**
     * Filter a Blocker query. Blockers carry organization_id directly on
     * their own table (copied from the polymorphic blockable at create
     * time), so the same horizontal key applies.
     */
    public function applyToBlockers(EloquentBuilder|QueryBuilder $query, User $actor): EloquentBuilder|QueryBuilder
    {
        if ($actor->isSuperAdmin()) {
            return $query;
        }

        if ($actor->organization_id === null) {
            return $query->whereRaw('false');
        }

        return $query->whereIn('blockers.organization_id', $this->clusterVisibleOrgIds($actor));
    }

    /**
     * List of organization ids visible to the user under the cluster_tree
     * policy for the Strategy module.
     *
     *   - Default: [actor.organization_id] only (strict same-org). Preserves
     *     the pre-9-D-D1b controller behavior (a user without capability
     *     grants sees only their own organization) to avoid breaking
     *     existing regression tests.
     *
     *   - Widening (read-only): if and only if the actor holds
     *     Capability::STRATEGY_VIEW + Capability::CLUSTER_TREE_VIEW on
     *     actor.organization_id, descendant organizations (via parent_id)
     *     are added to the list.
     *
     * Both capabilities are required. The engine-level rescue branch
     * (CLUSTER_TREE_VIEW) is consulted separately by the per-model
     * Policy::view() for per-record checks; this Scope is the per-list
     * widening only.
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

        $hasStrategyView = AccessDecision::can($actor, Capability::STRATEGY_VIEW);
        $hasClusterTreeView = AccessDecision::can($actor, Capability::CLUSTER_TREE_VIEW);
        if (! $hasStrategyView || ! $hasClusterTreeView) {
            return $visible;
        }

        $org = Organization::query()->find($orgId);
        if (! $org instanceof Organization) {
            return $visible;
        }

        return array_values(array_unique(array_merge($visible, $org->descendantIds())));
    }
}
