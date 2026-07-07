<?php

namespace App\Modules\Surveys\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SurveyFieldResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'survey_id' => $this->survey_id,
            'section_id' => $this->section_id,

            'field_key' => $this->field_key,
            'name' => $this->name,
            'label' => $this->label,
            'description' => $this->description,

            'type' => $this->type?->value,
            'type_label' => $this->type?->label(),
            'type_icon' => $this->type?->icon(),

            'config' => $this->config,
            'options' => $this->getOptions(),

            'is_required' => $this->is_required,
            'order' => $this->order,
            'is_visible' => $this->is_visible,
            'visibility_rules' => $this->visibility_rules,

            // معلومات إضافية
            'has_options' => $this->type?->hasOptions(),
            'has_matrix_config' => $this->type?->hasMatrixConfig(),
            'is_display_only' => $this->type?->isDisplayOnly(),

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
