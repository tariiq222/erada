<?php

namespace App\Modules\Surveys\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Surveys\Models\Survey;
use Illuminate\Foundation\Http\FormRequest;

/**
 * CreateNewRevisionRequest - engine-only authz for cloning a survey into
 * a new revision. Surfaces SURVEYS_CREATE against the source survey
 * (engine resolves org isolation from the source target).
 */
class CreateNewRevisionRequest extends FormRequest
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

        return AccessDecision::can($user, Capability::SURVEYS_CREATE, $survey);
    }

    public function rules(): array
    {
        return [];
    }
}
