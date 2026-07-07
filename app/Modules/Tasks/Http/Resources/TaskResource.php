<?php

namespace App\Modules\Tasks\Http\Resources;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Http\Resources\UserResource;
use App\Modules\Shared\Support\ElementAbilities;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type?->value ?? $this->type,
            'type_label' => $this->type?->label() ?? null,
            'title' => $this->title,
            'description' => $this->description,
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

            // العلاقات
            'project_id' => $this->project_id,
            'project' => $this->whenLoaded('project', fn () => [
                'id' => $this->project->id,
                'name' => $this->project->name,
                'code' => $this->project->code,
                'type' => $this->project->type,
            ]),

            'department_id' => $this->department_id,
            'department' => $this->whenLoaded('department', fn () => [
                'id' => $this->department->id,
                'name' => $this->department->name,
            ]),

            'milestone_id' => $this->milestone_id,
            'milestone' => $this->whenLoaded('milestone', fn () => [
                'id' => $this->milestone->id,
                'name' => $this->milestone->name,
            ]),

            'parent_id' => $this->parent_id,
            'parent' => $this->whenLoaded('parent', fn () => [
                'id' => $this->parent->id,
                'title' => $this->parent->title,
            ]),

            'assigned_to' => $this->assigned_to,
            'assignee' => $this->whenLoaded('assignee', fn () => new UserResource($this->assignee)),

            'created_by' => $this->created_by,
            'creator' => $this->whenLoaded('creator', fn () => new UserResource($this->creator)),

            'owner_id' => $this->owner_id,
            'owner' => $this->whenLoaded('owner', fn () => new UserResource($this->owner)),

            // المهام الفرعية
            'subtasks' => $this->whenLoaded('subtasks', fn () => TaskResource::collection($this->subtasks)),
            'subtasks_count' => $this->relationCount('subtasks'),
            'has_subtasks' => $this->relationCount('subtasks') > 0,
            'incomplete_subtasks_count' => $this->relationCount('incomplete_subtasks', 'subtasks', fn () => $this->subtasks()
                ->whereNotIn('status', ['completed', 'cancelled'])
                ->count()),

            // التعليقات والمرفقات
            'comments_count' => $this->relationCount('comments'),
            'attachments_count' => $this->relationCount('attachments'),

            // Per-record abilities — resolved through AccessDecision so the
            // frontend never re-derives scope-chain logic. Fall back to the
            // active request user when toArray() is invoked without a Request.
            'abilities' => ElementAbilities::resolve(
                $request?->user() ?? request()->user(),
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
