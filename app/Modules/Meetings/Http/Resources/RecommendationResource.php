<?php

namespace App\Modules\Meetings\Http\Resources;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Phase CFA-06 — Cluster-read sanitization for Recommendation.
 *
 * When the actor is reading a recommendation cross-org via the
 * CLUSTER_TREE_VIEW rescue (actor.org != recommendation.organization_id
 * AND actor holds CLUSTER_TREE_VIEW), the operational / internal-only
 * surfaces are STRIPPED:
 *   - rationale (decision rationale — internal to originating org)
 *   - defer_reason (defer justification — internal to originating org)
 *   - internal_notes (no column today; sanitizer here so future additions
 *     inherit the redacted shape automatically)
 *
 * Preserved for cluster actors:
 *   - id, reference_number, title, description
 *   - kind, priority, status, type
 *   - meeting (id + title only — preserved)
 *   - assignee (id + name only — preserved)
 *   - requester (id + name only — preserved)
 *   - decisionMaker (id + name only — preserved)
 *   - organization_id, meeting_id (FK pointers)
 *   - due_date, completed_at, decision_date, effective_date
 *   - deferred_until, deferred_at (dates; no PII)
 *
 * The Direction B ruling/action_item lifecycle (approve/reject/defer/
 * accept/complete) is unaffected — these fields describe state, not PII.
 * Direction B integrity is preserved (sanitization only affects the
 * GET shape; transitions still go through RecommendationPolicy).
 */
class RecommendationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request?->user();
        $isClusterRead = $user !== null
            && ! $user->isSuperAdmin()
            && $this->resource->exists
            && (int) $user->organization_id !== (int) $this->resource->scopeOrganizationId()
            && AccessDecision::can($user, Capability::CLUSTER_TREE_VIEW);

        return [
            'id' => $this->id,
            'reference_number' => $this->reference_number,
            'title' => $this->title,
            'description' => $this->description,
            'kind' => $this->kind,
            'type' => $this->type,
            'priority' => $this->priority,
            'status' => $this->status,
            'impact' => $this->impact,
            'organization_id' => $this->organization_id,
            'meeting_id' => $this->meeting_id,

            'meeting' => $this->whenLoaded('meeting', fn () => $this->meeting ? [
                'id' => $this->meeting->id,
                'title' => $this->meeting->title,
            ] : null),

            'assignee' => $this->whenLoaded('assignee', fn () => $this->assignee ? [
                'id' => $this->assignee->id,
                'name' => $this->assignee->name,
            ] : null),

            'requester' => $this->whenLoaded('requester', fn () => $this->requester ? [
                'id' => $this->requester->id,
                'name' => $this->requester->name,
            ] : null),

            'decision_maker' => $this->whenLoaded('decisionMaker', fn () => $this->decisionMaker ? [
                'id' => $this->decisionMaker->id,
                'name' => $this->decisionMaker->name,
            ] : null),

            'requested_by' => $this->requested_by,
            'made_by' => $this->made_by,
            'decision_date' => $this->decision_date?->format('Y-m-d'),
            'effective_date' => $this->effective_date?->format('Y-m-d'),
            'due_date' => $this->due_date?->format('Y-m-d'),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'deferred_until' => $this->deferred_until?->format('Y-m-d'),
            'deferred_at' => $this->deferred_at?->toIso8601String(),

            // Internal-only surfaces — stripped on cluster reads.
            'rationale' => $isClusterRead ? null : $this->rationale,
            'defer_reason' => $isClusterRead ? null : $this->defer_reason,
            'internal_notes' => $isClusterRead ? null : ($this->internal_notes ?? null),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
