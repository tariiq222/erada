<?php

namespace App\Modules\Surveys\Http\Resources;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Shared\Support\ElementAbilities;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SurveyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'revision' => $this->revision,
            'canonical_id' => $this->canonical_id,

            'title' => $this->title,
            'description' => $this->description,
            'type' => $this->type?->value,
            'type_label' => $this->type?->label(),
            'category' => $this->category,

            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'status_color' => $this->status?->color(),

            'is_public' => $this->is_public,
            'requires_auth' => $this->requires_auth,
            'accepting_responses' => $this->accepting_responses,
            'allow_multiple_responses' => $this->allow_multiple_responses,
            'allow_edit_response' => $this->allow_edit_response,

            'starts_at' => $this->starts_at?->toISOString(),
            'ends_at' => $this->ends_at?->toISOString(),
            'published_at' => $this->published_at?->toISOString(),
            'locked_at' => $this->locked_at?->toISOString(),
            'closed_at' => $this->closed_at?->toISOString(),
            'close_reason' => $this->close_reason,

            'consent_text' => $this->consent_text,
            'consent_required' => $this->consent_required,
            'welcome_message' => $this->welcome_message,
            'thank_you_message' => $this->thank_you_message,

            'settings' => $this->settings,

            // العلاقات
            'creator' => $this->whenLoaded('creator', fn () => [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ]),

            'sections' => SurveySectionResource::collection($this->whenLoaded('sections')),
            'fields' => SurveyFieldResource::collection($this->whenLoaded('fields')),

            'mapping_templates' => $this->whenLoaded('mappingTemplates', fn () => DataMappingTemplateResource::collection($this->mappingTemplates)
            ),

            // العدادات
            'responses_count' => $this->whenCounted('responses'),
            'fields_count' => $this->whenCounted('fields'),

            // حالات وإجراءات
            'is_active' => $this->isActive(),
            'is_locked' => $this->isLocked(),
            'can_edit' => $this->canEdit(),
            'can_publish' => $this->canPublish(),
            'can_close' => $this->canClose(),

            'abilities' => ElementAbilities::resolve(
                $request?->user() ?? request()->user(),
                $this->resource,
                [
                    'view' => Capability::SURVEYS_VIEW,
                    'edit' => Capability::SURVEYS_EDIT,
                    'delete' => Capability::SURVEYS_DELETE,
                ]
            ),

            'public_url' => $this->getPublicUrl(),

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
