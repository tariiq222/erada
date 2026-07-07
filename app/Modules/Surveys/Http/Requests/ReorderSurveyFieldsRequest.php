<?php

namespace App\Modules\Surveys\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Surveys\Models\Survey;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ReorderSurveyFieldsRequest - engine-only authz + payload validation for
 * reordering a survey's fields.
 *
 * The lifecycle guard (canEdit on the survey — refuses reorder when the
 * survey is published/locked) is enforced via abort(403) from authorize()
 * because the gate is a state error, not a field-validation error.
 */
class ReorderSurveyFieldsRequest extends FormRequest
{
    protected ?Survey $survey = null;

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

        $this->survey = $survey;

        if (! AccessDecision::can($user, Capability::SURVEYS_EDIT, $survey)) {
            return false;
        }

        if (! $survey->canEdit()) {
            abort(403, 'لا يمكن تعديل الاستبيان بعد نشره');
        }

        return true;
    }

    public function rules(): array
    {
        return [
            'fields' => ['required', 'array'],
            'fields.*' => ['integer', 'exists:survey_fields,id'],
        ];
    }

    public function getSurvey(): ?Survey
    {
        return $this->survey;
    }
}
