<?php

namespace App\Modules\RiskManagement\Http\Resources;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RiskActionResource extends JsonResource
{
    /**
     * Phase CFA-05 — Sanitized response for cluster-read actors.
     *
     * When the actor is reading a risk action cross-org via CLUSTER_TREE_VIEW
     * rescue (actor.org != action.organization_id AND actor holds
     * CLUSTER_TREE_VIEW), the narrative surfaces are STRIPPED:
     *   - description (free-form response notes)
     *   - notes (action-level progress notes)
     *
     * The action's parent Risk (ScopeAware::scopeParent) drives the org check;
     * the engine's rescue branch evaluates the ancestor walk on the
     * target action itself (extractOrganizationId returns
     * action.organization_id, which equals the parent's org).
     */
    public function toArray(Request $request): array
    {
        $user = $request?->user();
        $isClusterRead = $user !== null
            && ! $user->isSuperAdmin()
            && $this->resource->exists
            && $this->resource->organization_id !== null
            && (int) $user->organization_id !== (int) $this->resource->organization_id
            && AccessDecision::can($user, Capability::CLUSTER_TREE_VIEW);

        return [
            'id' => $this->id,
            'risk_id' => $this->risk_id,
            'title' => $this->title,
            'type' => $this->type?->value,
            'type_label' => $this->type?->label(),

            // Narrative fields stripped under cluster-read.
            'description' => $isClusterRead ? null : $this->description,

            'owner_id' => $this->owner_id,
            'due_date' => $this->due_date?->toDateString(),
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'progress_pct' => $this->progress_pct,

            // Narrative progress field stripped under cluster-read.
            'notes' => $isClusterRead ? null : $this->notes,

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
