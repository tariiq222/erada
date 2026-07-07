<?php

namespace App\Modules\Surveys\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Surveys\Models\Survey;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ReorderSurveySectionsRequest - engine-only authz + payload validation
 * for reordering a survey's sections. Same lifecycle guard as the fields
 * counterpart: abort(403) when the survey is no longer editable.
 */
class ReorderSurveySectionsRequest extends FormRequest
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
            'sections' => ['required', 'array'],
            'sections.*' => ['integer', 'exists:survey_sections,id'],
        ];
    }

    public function getSurvey(): ?Survey
    {
        return $this->survey;
    }
}
