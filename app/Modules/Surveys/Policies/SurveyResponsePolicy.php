<?php

namespace App\Modules\Surveys\Policies;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\User;
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
}
