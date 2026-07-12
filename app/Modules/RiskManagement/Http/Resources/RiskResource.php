<?php

namespace App\Modules\RiskManagement\Http\Resources;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Shared\Support\ElementAbilities;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RiskResource extends JsonResource
{
    /**
     * Phase CFA-05 — Sanitized show for cluster actors.
     *
     * When the actor is reading a risk cross-org via CLUSTER_TREE_VIEW
     * rescue (actor.org != risk.organization_id AND actor holds
     * CLUSTER_TREE_VIEW), the heavy / PII surfaces are STRIPPED:
     *   - description (free-form org-confidential notes)
     *   - consequences (response narrative — org-confidential)
     *   - preventive_measures (response narrative — org-confidential)
     *   - stakeholder_ids (cross-org user identifiers)
     *   - riskable (cross-morph pointer — project / portfolio etc.)
     *   - latest_assessment (rolled-up assessment data)
     *
     * Preserved for cluster actors:
     *   - id, code, organization_id, title
     *   - discovery_date, type / type_label, department_id
     *   - initial_ / current_ likelihood / impact / score / level
     *   - status / status_label, response_type / response_type_label
     *   - owner_id, target_close_date
     *   - created_by, created_at, updated_at
     *   - department / owner / creator (id + name only — no email / phone)
     *   - abilities (per-record — cluster rescue grants view=true;
     *     all writes forced to false)
     *
     * The sanitization is enforced at the Resource layer; the controller
     * does NOT pre-filter relations, so a cluster actor's `Risk::find()`
     * still loads everything, but the response shape is sanitized.
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
            'code' => $this->code,
            'organization_id' => $this->organization_id,
            'title' => $this->title,
            'discovery_date' => $this->discovery_date?->toDateString(),
            'type' => $this->type?->value,
            'type_label' => $this->type?->label(),
            'department_id' => $this->department_id,

            // PII / org-confidential narrative fields stripped under cluster-read.
            'description' => $isClusterRead ? null : $this->description,
            'consequences' => $isClusterRead ? null : $this->consequences,
            'preventive_measures' => $isClusterRead ? null : $this->preventive_measures,

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

            // Cross-org user identifiers stripped under cluster-read.
            'stakeholder_ids' => $isClusterRead ? null : $this->stakeholder_ids,

            'target_close_date' => $this->target_close_date?->toDateString(),

            // Cross-morph pointer stripped under cluster-read (target's
            // cross-org structure is not the cluster actor's concern).
            'riskable_type' => $isClusterRead ? null : $this->riskable_type,
            'riskable_id' => $isClusterRead ? null : $this->riskable_id,

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

            // Latest assessment surfaces cross-org risk register context;
            // strip under cluster-read. The cluster rescue always grants
            // read access; downstream assessments are accessed via dedicated
            // cross-org controllers when needed.
            'latest_assessment' => $isClusterRead ? null : $this->whenLoaded('assessments', function () use ($request) {
                $latest = $this->assessments->first();

                return $latest ? (new RiskAssessmentResource($latest))->resolve($request) : null;
            }),

            'actions_count' => $this->whenCounted('actions'),
            'open_actions_count' => $this->when(
                $this->relationLoaded('actions'),
                fn () => $this->openActionsCount()
            ),

            // Per-record abilities — resolved through AccessDecision so the
            // frontend never re-derives scope-chain logic. When toArray() is
            // invoked outside an HTTP context (e.g. controller calls
            // ->resolve() directly in store/update), fall back to the active
            // request user so abilities still compute.
            //
            'abilities' => ElementAbilities::resolve(
                $user ?? request()->user(),
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
