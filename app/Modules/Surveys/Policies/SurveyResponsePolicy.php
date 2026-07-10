<?php

namespace App\Modules\Surveys\Policies;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\User;
use App\Modules\Surveys\Models\Survey;
use App\Modules\Surveys\Models\SurveyResponse;
use Illuminate\Auth\Access\HandlesAuthorization;

class SurveyResponsePolicy
{
    use HandlesAuthorization;

    /**
     * Super Admin bypasses every gate. Engine treats super_admin as
     * always-true, but the explicit check here mirrors the rest of the
     * engine-only Policies and keeps the before() contract consistent.
     */
    public function before(User $user, string $ability): ?bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return null;
    }

    /**
     * Review a survey response: engine grants the capability, then
     * the response's owning survey must belong to the user's org.
     */
    public function review(User $user, SurveyResponse $response): bool
    {
        if (! AccessDecision::can($user, Capability::SURVEYS_REVIEW_RESPONSES)) {
            return false;
        }

        $surveyOrgId = $response->survey?->organization_id;

        if ($user->organization_id === null || $surveyOrgId === null) {
            return false;
        }

        return (int) $user->organization_id === (int) $surveyOrgId;
    }

    /**
     * Phase CFA-10 — View aggregate stats for a survey (NEVER raw responses).
     *
     * Decoupled from `review()` on purpose: `review()` keeps its existing
     * same-org semantics (returns false for any descendant-org target) and
     * is the SOLE authz seam for raw-response access. `viewStats()` is the
     * dedicated aggregate-only seam that cluster-widens to descendant
     * organizations. Returning true here does NOT enable raw response
     * reads, per-response review, or flag/review mutations.
     *
     * Two-path rescue:
     *   - The actor must hold Capability::SURVEYS_VIEW on actor.organization_id
     *     (the surveys view capability — never implied by cluster_tree alone).
     *   - The actor must hold Capability::CLUSTER_TREE_VIEW on actor.organization_id
     *     (the cluster_tree primitive — read-only, no implicit widening).
     *   - The survey's organization must be an ancestor of actor.organization_id
     *     via the parent_id walk (depth cap 32, fail-closed on cycle) — or the
     *     actor.org MUST equal the survey's org (same-org path).
     *   - super_admin short-circuits to true (actor bypasses every gate).
     *   - null-org actor ⇒ false (fail-closed, matches the engine convention).
     *   - siblings are isolated by the ancestor walk (one-directional).
     *
     * This is a TWO-PATH grant: the actor must satisfy BOTH the module cap
     * and the cluster_tree primitive. Either alone returns false. CFA-00
     * stop conditions apply unchanged (no respondent PII leaks, no raw
     * response widening, no write widening).
     */
    public function viewStats(User $user, Survey $survey): bool
    {
        // super_admin already short-circuited in before(); defensive belt-and-braces
        // in case the policy is invoked without the engine.
        if ($user->isSuperAdmin()) {
            return true;
        }

        // null-org actor ⇒ fail-closed. Engine convention.
        if ($user->organization_id === null || $survey->organization_id === null) {
            return false;
        }

        // Path 1: same-org SURVEYS_VIEW via engine strict equality + scoped role.
        if (AccessDecision::can($user, Capability::SURVEYS_VIEW, $survey)) {
            return true;
        }

        // Path 2: cross-org cluster_tree widening — both grants required on actor.org.
        if (! AccessDecision::can($user, Capability::SURVEYS_VIEW)) {
            return false;
        }
        if (! AccessDecision::can($user, Capability::CLUSTER_TREE_VIEW)) {
            return false;
        }

        // Engine's rescue branch verifies ancestor walk + non-sensitive target.
        return AccessDecision::can($user, Capability::CLUSTER_TREE_VIEW, $survey);
    }
}
