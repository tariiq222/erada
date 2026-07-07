<?php

namespace App\Modules\Surveys\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Surveys\Models\Survey;
use App\Modules\Surveys\Models\SurveySection;
use Illuminate\Foundation\Http\FormRequest;

/**
 * UpdateSurveySectionRequest - التحقق من صلاحية تحديث قسم استبيان.
 *
 * authz (engine-only): المستخدم يجب أن يمتلك القدرة SURVEYS_EDIT على الاستبيان
 * المستهدف (المحرّك يعالج super_admin + عزل المؤسسة).
 *
 * قواعد دورة الحياة وانتماء القسم تبقى في withValidator — ليست قرارات AuthZ.
 */
class UpdateSurveySectionRequest extends FormRequest
{
    protected ?Survey $survey = null;

    protected ?SurveySection $section = null;

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
            'description' => ['nullable', 'string'],
            'is_visible' => ['boolean'],
            'visibility_rules' => ['nullable', 'array'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->survey === null) {
                return;
            }

            if (! $this->survey->canEdit()) {
                $validator->errors()->add(
                    'survey',
                    'لا يمكن تعديل الاستبيان بعد نشره'
                );

                return;
            }

            $section = $this->route('section');

            if (! $section instanceof SurveySection) {
                return;
            }

            $this->section = $section;

            if ((int) $section->survey_id !== (int) $this->survey->id) {
                $validator->errors()->add(
                    'section',
                    'القسم غير موجود في هذا الاستبيان'
                );
            }
        });
    }

    public function getSurvey(): ?Survey
    {
        return $this->survey;
    }

    public function getSection(): ?SurveySection
    {
        return $this->section;
    }
}
