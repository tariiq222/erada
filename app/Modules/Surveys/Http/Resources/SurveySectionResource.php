<?php

namespace App\Modules\Surveys\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SurveySectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'survey_id' => $this->survey_id,
            'title' => $this->title,
            'description' => $this->description,
            'order' => $this->order,
            'is_visible' => $this->is_visible,
            'visibility_rules' => $this->visibility_rules,

            'fields' => SurveyFieldResource::collection($this->whenLoaded('fields')),
            'fields_count' => $this->whenCounted('fields'),

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
