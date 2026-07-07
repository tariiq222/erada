<?php

namespace App\Modules\RiskManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RiskActionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'risk_id' => $this->risk_id,
            'title' => $this->title,
            'type' => $this->type?->value,
            'type_label' => $this->type?->label(),
            'description' => $this->description,
            'owner_id' => $this->owner_id,
            'due_date' => $this->due_date?->toDateString(),
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'progress_pct' => $this->progress_pct,
            'notes' => $this->notes,
            'overdue_notified_at' => $this->overdue_notified_at?->toDateTimeString(),
            'is_overdue' => $this->isOverdue(),
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
            // Relations
            'owner' => $this->whenLoaded('owner', fn () => [
                'id' => $this->owner->id,
                'name' => $this->owner->name,
            ]),
            'updates' => RiskActionUpdateResource::collection($this->whenLoaded('updates')),
            'updates_count' => $this->whenCounted('updates'),
        ];
    }
}
