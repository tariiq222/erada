<?php

namespace App\Modules\Surveys\Scopes;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * UserSurveyScope - the unified filter isolating survey lists and dependents
 * at the organization level.
 *
 * This is the single place that applies the organization_id filter to
 * Eloquent queries for the Surveys module (surveys, survey_responses,
 * survey_invitations, data_import_requests). It is NOT re-implemented inline
 * in any Controller or FormRequest.
 *
 * Behavior (per variant):
 *   - super_admin: no filter.
 *   - actor without organization_id: whereRaw('1 = 0') — fail-closed (sees nothing).
 *   - regular actor: organization_id filter directly on surveys.organization_id
 *     for surveys, and via whereHas('survey', ...) for sub-tables
 *     (SurveyResponse, SurveyInvitation, DataImportRequest) that do not carry
 *     a direct organization_id column.
 *
 * Does not rely on the department hierarchy chain; the AccessDecision engine
 * handles the hierarchical detail via scope-chain. This Scope is responsible
 * only for the horizontal cut to the user's organization (org isolation floor).
 *
 * Phase CFA-10 — Cluster aggregate reporting (NEVER raw responses):
 *   - `clusterVisibleOrgIds()` widens to descendant organizations when the
 *     actor holds Capability::SURVEYS_VIEW + Capability::CLUSTER_TREE_VIEW
 *     on actor.organization_id. This widening applies ONLY to the aggregate
 *     stats endpoint and the aggregate-only export endpoint — the per-survey
 *     `applyToSurveys()` / `applyToSurveyResponses()` paths stay strict
 *     same-org so raw responses are NEVER cluster-widened.
 *   - actor with only SURVEYS_VIEW (no CLUSTER_TREE_VIEW) ⇒ single-org list.
 *   - actor with only CLUSTER_TREE_VIEW (no SURVEYS_VIEW) ⇒ single-org list.
 *   - missing either grant ⇒ single-org list (BOTH required).
 *
 * Phase 6 — Surveys Org-Isolation: created as the floor before Phase 6.B
 * wired controllers + FormRequests to this Scope.
 */
class UserSurveyScope
{
    /**
     * Filter a Survey query (the surveys themselves).
     * The organization_id column exists directly on surveys.
     */
    public function applyToSurveys(Builder $query, User $actor): Builder
    {
        if ($actor->isSuperAdmin()) {
            return $query;
        }

        if ($actor->organization_id === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('surveys.organization_id', $actor->organization_id);
    }

    /**
     * Filter a SurveyResponse query via its parent Survey.
     * survey_responses has no direct organization_id column, so the
     * derivation flows through the relation.
     */
    public function applyToSurveyResponses(Builder $query, User $actor): Builder
    {
        return $query->whereHas(
            'survey',
            fn (Builder $s) => $this->applyToSurveys($s, $actor)
        );
    }

    /**
     * Filter a SurveyInvitation query via its parent Survey.
     * survey_invitations has no direct organization_id column, so the
     * derivation flows through the relation.
     */
    public function applyToSurveyInvitations(Builder $query, User $actor): Builder
    {
        return $query->whereHas(
            'survey',
            fn (Builder $s) => $this->applyToSurveys($s, $actor)
        );
    }

    /**
     * Filter a DataImportRequest query via response -> survey grandparent.
     * data_import_requests has no direct organization_id or survey_id column,
     * so the derivation flows through response -> survey (two relations).
     */
    public function applyToDataImportRequests(Builder $query, User $actor): Builder
    {
        return $query->whereHas(
            'response.survey',
            fn (Builder $s) => $this->applyToSurveys($s, $actor)
        );
    }

    /**
     * Phase CFA-10 — Aggregate-only widening for the cluster stats endpoint.
     *
     * Used by `SurveyResponseController::clusterStats()` (and its aggregate-only
     * export sibling) to enumerate the descendant organizations whose
     * aggregate counts/completion-rates/scores are returned in a single
     * dashboard payload. The widening applies ONLY at the aggregate boundary
     * — no individual `survey_responses` row, no respondent PII, no invitation
     * email leaks through this path.
     *
     * The filter widens to descendant organizations when (and only when) the
     * actor holds BOTH Capability::SURVEYS_VIEW AND Capability::CLUSTER_TREE_VIEW
     * on actor.organization_id. Either alone returns the strict same-org list.
     * A null-org actor returns an empty list (fail-closed).
     *
     * Per descendant enumeration contract (matches CFA-00 / CFA-01):
     *   - one-directional: user.org ⇒ descendants only (no siblings).
     *   - depth cap = 32 (mirrors Organization::descendantIds()).
     *   - cycle guard via descendantIds() (fail-closed on cycle).
     *
     * @return list<int>
     */
    public function clusterVisibleOrgIds(User $actor): array
    {
        return $this->clusterVisibleOrgIdsFor(
            $actor,
            Capability::SURVEYS_VIEW,
            Capability::CLUSTER_TREE_VIEW
        );
    }

    /**
     * Visible organizations for aggregate-only exports.
     *
     * SURVEYS_EXPORT permits the actor's own organization; the export expands
     * to descendants only with CLUSTER_TREE_EXPORT.
     *
     * @return list<int>
     */
    public function clusterExportVisibleOrgIds(User $actor): array
    {
        return $this->clusterVisibleOrgIdsFor(
            $actor,
            Capability::SURVEYS_EXPORT,
            Capability::CLUSTER_TREE_EXPORT
        );
    }

    /**
     * @return list<int>
     */
    private function clusterVisibleOrgIdsFor(User $actor, string $moduleCapability, string $clusterCapability): array
    {
        if ($actor->isSuperAdmin()) {
            // super_admin caller is responsible for handling their own list
            // (typically: every organization). This scope stays conservative
            // for non-super callers.
            return [];
        }

        if ($actor->organization_id === null) {
            return [];
        }

        $orgId = (int) $actor->organization_id;

        $hasModuleCapability = AccessDecision::can($actor, $moduleCapability);
        $hasClusterCapability = AccessDecision::can($actor, $clusterCapability);

        if (! $hasModuleCapability || ! $hasClusterCapability) {
            return [$orgId];
        }

        $org = Organization::query()->find($orgId);
        if (! $org instanceof Organization) {
            return [$orgId];
        }

        return array_values(array_unique($org->descendantIds()));
    }
}
