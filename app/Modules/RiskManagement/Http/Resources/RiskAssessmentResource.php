<?php

namespace App\Modules\RiskManagement\Http\Resources;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RiskAssessmentResource extends JsonResource
{
    /**
     * Phase CFA-05 — Sanitized response for cluster-read actors.
     *
     * When the actor is reading a risk assessment cross-org via
     * CLUSTER_TREE_VIEW rescue (actor.org != assessment.organization_id AND
     * actor holds CLUSTER_TREE_VIEW), the assessor's narrative notes are
     * STRIPPED. The numeric scoring surfaces (likelihood / impact / score /
     * level) are preserved — they are the cross-org cluster-monitoring
     * primary signal.
     *
     * The assessment's parent Risk (ScopeAware::scopeParent) drives the org
     * check; the engine's rescue branch evaluates the ancestor walk.
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
            'likelihood' => $this->likelihood,
            'impact' => $this->impact,
            'score' => $this->score,
            'level' => $this->level,
            'residual_likelihood' => $this->residual_likelihood,
            'residual_impact' => $this->residual_impact,
            'residual_score' => $this->residual_score,
            'residual_level' => $this->residual_level,
            'assessor_id' => $this->assessor_id,

            // Assessor narrative notes stripped under cluster-read.
            'notes' => $isClusterRead ? null : $this->notes,

            'next_review_at' => $this->next_review_at?->toDateString(),
            'review_due_notified_at' => $this->review_due_notified_at?->toDateTimeString(),
            'created_at' => $this->created_at?->toDateTimeString(),
            // Relations
            'assessor' => $this->whenLoaded('assessor', fn () => [
                'id' => $this->assessor->id,
                'name' => $this->assessor->name,
            ]),
        ];
    }
}
