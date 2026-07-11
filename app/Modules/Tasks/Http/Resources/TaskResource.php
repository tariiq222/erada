<?php

namespace App\Modules\Tasks\Http\Resources;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Http\Resources\UserResource;
use App\Modules\Shared\Support\ElementAbilities;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request?->user() ?? request()->user();

        // Phase CFA-08 — Cluster-read sanitization.
        //
        // When the actor is reading this task cross-org via CLUSTER_TREE_VIEW
        // rescue (actor.org != task-effective-organization AND actor holds
        // CLUSTER_TREE_VIEW), sensitive payload is STRIPPED:
        //   - description (often freeform narrative — PII / partial-confidential)
        //   - challenges / lessons_learned / status_comment (per-record closure
        //     narrative; can carry names of staff / patients)
        //   - assignee / creator / owner (UserResource collection — exposes
        //     user PII cross-org)
        //   - subtasks (cross-module child task data — widened separately when
        //     the actor revisits with a focused cross-org query)
        //   - comments_count / attachments_count (counts surfaced as metadata
        //     only; the underlying records require a separate authorized read)
        //
        // Preserved for cluster actors:
        //   - id, type, title, status / priority / progress
        //   - start_date / due_date / completed_date (no PII)
        //   - project_id / department_id / milestone_id / parent_id (FK pointers
        //     — no leakage)
        //   - source_type / source_id / source_sensitivity (FK pointers — the
        //     sensitive-source stamp is intentionally preserved so the FE
        //     can render the "this task inherits a confidential source" badge
        //     without fetching the row)
        //   - assigned_to / created_by / owner_id (FK pointers — no names)
        //   - is_private, order, recurrence_rule
        //   - time_indicator / days_* / total_days / time_progress / is_overdue
        //   - abilities (per-record — needed for UI; engine-resolved)
        //
        // super_admin bypasses sanitization. The cluster detection fails closed
        // (no actor / null actor / same-org ⇒ not cluster-read).
        $isClusterRead = $user !== null
            && ! $user->isSuperAdmin()
            && $this->resource->exists
            && $user->organization_id !== null
            && $this->resource->scopeOrganizationId() !== null
            && (int) $user->organization_id !== (int) $this->resource->scopeOrganizationId()
            && AccessDecision::can($user, Capability::CLUSTER_TREE_VIEW);

        return [
            'id' => $this->id,
            'type' => $this->type?->value ?? $this->type,
            'type_label' => $this->type?->label() ?? null,
            'title' => $this->title,
            'description' => $isClusterRead ? null : $this->description,
            'status' => $this->status?->value ?? $this->status,
            'status_label' => $this->status?->label() ?? null,
            'status_color' => $this->status?->color() ?? null,
            'priority' => $this->priority?->value ?? $this->priority,
            'priority_label' => $this->priority?->label() ?? null,
            'priority_color' => $this->priority?->color() ?? null,
            'progress' => $this->progress,

            // التواريخ
            'start_date' => $this->start_date?->format('Y-m-d'),
            'due_date' => $this->due_date?->format('Y-m-d'),
            'completed_date' => $this->completed_date?->format('Y-m-d'),

            // الوقت
            'estimated_hours' => $this->estimated_hours,
            'actual_hours' => $this->actual_hours,

            // مؤشرات الوقت
            'time_indicator' => $this->time_indicator,
            'days_remaining' => $this->days_remaining,
            'days_elapsed' => $this->days_elapsed,
            'total_days' => $this->total_days,
            'time_progress' => $this->time_progress,
            'is_overdue' => $this->isOverdue(),

            // خصائص إضافية
            'is_private' => $this->is_private,
            'order' => $this->order,
            'recurrence_rule' => $this->recurrence_rule,

            // حقول الإكمال — strip on cluster read (per-row closure narrative
            // can carry sensitive surfaces like staff / patient names).
            'challenges' => $isClusterRead ? null : $this->challenges,
            'lessons_learned' => $isClusterRead ? null : $this->lessons_learned,
            'status_comment' => $isClusterRead ? null : $this->status_comment,

            // العلاقات — FK pointers are kept on cluster cross-org
            // (stable routing keys), but the labeled sub-fields are
            // stripped (Phase 2B). A cluster actor must not see
            // project / department / milestone names or parent
            // titles even though they have access to the task row at
            // all. On cluster read the blocks fall through to id-only;
            // same-org reads get the full subresource.
            'project_id' => $this->project_id,
            'project' => $this->whenLoaded('project', fn () => $isClusterRead
                ? ['id' => $this->project->id]
                : [
                    'id' => $this->project->id,
                    'name' => $this->project->name,
                    'code' => $this->project->code,
                    'type' => $this->project->type,
                ]),

            'department_id' => $this->department_id,
            'department' => $this->whenLoaded('department', fn () => $isClusterRead
                ? ['id' => $this->department->id]
                : [
                    'id' => $this->department->id,
                    'name' => $this->department->name,
                ]),

            'milestone_id' => $this->milestone_id,
            'milestone' => $this->whenLoaded('milestone', fn () => $isClusterRead
                ? ['id' => $this->milestone->id]
                : [
                    'id' => $this->milestone->id,
                    'name' => $this->milestone->name,
                ]),

            'parent_id' => $this->parent_id,
            'parent' => $this->whenLoaded('parent', fn () => $isClusterRead
                ? ['id' => $this->parent->id]
                : [
                    'id' => $this->parent->id,
                    'title' => $this->parent->title,
                ]),

            'assigned_to' => $this->assigned_to,
            'assignee' => $isClusterRead
                ? null
                : $this->whenLoaded('assignee', fn () => new UserResource($this->assignee)),

            'created_by' => $this->created_by,
            'creator' => $isClusterRead
                ? null
                : $this->whenLoaded('creator', fn () => new UserResource($this->creator)),

            'owner_id' => $this->owner_id,
            'owner' => $isClusterRead
                ? null
                : $this->whenLoaded('owner', fn () => new UserResource($this->owner)),

            // Phase 4 polymorphic source — surfaced as FK pointers so the FE
            // can resolve the parent record via its own scoped API. The
            // source_sensitivity stamp is preserved (read-only metadata for
            // a "this task inherits a confidential source" badge); the row
            // itself is unreachable to cluster actors per the engine's
            // sensitive gate.
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'source_sensitivity' => $this->source_sensitivity,

            // المهام الفرعية — array-of-records stripped on cluster read;
            // counts (subtasks_count / has_subtasks / incomplete_subtasks_count)
            // are also zeroed since they encode module business surface.
            'subtasks' => $isClusterRead
                ? null
                : $this->whenLoaded('subtasks', fn () => TaskResource::collection($this->subtasks)),
            'subtasks_count' => $isClusterRead ? 0 : $this->relationCount('subtasks'),
            'has_subtasks' => $isClusterRead ? false : $this->relationCount('subtasks') > 0,
            'incomplete_subtasks_count' => $isClusterRead
                ? 0
                : $this->relationCount('incomplete_subtasks', 'subtasks', fn () => $this->subtasks()
                    ->whereNotIn('status', ['completed', 'cancelled'])
                    ->count()),

            // Phase 2B — comments and attachments counts are also zeroed
            // on cluster cross-org. The records themselves require a
            // separate authorized read so the array-of-records surfaces
            // are already null; we extend that to the count metadata so
            // the cluster actor cannot even infer collaboration density
            // from numeric hints.
            'comments_count' => $isClusterRead ? 0 : $this->relationCount('comments'),
            'attachments_count' => $isClusterRead ? 0 : $this->relationCount('attachments'),

            // Per-record abilities — resolved through AccessDecision so the
            // frontend never re-derives scope-chain logic. Fall back to the
            // active request user when toArray() is invoked without a Request.
            'abilities' => ElementAbilities::resolve(
                $user,
                $this->resource,
                [
                    'view' => Capability::TASKS_VIEW,
                    'edit' => Capability::TASKS_EDIT,
                    'delete' => Capability::TASKS_DELETE,
                    'complete' => Capability::TASKS_COMPLETE,
                    'assign' => Capability::TASKS_ASSIGN,
                ]
            ),

            // التواريخ
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }

    private function relationCount(string $countKey, ?string $relation = null, ?callable $fallback = null): int
    {
        $attribute = $countKey.'_count';

        if (array_key_exists($attribute, $this->resource->getAttributes())) {
            return (int) $this->{$attribute};
        }

        if ($fallback) {
            return (int) $fallback();
        }

        return (int) $this->{($relation ?? $countKey)}()->count();
    }
}
