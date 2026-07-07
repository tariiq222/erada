<?php

namespace App\Modules\Surveys\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Surveys\Models\Survey;
use Illuminate\Foundation\Http\FormRequest;

/**
 * CloseSurveyRequest - engine-only authz for the close transition.
 *
 * authorize() runs SURVEYS_EDIT against the resolved survey. Lifecycle
 * (canClose) stays in the controller; the optional `reason` field is
 * validated here.
 */
class CloseSurveyRequest extends FormRequest
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

        return AccessDecision::can($user, Capability::SURVEYS_EDIT, $survey);
    }

    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function getSurvey(): ?Survey
    {
        return $this->survey;
    }
}
