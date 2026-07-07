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
 * StoreDataMappingRequest - التحقق من صلاحية إنشاء قالب ربط بيانات لاستبيان.
 *
 * authz (engine-only): المستخدم يجب أن يمتلك القدرة SURVEYS_EDIT على الاستبيان
 * المستهدف (المحرّك يعالج super_admin + عزل المؤسسة لأن Survey تطبّق ScopeAware).
 *
 * قواعد القوائم المسموحة (`target_model`، تحويلات الأعمدة) مرتبطة بنموذج
 * DataMappingTemplate وتُحقَّق بعد التحقق الأساسي في withValidator.
 */
class StoreDataMappingRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'target_model' => [
                'required',
                'string',
                Rule::in(array_keys(DataMappingTemplate::getAvailableTargetModels())),
            ],
            'mappings' => ['required', 'array'],
            'mappings.*.column' => ['required', 'string'],
            'mappings.*.transforms' => ['nullable', 'array'],
            'mappings.*.transforms.*' => [
                'string',
                Rule::in(array_column(DataTransform::cases(), 'value')),
            ],
            'mappings.*.required' => ['boolean'],
            'mappings.*.upsert_key' => ['boolean'],
            'insert_policy' => ['required', Rule::enum(InsertPolicy::class)],
            'conflict_policy' => ['required', Rule::enum(ConflictPolicy::class)],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * Allowlist columns against the chosen target model. Mirrors the original
     * controller helper; runs after the base rules so `target_model` and
     * `mappings.*.column` are already validated as strings.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $targetModel = $this->input('target_model');
            $mappings = $this->input('mappings', []);

            if (! is_array($mappings) || $targetModel === null) {
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
}
