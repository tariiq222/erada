<?php

namespace App\Modules\Surveys\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Surveys\Models\DataMappingTemplate;
use App\Modules\Surveys\Models\Survey;
use Illuminate\Foundation\Http\FormRequest;

/**
 * DestroyDataMappingRequest - التحقق من صلاحية حذف قالب ربط بيانات.
 *
 * authz (engine-only): المستخدم يجب أن يمتلك القدرة SURVEYS_EDIT على الاستبيان
 * المستهدف (المحرّك يعالج super_admin + عزل المؤسسة).
 *
 * قواعد سلامة المجال (انتماء القالب + عدم وجود طلبات استيراد معلقة) تبقى في
 * withValidator لأنها ليست قرارات AuthZ.
 */
class DestroyDataMappingRequest extends FormRequest
{
    protected ?Survey $survey = null;

    protected ?DataMappingTemplate $template = null;

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

            $template = $this->route('template');

            if (! $template instanceof DataMappingTemplate) {
                return;
            }

            $this->template = $template;

            if ((int) $template->survey_id !== (int) $this->survey->id) {
                abort(404, 'القالب غير موجود في هذا الاستبيان');
            }

            if ($template->importRequests()
                ->whereIn('status', ['pending', 'approved'])
                ->exists()) {
                $validator->errors()->add(
                    'template',
                    'لا يمكن حذف القالب وهناك طلبات استيراد معلقة'
                );
            }
        });
    }

    public function getSurvey(): ?Survey
    {
        return $this->survey;
    }

    public function getTemplate(): ?DataMappingTemplate
    {
        return $this->template;
    }
}
