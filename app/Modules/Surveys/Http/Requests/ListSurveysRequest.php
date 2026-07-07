<?php

namespace App\Modules\Surveys\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ListSurveysRequest - engine-only authz for the surveys index.
 *
 * authorize() runs SURVEYS_VIEW through the engine (no target; capability
 * is evaluated at organization scope). Mirrors the controller's prior
 * super_admin bypass + org-scoping filter.
 */
class ListSurveysRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && AccessDecision::can($user, Capability::SURVEYS_VIEW);
    }

    public function rules(): array
    {
        return [];
    }
}
