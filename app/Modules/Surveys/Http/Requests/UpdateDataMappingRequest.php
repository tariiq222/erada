<?php

namespace App\Modules\Surveys\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Surveys\Enums\ConflictPolicy;
use App\Modules\Surveys\Enums\DataTransform;
use App\Modules\Surveys\Enums\InsertPolicy;
use App\Modules\Surveys\Models\DataMappingTemplate;
use App\Modules\Surveys\Models\Survey;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * UpdateDataMappingRequest - التحقق من صلاحية تحديث قالب ربط بيانات لاستبيان.
 *
 * authz (engine-only): المستخدم يجب أن يمتلك القدرة SURVEYS_EDIT على الاستبيان
 * المستهدف (المحرّك يعالج super_admin + عزل المؤسسة لأن Survey تطبّق ScopeAware).
 *
 * التحقق من انتماء القالب إلى الاستبيان (`template.survey_id === survey.id`)
 * وفحص القوائم المسموحة للأعمدة يبقى في withValidator — ليس قرار AuthZ.
 */
class UpdateDataMappingRequest extends FormRequest
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
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'target_model' => [
                'sometimes',
                'string',
                Rule::in(array_keys(DataMappingTemplate::getAvailableTargetModels())),
            ],
            'mappings' => ['sometimes', 'array'],
            'mappings.*.column' => ['required', 'string'],
            'mappings.*.transforms' => ['nullable', 'array'],
            'mappings.*.transforms.*' => [
                'string',
                Rule::in(array_column(DataTransform::cases(), 'value')),
            ],
            'mappings.*.required' => ['boolean'],
            'mappings.*.upsert_key' => ['boolean'],
            'insert_policy' => ['sometimes', Rule::enum(InsertPolicy::class)],
            'conflict_policy' => ['sometimes', Rule::enum(ConflictPolicy::class)],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * Resolve the bound template and enforce: (a) the template belongs to the
     * route-bound survey (404 when not) and (b) the resulting `mappings` columns
     * are allowed against the (possibly new) target_model.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->survey === null || $validator->errors()->isNotEmpty()) {
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

            $hasMappings = $this->has('mappings');
            $hasTarget = $this->has('target_model');

            if (! $hasMappings && ! $hasTarget) {
                return;
            }

            $targetModel = $this->input('target_model', $template->target_model);
            $mappings = $this->input('mappings', $template->mappings ?? []);

            if (! is_array($mappings) || ! is_string($targetModel)) {
                return;
            }

            foreach ($mappings as $field => $mapping) {
                $column = is_array($mapping) ? ($mapping['column'] ?? '') : '';

                if (! DataMappingTemplate::isAllowedColumn($targetModel, (string) $column)) {
                    $validator->errors()->add(
                        "mappings.{$field}.column",
                        'العمود المحدد غير مسموح لهذا الهدف.'
                    );
                }
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
