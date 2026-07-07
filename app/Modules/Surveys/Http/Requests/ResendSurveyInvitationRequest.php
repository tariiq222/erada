<?php

namespace App\Modules\Surveys\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Surveys\Models\Survey;
use App\Modules\Surveys\Models\SurveyInvitation;
use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * ResendSurveyInvitationRequest - engine-only authz for re-sending an
 * existing invitation.
 *
 * Cross-row checks (invitation belongs to this survey, invitation is
 * usable) are state errors and surface as 404 / 403 directly from
 * authorize() / runtime checks.
 */
class ResendSurveyInvitationRequest extends FormRequest
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

        if (! $invitation->canUse()) {
            throw new AccessDeniedHttpException('لا يمكن إعادة إرسال هذه الدعوة');
        }

        return true;
    }

    public function rules(): array
    {
        return [];
    }
}
