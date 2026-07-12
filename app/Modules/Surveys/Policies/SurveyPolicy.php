<?php

namespace App\Modules\Surveys\Policies;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\User;
use App\Modules\Surveys\Models\Survey;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * SurveyPolicy — Phase 6 (Surveys Org-Isolation).
 *
 * Engine-only authorization: every gate is decided by AccessDecision::can()
 * against the canonical Capability::SURVEYS_* constant. Record-scoped checks
 * add a same-organization floor so a user from org A cannot reach a
 * survey that lives in org B even if their scoped roles grant the
 * underlying capability.
 *
 * Mirrors TaskPolicy (Phase 5) and SurveyResponsePolicy (the existing
 * Surveys module precedent): the super_admin short-circuit lives in
 * before(); record-bearing methods apply the engine check + manual
 * organization_id equality.
 */
class SurveyPolicy
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
     * List gate: engine grants surveys.view AND the user has a non-null
     * organization_id. A user without an organization cannot be org-scoped
     * and therefore cannot list surveys (no tenancy anchor).
     */
    public function viewAny(User $user): bool
    {
        if ($user->organization_id === null) {
            return false;
        }

        return AccessDecision::can($user, Capability::SURVEYS_VIEW);
    }

    /**
     * Show gate: engine grants surveys.view AND the survey's organization
     * matches the user's. For non-super_admin users, a null on either
     * side fails closed (no tenancy anchor on either end).
     */
    public function view(User $user, Survey $survey): bool
    {
        if (! AccessDecision::can($user, Capability::SURVEYS_VIEW)) {
            return false;
        }

        if ($user->organization_id === null || $survey->organization_id === null) {
            return false;
        }

        return (int) $user->organization_id === (int) $survey->organization_id;
    }

    /**
     * Create gate: engine grants surveys.create. No record to scope
     * against; the form-request layer supplies the organization_id for
     * the new row.
     */
    public function create(User $user): bool
    {
        return AccessDecision::can($user, Capability::SURVEYS_CREATE);
    }

    /**
     * Update gate: engine grants surveys.edit AND the survey lives in
     * the user's organization. Same null-floor as view().
     */
    public function update(User $user, Survey $survey): bool
    {
        if (! AccessDecision::can($user, Capability::SURVEYS_EDIT)) {
            return false;
        }

        if ($user->organization_id === null || $survey->organization_id === null) {
            return false;
        }

        return (int) $user->organization_id === (int) $survey->organization_id;
    }

    /**
     * Delete gate: engine grants surveys.delete AND the survey lives in
     * the user's organization. Same null-floor as view().
     */
    public function delete(User $user, Survey $survey): bool
    {
        if (! AccessDecision::can($user, Capability::SURVEYS_DELETE)) {
            return false;
        }

        if ($user->organization_id === null || $survey->organization_id === null) {
            return false;
        }

        return (int) $user->organization_id === (int) $survey->organization_id;
    }

    /**
     * Review-responses gate: engine grants surveys.review_responses AND
     * the survey lives in the user's organization. Same null-floor as
     * view(). This is the per-Survey equivalent of
     * SurveyResponsePolicy::review (which scopes by response->survey org).
     */
    public function review(User $user, Survey $survey): bool
    {
        if (! AccessDecision::can($user, Capability::SURVEYS_REVIEW_RESPONSES)) {
            return false;
        }

        if ($user->organization_id === null || $survey->organization_id === null) {
            return false;
        }

        return (int) $user->organization_id === (int) $survey->organization_id;
    }
}
