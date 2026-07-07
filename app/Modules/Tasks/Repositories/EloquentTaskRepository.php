<?php

namespace App\Modules\Tasks\Repositories;

use App\Modules\Core\Models\User;
use App\Modules\Tasks\Enums\TaskStatus;
use App\Modules\Tasks\Enums\TaskType;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Repositories\Contracts\TaskRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentTaskRepository implements TaskRepositoryInterface
{
    private const RELATIONS = ['assignee', 'creator', 'owner', 'project', 'department', 'milestone', 'subtasks'];

    // Relations only used by the list/index endpoint. The full RELATIONS set
    // remains for find/create/update paths that need every model graph.
    private const LIST_RELATIONS = ['assignee', 'project', 'department', 'milestone'];

    private const COUNTS = ['subtasks', 'comments', 'attachments'];

    private const ALLOWED_SORTS = ['title', 'status', 'priority', 'start_date', 'due_date', 'created_at', 'updated_at', 'progress'];

    public function baseQuery(): Builder
    {
        return Task::query()
            ->with(self::LIST_RELATIONS)
            ->withCount(self::COUNTS)
            ->withCount([
                'subtasks as incomplete_subtasks_count' => fn (Builder $query) => $query->whereNotIn('status', ['completed', 'cancelled']),
            ]);
    }

    public function getPaginated(array $filters, int $perPage = 15, ?User $user = null): LengthAwarePaginator
    {
        $query = $this->baseQuery();

        if ($user && ! $user->isSuperAdmin()) {
            $query->visibleTo($user);
        }

        $this->applyFilters($query, $filters);
        $this->applySorting($query, $filters);

        return $query->paginate(min($perPage, 100));
    }

    public function getUserTasksPaginated(int $userId, array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $query = $this->baseQuery()
            ->forUser($userId)
            ->rootTasks();

        // فلترة حسب النوع
        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        // فلترة حسب الحالة (افتراضياً: النشطة فقط)
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        } else {
            $query->active();
        }

        // الترتيب: الأولوية ثم التاريخ
        $query->orderByRaw("CASE priority WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 ELSE 5 END")
            ->orderBy('due_date', 'asc')
            ->orderBy('created_at', 'desc');

        return $query->paginate(min($perPage, 100));
    }

    public function findWithRelations(int $id): ?Task
    {
        return Task::with([
            'assignee',
            'creator',
            'owner',
            'project',
            'department',
            'milestone',
            'subtasks.assignee',
            'parent',
            'comments.user',
            'attachments',
        ])->withCount(self::COUNTS)
            ->withCount([
                'subtasks as incomplete_subtasks_count' => fn (Builder $query) => $query->whereNotIn('status', ['completed', 'cancelled']),
            ])
            ->find($id);
    }

    public function create(array $data): Task
    {
        $task = Task::create($data);
        $task->load(['assignee', 'creator', 'owner', 'project', 'department', 'milestone']);
        $task->loadCount(self::COUNTS);
        $task->loadCount([
            'subtasks as incomplete_subtasks_count' => fn (Builder $query) => $query->whereNotIn('status', ['completed', 'cancelled']),
        ]);

        return $task;
    }

    public function update(Task $task, array $data): Task
    {
        $task->update($data);
        $task->load(['assignee', 'creator', 'owner', 'project', 'department', 'milestone', 'subtasks']);
        $task->loadCount(self::COUNTS);
        $task->loadCount([
            'subtasks as incomplete_subtasks_count' => fn (Builder $query) => $query->whereNotIn('status', ['completed', 'cancelled']),
        ]);

        return $task;
    }

    public function delete(Task $task): bool
    {
        $task->subtasks()->delete();

        return (bool) $task->delete();
    }

    public function getStats(array $filters = [], ?User $user = null): array
    {
        $query = Task::query();
        $shouldScope = $user && ! $user->isSuperAdmin();

        if ($shouldScope) {
            $query->visibleTo($user);
        }

        if (! empty($filters['my_tasks']) && ! empty($filters['user_id'])) {
            $query->forUser($filters['user_id']);
        }

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        $row = $query
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('COUNT(*) FILTER (WHERE status = ?) AS todo', [TaskStatus::TODO->value])
            ->selectRaw('COUNT(*) FILTER (WHERE status = ?) AS in_progress', [TaskStatus::IN_PROGRESS->value])
            ->selectRaw('COUNT(*) FILTER (WHERE status = ?) AS in_review', [TaskStatus::IN_REVIEW->value])
            ->selectRaw('COUNT(*) FILTER (WHERE status = ?) AS completed', [TaskStatus::COMPLETED->value])
            ->selectRaw('COUNT(*) FILTER (WHERE status = ?) AS cancelled', [TaskStatus::CANCELLED->value])
            ->selectRaw('COUNT(*) FILTER (WHERE status = ?) AS on_hold', [TaskStatus::ON_HOLD->value])
            ->selectRaw("COUNT(*) FILTER (WHERE priority = 'critical') AS priority_critical")
            ->selectRaw("COUNT(*) FILTER (WHERE priority = 'high') AS priority_high")
            ->selectRaw("COUNT(*) FILTER (WHERE priority = 'medium') AS priority_medium")
            ->selectRaw("COUNT(*) FILTER (WHERE priority = 'low') AS priority_low")
            ->selectRaw('COUNT(*) FILTER (WHERE type = ?) AS type_project', [TaskType::PROJECT->value])
            ->selectRaw('COUNT(*) FILTER (WHERE type = ?) AS type_personal', [TaskType::PERSONAL->value])
            ->selectRaw('COUNT(*) FILTER (WHERE type = ?) AS type_department', [TaskType::DEPARTMENT->value])
            ->selectRaw('COUNT(*) FILTER (WHERE type = ?) AS type_recurring', [TaskType::RECURRING->value])
            ->selectRaw('COUNT(*) FILTER (WHERE due_date IS NOT NULL AND due_date < NOW() AND status != ? AND status != ?) AS overdue',
                [TaskStatus::COMPLETED->value, TaskStatus::CANCELLED->value])
            ->selectRaw("COUNT(*) FILTER (WHERE due_date IS NOT NULL AND due_date BETWEEN NOW() AND NOW() + INTERVAL '7 days' AND status IN (?, ?, ?)) AS upcoming_7_days",
                [TaskStatus::TODO->value, TaskStatus::IN_PROGRESS->value, TaskStatus::IN_REVIEW->value])
            ->reorder()
            ->first();

        return [
            'total' => (int) $row->total,
            'by_status' => [
                'todo' => (int) $row->todo,
                'in_progress' => (int) $row->in_progress,
                'in_review' => (int) $row->in_review,
                'completed' => (int) $row->completed,
                'cancelled' => (int) $row->cancelled,
                'on_hold' => (int) $row->on_hold,
            ],
            'by_priority' => [
                'critical' => (int) $row->priority_critical,
                'high' => (int) $row->priority_high,
                'medium' => (int) $row->priority_medium,
                'low' => (int) $row->priority_low,
            ],
            'overdue' => (int) $row->overdue,
            'upcoming_7_days' => (int) $row->upcoming_7_days,
            'by_type' => [
                'project' => (int) $row->type_project,
                'personal' => (int) $row->type_personal,
                'department' => (int) $row->type_department,
                'recurring' => (int) $row->type_recurring,
            ],
        ];
    }

    private function applyFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (! empty($filters['project_id']) && is_numeric($filters['project_id'])) {
            $query->where('project_id', (int) $filters['project_id']);
        }

        if (! empty($filters['department_id']) && is_numeric($filters['department_id'])) {
            $query->where('department_id', (int) $filters['department_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }

        if (! empty($filters['assigned_to']) && is_numeric($filters['assigned_to'])) {
            $query->where('assigned_to', (int) $filters['assigned_to']);
        }

        if (! empty($filters['my_tasks']) && ! empty($filters['user_id'])) {
            $query->forUser($filters['user_id']);
        }

        if (! empty($filters['overdue'])) {
            $query->overdue();
        }

        if (! empty($filters['upcoming'])) {
            $query->upcoming((int) $filters['upcoming']);
        }

        if (! empty($filters['active'])) {
            $query->active();
        }

        if (! empty($filters['root_only'])) {
            $query->rootTasks();
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }
    }

    private function applySorting(Builder $query, array $filters): void
    {
        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortDir = $filters['sort_dir'] ?? 'desc';

        if (in_array($sortBy, self::ALLOWED_SORTS)) {
            $query->orderBy($sortBy, $sortDir);
        }
    }
}
