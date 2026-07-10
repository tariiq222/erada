<?php

namespace App\Modules\RiskManagement\Http\Resources;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RiskActionUpdateResource extends JsonResource
{
    /**
     * Phase CFA-05 — Sanitized response for cluster-read actors.
     *
     * When the actor is reading an action update cross-org via
     * CLUSTER_TREE_VIEW rescue (actor.org != update.organization_id AND
     * actor holds CLUSTER_TREE_VIEW), the narrative notes are STRIPPED.
     * Numeric progress / status surfaces are preserved for cluster-monitoring.
     *
     * The update's parent RiskAction (ScopeAware::scopeParent -> Risk ->
     * organization_id) drives the org check; the engine's rescue branch
     * evaluates the ancestor walk.
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
            'risk_action_id' => $this->risk_action_id,
            'user_id' => $this->user_id,
            'progress_pct' => $this->progress_pct,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),

            // Narrative update notes stripped under cluster-read.
            'notes' => $isClusterRead ? null : $this->notes,

            'created_at' => $this->created_at?->toDateTimeString(),
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ]),
        ];
    }
}
