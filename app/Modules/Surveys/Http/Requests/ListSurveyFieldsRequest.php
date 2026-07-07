<?php

namespace App\Modules\Surveys\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Surveys\Models\Survey;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ListSurveyFieldsRequest - engine-only authz for reading a survey's
 * fields. Surfaces SURVEYS_VIEW on the survey.
 */
class ListSurveyFieldsRequest extends FormRequest
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

        return AccessDecision::can($user, Capability::SURVEYS_VIEW, $survey);
    }

    public function rules(): array
    {
        return [];
    }
}
