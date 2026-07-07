<?php

namespace App\Modules\Surveys\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Surveys\Models\Survey;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PublishSurveyRequest - engine-only authz for the publish transition.
 *
 * authorize() runs SURVEYS_EDIT against the resolved survey through the
 * engine. Lifecycle state checks (canPublish, fields present) stay in the
 * controller — they are state checks, not AuthZ.
 */
class PublishSurveyRequest extends FormRequest
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
        return [];
    }

    public function getSurvey(): ?Survey
    {
        return $this->survey;
    }
}
