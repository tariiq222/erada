<?php

namespace App\Modules\Surveys\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Surveys\Enums\SurveyType;
use App\Modules\Surveys\Models\Survey;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * UpdateSurveyRequest - التحقق من بيانات وصلاحية تحديث استبيان.
 *
 * authz (engine-only): المستخدم يجب أن يمتلك القدرة SURVEYS_EDIT على الاستبيان
 * المستهدف (المحرّك يعالج super_admin + عزل المؤسسة لأن Survey تطبّق ScopeAware).
 *
 * قاعدة دورة الحياة (`canEdit` — مسودة فقط وغير مقفل) تبقى في withValidator
 * لأنها ليست قرار AuthZ بل شرط حالة مجال.
 */
class UpdateSurveyRequest extends FormRequest
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
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'type' => ['sometimes', Rule::enum(SurveyType::class)],
            'category' => ['nullable', 'string', 'max:50'],

            'is_public' => ['boolean'],
            'requires_auth' => ['boolean'],
            'allow_multiple_responses' => ['boolean'],
            'allow_edit_response' => ['boolean'],
            'accepting_responses' => ['boolean'],

            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],

            'consent_text' => ['nullable', 'string', 'max:5000'],
            'consent_required' => ['boolean'],
            'welcome_message' => ['nullable', 'string', 'max:5000'],
            'thank_you_message' => ['nullable', 'string', 'max:5000'],

            'settings' => ['nullable', 'array'],
        ];
    }

    /**
     * قاعدة دورة الحياة: لا يمكن تعديل الاستبيان بعد نشره أو قفله.
     * فحص حالة المجال — ليس قرار AuthZ — يُطبَّق بعد قواعد التحقق الأساسية.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->survey === null) {
                return;
            }

            if (! $this->survey->canEdit()) {
                $validator->errors()->add(
                    'status',
                    'لا يمكن تعديل الاستبيان بعد نشره'
                );
            }
        });
    }

    public function getSurvey(): ?Survey
    {
        return $this->survey;
    }
}
