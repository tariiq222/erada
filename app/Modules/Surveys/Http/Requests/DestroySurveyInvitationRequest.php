<?php

namespace App\Modules\Surveys\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Surveys\Enums\InvitationStatus;
use App\Modules\Surveys\Models\Survey;
use App\Modules\Surveys\Models\SurveyInvitation;
use Illuminate\Foundation\Http\FormRequest;

/**
 * DestroySurveyInvitationRequest - التحقق من صلاحية حذف دعوة استبيان.
 *
 * authz (engine-only): المستخدم يجب أن يمتلك القدرة SURVEYS_EDIT على الاستبيان
 * المستهدف (المحرّك يعالج super_admin + عزل المؤسسة).
 *
 * قواعد المجال (انتماء الدعوة للاستبيان + عدم إمكانية حذف دعوة مستخدمة) تبقى في
 * withValidator لأنها ليست قرارات AuthZ.
 */
class DestroySurveyInvitationRequest extends FormRequest
{
    protected ?Survey $survey = null;

    protected ?SurveyInvitation $invitation = null;

    public function authorize(): bool
    {
        $survey = $this->route('survey');

        if (! $survey instanceof Survey) {
            $survey = Survey::find($survey);
        }

        if (! $survey) {
            return false;
        }

        $this->survey = $survey;

        $user = $this->user();

        return $user !== null
            && AccessDecision::can($user, Capability::SURVEYS_EDIT, $survey);
    }

    public function rules(): array
    {
        return [];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->survey === null) {
                return;
            }

            $invitation = $this->route('invitation');

            if (! $invitation instanceof SurveyInvitation) {
                return;
            }

            $this->invitation = $invitation;

            if ((int) $invitation->survey_id !== (int) $this->survey->id) {
                abort(404, 'الدعوة غير موجودة في هذا الاستبيان');
            }

            if ($invitation->status === InvitationStatus::Used) {
                abort(403, 'لا يمكن حذف دعوة مستخدمة');
            }
        });
    }

    public function getSurvey(): ?Survey
    {
        return $this->survey;
    }

    public function getInvitation(): ?SurveyInvitation
    {
        return $this->invitation;
    }
}
