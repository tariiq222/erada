<?php

namespace App\Modules\Surveys\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Surveys\Models\Survey;
use Illuminate\Foundation\Http\FormRequest;

/**
 * DestroySurveyRequest - التحقق من صلاحية حذف استبيان.
 *
 * authz (engine-only): المستخدم يجب أن يمتلك القدرة SURVEYS_DELETE على الاستبيان
 * المستهدف (المحرّك يعالج super_admin + عزل المؤسسة).
 *
 * قاعدة المجال ("لا يمكن الحذف عند وجود إجابات") تبقى في withValidator لأنها
 * قيد سلامة بيانات وليس قرار AuthZ.
 */
class DestroySurveyRequest extends FormRequest
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
            && AccessDecision::can($user, Capability::SURVEYS_DELETE, $survey);
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

            if ($this->survey->responses()->exists()) {
                $validator->errors()->add(
                    'survey',
                    'لا يمكن حذف استبيان يحتوي على إجابات'
                );
            }
        });
    }

    public function getSurvey(): ?Survey
    {
        return $this->survey;
    }
}
