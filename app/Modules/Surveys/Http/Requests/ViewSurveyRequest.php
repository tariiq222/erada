<?php

namespace App\Modules\Surveys\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Surveys\Models\Survey;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ViewSurveyRequest - engine-only authz for reading a single survey.
 *
 * authorize() runs SURVEYS_VIEW against the resolved survey through the
 * engine; the engine handles super_admin bypass + organization isolation
 * (Survey is ScopeAware).
 */
class ViewSurveyRequest extends FormRequest
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

        // ponytail: null → let route model binding produce the 404.
        if (! $survey) {
            return true;
        }

        $this->survey = $survey;

        return AccessDecision::can($user, Capability::SURVEYS_VIEW, $survey);
    }

    public function rules(): array
    {
        return [];
    }

    public function getSurvey(): ?Survey
    {
        return $this->survey;
    }
}
