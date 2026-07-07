<?php

namespace App\Modules\Surveys\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Surveys\Models\Survey;
use App\Modules\Surveys\Models\SurveyField;
use Illuminate\Foundation\Http\FormRequest;

/**
 * DestroySurveyFieldRequest - التحقق من صلاحية حذف حقل استبيان.
 *
 * authz (engine-only): المستخدم يجب أن يمتلك القدرة SURVEYS_EDIT على الاستبيان
 * المستهدف (المحرّك يعالج super_admin + عزل المؤسسة).
 */
class DestroySurveyFieldRequest extends FormRequest
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
