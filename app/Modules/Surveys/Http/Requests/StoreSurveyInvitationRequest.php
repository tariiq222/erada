<?php

namespace App\Modules\Surveys\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Surveys\Models\Survey;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * StoreSurveyInvitationRequest - التحقق من صلاحية إنشاء دعوة استبيان.
 *
 * authz (engine-only): المستخدم يجب أن يمتلك القدرة SURVEYS_EDIT على الاستبيان
 * المستهدف (المحرّك يعالج super_admin + عزل المؤسسة).
 */
class StoreSurveyInvitationRequest extends FormRequest
{
    protected ?Survey $survey = null;

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
        return [
            'email' => ['required', 'email'],
            'name' => ['nullable', 'string', 'max:255'],
            'department_id' => [
                'nullable',
                'integer',
                Rule::exists('departments', 'id')->when(
                    ! $this->user()?->isSuperAdmin(),
                    fn ($rule) => $rule->where('organization_id', $this->user()?->organization_id)
                ),
            ],
            'user_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->when(
                    ! $this->user()?->isSuperAdmin(),
                    fn ($rule) => $rule->where('organization_id', $this->user()?->organization_id)
                ),
            ],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function getSurvey(): ?Survey
    {
        return $this->survey;
    }
}
