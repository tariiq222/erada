<?php

namespace App\Modules\Surveys\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resource للعرض العام (بدون بيانات حساسة)
 */
class SurveyPublicResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // التعامل مع type سواء كان enum أو string
        $surveyType = $this->type;
        if ($surveyType instanceof \BackedEnum) {
            $surveyType = $surveyType->value;
        }

        return [
            'code' => $this->code,
            'title' => $this->title,
            'description' => $this->description,
            'type' => $surveyType,

            'consent_text' => $this->consent_text,
            'consent_required' => $this->consent_required,
            'welcome_message' => $this->welcome_message,

            'allow_multiple_responses' => $this->allow_multiple_responses,

            'sections' => $this->sections->map(fn ($section) => [
                'id' => $section->id,
                'title' => $section->title,
                'description' => $section->description,
                'order' => $section->order,
                'is_visible' => $section->is_visible,
                'visibility_rules' => $section->visibility_rules,
                'fields' => $section->fields->map(fn ($field) => $this->formatField($field)),
            ]),

            // جميع الحقول (سواء كانت في قسم أو بدون قسم)
            'fields' => $this->fields
                ->sortBy('order')
                ->values()
                ->map(fn ($field) => $this->formatField($field)),
        ];
    }

    protected function formatField($field): array
    {
        // التعامل مع type سواء كان enum أو string
        $type = $field->type;
        if ($type instanceof \BackedEnum) {
            $type = $type->value;
        }

        return [
            'id' => $field->id,
            'field_key' => $field->field_key,
            'label' => $field->label,
            'description' => $field->description,
            'type' => $type,
            'config' => $this->sanitizeConfig($field->config),
            'is_required' => $field->is_required,
            'order' => $field->order,
            'is_visible' => $field->is_visible,
            'visibility_rules' => $field->visibility_rules,
        ];
    }

    protected function sanitizeConfig(mixed $config): array
    {
        if (! $config) {
            return [];
        }

        // إذا كان string، نحاول تحويله لـ array
        if (is_string($config)) {
            $config = json_decode($config, true) ?? [];
        }

        if (! is_array($config)) {
            return [];
        }

        // إزالة البيانات الحساسة من الـ config
        $sanitized = $config;
        unset($sanitized['security_sensitive']);

        return $sanitized;
    }
}
