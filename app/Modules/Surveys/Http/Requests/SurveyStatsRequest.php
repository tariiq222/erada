<?php

namespace App\Modules\Surveys\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use Illuminate\Foundation\Http\FormRequest;

/**
 * SurveyStatsRequest - engine-only authz for the surveys stats aggregate.
 * Same gate as ListSurveysRequest (SURVEYS_VIEW).
 */
class SurveyStatsRequest extends FormRequest
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
