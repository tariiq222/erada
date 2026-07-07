<?php

namespace App\Modules\Surveys\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DataMappingTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'survey_id' => $this->survey_id,

            'name' => $this->name,
            'description' => $this->description,

            'target_model' => $this->target_model,
            'target_model_info' => $this->getTargetModelInfo(),

            'mappings' => $this->mappings,

            'insert_policy' => $this->insert_policy?->value,
            'insert_policy_label' => $this->insert_policy?->label(),

            'conflict_policy' => $this->conflict_policy?->value,
            'conflict_policy_label' => $this->conflict_policy?->label(),

            'is_active' => $this->is_active,

            'creator' => $this->whenLoaded('creator', fn () => [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ]),

            'upsert_key_fields' => $this->getUpsertKeyFields(),
            'required_fields' => $this->getRequiredFields(),

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
