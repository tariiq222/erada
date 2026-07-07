<?php

namespace App\Modules\Surveys\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Surveys\Models\Survey;
use Illuminate\Foundation\Http\FormRequest;

/**
 * SurveyAnalyticsRequest - engine-only authz for the analytics rollup.
 * Surfaces SURVEYS_REVIEW_RESPONSES on the resolved survey (responses
 * analytics is a richer operation than mere viewing).
 */
class SurveyAnalyticsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        $survey = $this->route('survey');

        if (! $survey instanceof Survey) {
            $survey = Survey::find($survey);
        }

        if (! $survey) {
            return true;
        }

        return AccessDecision::can($user, Capability::SURVEYS_REVIEW_RESPONSES, $survey);
    }

    public function rules(): array
    {
        return [];
    }
}
