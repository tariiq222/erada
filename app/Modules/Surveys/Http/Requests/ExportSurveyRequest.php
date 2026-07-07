<?php

namespace App\Modules\Surveys\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Surveys\Models\Survey;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ExportSurveyRequest - engine-only authz + format validation for survey
 * response export. Surfaces SURVEYS_REVIEW_RESPONSES on the survey.
 */
class ExportSurveyRequest extends FormRequest
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
        return [
            'format' => ['nullable', Rule::in(['csv', 'json'])],
            'status' => ['nullable', 'string'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
        ];
    }
}
