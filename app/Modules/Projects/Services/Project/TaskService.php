<?php

namespace App\Modules\Projects\Services\Project;

use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\Task;

class TaskService
{
    private const PROJECT_MANAGER_ROLE = 'project_manager';

    /**
     * Create the initial set of tasks for a newly-created project.
     *
     * Invoked by ProjectCrudService::createProject as the creation path.
     */
    public function createTasks(Project $project, array $tasks, array $milestoneIds, User $user): void
    {
        foreach ($tasks as $order => $taskData) {
            if (empty($taskData['name'])) {
                continue;
            }

            $this->createTask($project, $taskData, $milestoneIds, $user, $order);
        }
    }

    /**
     * إنشاء مهمة واحدة
     */
    public function createTask(Project $project, array $data, array $milestoneIds, User $user, int $order = 0): Task
    {
        // تحويل milestone_index إلى milestone_id
        $milestoneId = null;
        if (isset($data['milestone_index']) && isset($milestoneIds[$data['milestone_index']])) {
            $milestoneId = $milestoneIds[$data['milestone_index']];
        } elseif (isset($data['milestone_id'])) {
            $milestoneId = $data['milestone_id'];
        }

        return $project->tasks()->create([
            'type' => 'project',
            'title' => $data['name'] ?? $data['title'],
            'description' => $data['description'] ?? null,
            'milestone_id' => $milestoneId,
            'assigned_to' => $data['assigned_to'] ?? $this->projectManagerId($project),
            'created_by' => $user->id,
            'priority' => $data['priority'] ?? 'medium',
            'status' => $data['status'] ?? 'todo',
            'start_date' => $data['start_date'] ?? null,
            'due_date' => $data['due_date'] ?? null,
            'order' => $order + 1,
            'progress' => $data['progress'] ?? 0,
        ]);
    }

    /**
     * مزامنة المهام عند تحديث المشروع (upsert بالـ id).
     *
     * - مهمة تحمل id موجوداً ضمن المشروع → تحديث (لا تكرار).
     * - مهمة بلا id → إنشاء جديدة.
     *
     * لا تُحذف المهام غير المذكورة (قد تملك مهاماً فرعية أو تُدار عبر نقاط
     * نهاية المهام المخصصة). يتجنّب سلوك التكرار السابق في كل تحديث.
     *
     * @param  array<int, int>  $milestoneIds  خريطة index→id للمراحل الجديدة
     */
    public function syncTasks(Project $project, array $tasks, User $user, array $milestoneIds = []): void
    {
        $existingIds = array_map('intval', $project->tasks()->pluck('id')->all());
        $maxOrder = $project->tasks()->max('order') ?? 0;
        $order = $maxOrder;

        foreach ($tasks as $taskData) {
            if (empty($taskData['name']) && empty($taskData['title'])) {
                continue;
            }

            $id = $taskData['id'] ?? null;
            if ($id && in_array((int) $id, $existingIds, true)) {
                $task = $project->tasks()->whereKey($id)->first();
                if ($task) {
                    $this->updateTask($task, $taskData);

                    continue;
                }
            }

            $this->createTask($project, $taskData, $milestoneIds, $user, $order);
            $order++;
        }
    }

    /**
     * تحديث مهمة
     */
    public function updateTask(Task $task, array $data): Task
    {
        $task->update(array_filter([
            'title' => $data['title'] ?? $data['name'] ?? null,
            'description' => $data['description'] ?? null,
            'milestone_id' => $data['milestone_id'] ?? null,
            'assigned_to' => $data['assigned_to'] ?? null,
            'priority' => $data['priority'] ?? null,
            'status' => $data['status'] ?? null,
            'start_date' => $data['start_date'] ?? null,
            'due_date' => $data['due_date'] ?? null,
            'progress' => $data['progress'] ?? null,
        ], fn ($value) => $value !== null));

        return $task->fresh();
    }

    /**
     * حذف مهمة
     */
    public function deleteTask(Task $task): bool
    {
        return $task->delete();
    }

    /**
     * تغيير حالة المهمة
     */
    public function changeStatus(Task $task, string $status): Task
    {
        $task->update(['status' => $status]);

        // تحديث التقدم تلقائياً
        if ($status === 'completed') {
            $task->update(['progress' => 100]);
        }

        return $task->fresh();
    }

    /**
     * إسناد المهمة لمستخدم
     */
    public function assignTask(Task $task, int $userId): Task
    {
        $task->update(['assigned_to' => $userId]);

        return $task->fresh();
    }

    /**
     * الحصول على المهام حسب الحالة
     */
    public function getTasksByStatus(Project $project, string $status)
    {
        return $project->tasks()
            ->where('status', $status)
            ->orderBy('order')
            ->get();
    }

    /**
     * الحصول على المهام المتأخرة
     */
    public function getOverdueTasks(Project $project)
    {
        return $project->tasks()
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->where('due_date', '<', now())
            ->orderBy('due_date')
            ->get();
    }

    /**
     * الحصول على مهام مستخدم في المشروع
     */
    public function getUserTasks(Project $project, int $userId)
    {
        return $project->tasks()
            ->where('assigned_to', $userId)
            ->orderBy('order')
            ->get();
    }

    /**
     * تحديث ترتيب المهام
     */
    public function reorderTasks(Project $project, array $orderedIds): void
    {
        foreach ($orderedIds as $order => $taskId) {
            $project->tasks()
                ->where('id', $taskId)
                ->update(['order' => $order + 1]);
        }
    }

    private function projectManagerId(Project $project): ?int
    {
        $userId = AuthorizationRoleAssignment::query()
            ->where('scope_type', 'project')
            ->where('scope_id', $project->id)
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->whereHas('role', fn ($query) => $query
                ->where('name', self::PROJECT_MANAGER_ROLE)
                ->where('is_active', true))
            ->value('user_id');

        return $userId === null ? null : (int) $userId;
    }
}
