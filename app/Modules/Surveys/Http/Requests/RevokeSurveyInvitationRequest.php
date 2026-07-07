<?php

namespace App\Modules\Surveys\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Surveys\Models\Survey;
use App\Modules\Surveys\Models\SurveyInvitation;
use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * RevokeSurveyInvitationRequest - engine-only authz for revoking an
 * invitation. Surfaces SURVEYS_EDIT on the survey; the
 * invitation-belongs-to-survey check is a 404 state error.
 */
class RevokeSurveyInvitationRequest extends FormRequest
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

        if (! AccessDecision::can($user, Capability::SURVEYS_EDIT, $survey)) {
            return false;
        }

        $invitation = $this->route('invitation');
        if (! $invitation instanceof SurveyInvitation) {
            return true;
        }

        if ($invitation->survey_id !== $survey->id) {
            throw new NotFoundHttpException('الدعوة غير موجودة في هذا الاستبيان');
        }

        return true;
    }

    public function rules(): array
    {
        return [];
    }
}
