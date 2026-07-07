<?php

namespace App\Modules\RiskManagement\Http\Resources;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Shared\Support\ElementAbilities;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RiskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'organization_id' => $this->organization_id,
            'title' => $this->title,
            'discovery_date' => $this->discovery_date?->toDateString(),
            'type' => $this->type?->value,
            'type_label' => $this->type?->label(),
            'department_id' => $this->department_id,
            'description' => $this->description,
            'consequences' => $this->consequences,
            'initial_likelihood' => $this->initial_likelihood,
            'initial_impact' => $this->initial_impact,
            'current_likelihood' => $this->current_likelihood,
            'current_impact' => $this->current_impact,
            'current_score' => $this->current_score,
            'current_level' => $this->current_level,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'response_type' => $this->response_type?->value,
            'response_type_label' => $this->response_type?->label(),
            'owner_id' => $this->owner_id,
            'stakeholder_ids' => $this->stakeholder_ids,
            'preventive_measures' => $this->preventive_measures,
            'target_close_date' => $this->target_close_date?->toDateString(),
            'riskable_type' => $this->riskable_type,
            'riskable_id' => $this->riskable_id,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
            // Relations
            'department' => $this->whenLoaded('department', fn () => [
                'id' => $this->department->id,
                'name' => $this->department->name,
            ]),
            'owner' => $this->whenLoaded('owner', fn () => [
                'id' => $this->owner->id,
                'name' => $this->owner->name,
            ]),
            'creator' => $this->whenLoaded('creator', fn () => [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ]),
            'riskable' => $this->whenLoaded('riskable', fn () => [
                'id' => $this->riskable->id ?? null,
                'type' => $this->riskable_type,
                'label' => $this->riskable?->name ?? $this->riskable?->title ?? null,
            ]),
            'latest_assessment' => $this->whenLoaded('assessments', function () use ($request) {
                $latest = $this->assessments->first();

                return $latest ? (new RiskAssessmentResource($latest))->resolve($request) : null;
            }),
            'actions_count' => $this->whenCounted('actions'),
            'open_actions_count' => $this->when(
                $this->relationLoaded('actions'),
                fn () => $this->openActionsCount()
            ),

            // Per-record abilities — resolved through AccessDecision so the
            // frontend never re-derives scope-chain logic.
            'abilities' => ElementAbilities::resolve(
                $request?->user() ?? request()->user(),
                $this->resource,
                [
                    'view' => Capability::RISKS_VIEW,
                    'edit' => Capability::RISKS_EDIT,
                    'delete' => Capability::RISKS_DELETE,
                    'reassess' => Capability::RISKS_REASSESS,
                    'change_status' => Capability::RISKS_CHANGE_STATUS,
                ]
            ),
        ];
    }
}
