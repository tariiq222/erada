<?php

namespace App\Modules\Surveys\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Surveys\Enums\FieldType;
use App\Modules\Surveys\Models\Survey;
use App\Modules\Surveys\Models\SurveyField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * UpdateSurveyFieldRequest - التحقق من صلاحية تحديث حقل استبيان.
 *
 * authz (engine-only): المستخدم يجب أن يمتلك القدرة SURVEYS_EDIT على الاستبيان
 * المستهدف (المحرّك يعالج super_admin + عزل المؤسسة).
 *
 * قواعد دورة الحياة وانتماء الحقل تبقى في withValidator — ليست قرارات AuthZ.
 */
class UpdateSurveyFieldRequest extends FormRequest
{
    protected ?Survey $survey = null;

    protected ?SurveyField $field = null;

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

        $field = $this->route('field');
        if ($field instanceof SurveyField) {
            $this->field = $field;
        }

        $user = $this->user();

        return $user !== null
            && AccessDecision::can($user, Capability::SURVEYS_EDIT, $survey);
    }

    public function rules(): array
    {
        $surveyId = $this->survey?->id;
        $fieldId = $this->field?->id;

        return [
            'section_id' => [
                'nullable',
                'integer',
                Rule::exists('survey_sections', 'id')->where('survey_id', $this->route('survey')->id),
            ],
            'field_key' => [
                'sometimes',
                'string',
                'max:100',
                'alpha_dash',
                $surveyId !== null && $fieldId !== null
                    ? Rule::unique('survey_fields', 'field_key')
                        ->where('survey_id', $surveyId)
                        ->ignore($fieldId)
                    : ($surveyId !== null
                        ? Rule::unique('survey_fields', 'field_key')->where('survey_id', $surveyId)
                        : Rule::unique('survey_fields', 'field_key')),
            ],
            'name' => ['sometimes', 'string', 'max:100'],
            'label' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'type' => ['sometimes', Rule::enum(FieldType::class)],
            'config' => ['nullable', 'array'],
            'is_required' => ['boolean'],
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
                abort(403, 'لا يمكن تعديل الاستبيان بعد نشره');
            }

            $field = $this->route('field');

            if (! $field instanceof SurveyField) {
                return;
            }

            $this->field = $field;

            if ((int) $field->survey_id !== (int) $this->survey->id) {
                abort(404, 'الحقل غير موجود في هذا الاستبيان');
            }
        });
    }

    public function getSurvey(): ?Survey
    {
        return $this->survey;
    }

    public function getField(): ?SurveyField
    {
        return $this->field;
    }
}
