<?php

namespace App\Modules\Tasks\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Shared\Http\Resources\ActivityLogResource;
use App\Modules\Shared\Scopes\UserActivityLogScope;
use App\Modules\Tasks\Enums\TaskStatus;
use App\Modules\Tasks\Http\Requests\AssignTaskRequest;
use App\Modules\Tasks\Http\Requests\DestroyTaskRequest;
use App\Modules\Tasks\Http\Requests\StoreTaskRequest;
use App\Modules\Tasks\Http\Requests\UpdateTaskRequest;
use App\Modules\Tasks\Http\Requests\UpdateTaskStatusRequest;
use App\Modules\Tasks\Http\Resources\TaskResource;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Repositories\Contracts\TaskRepositoryInterface;
use App\Modules\Tasks\Support\ImprovementTransitionGuard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class TaskController extends Controller
{
    /**
     * معالجة الأخطاء غير المتوقعة
     */
    private function handleException(\Throwable $e, string $context): JsonResponse
    {
        if ($e instanceof AuthorizationException
            || $e instanceof AuthenticationException
            || $e instanceof ValidationException
            || $e instanceof ModelNotFoundException
            || $e instanceof HttpException
            || $e instanceof NotFoundHttpException
            || $e instanceof MethodNotAllowedHttpException) {
            throw $e;
        }

        $errorId = uniqid('task_err_', true);
        Log::error("TaskController error: {$context}", [
            'error_id' => $errorId,
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        return response()->json([
            'message' => 'حدث خطأ غير متوقع. الرجاء المحاولة لاحقاً.',
            'error_id' => $errorId,
        ], 500);
    }

    public function __construct(
        private readonly TaskRepositoryInterface $tasks,
    ) {}

    /**
     * التحقق من صلاحية الوصول للمهمة
     */
    protected function authorizeTask(Task $task, string $ability = 'view'): void
    {
        $this->authorize($ability, $task);
    }

    /**
     * يمنع ربط المهمة بمشروع/قسم/مهمة-أب تابعة لمؤسسة أخرى (store + update).
     */
    private function assertTargetsInOrganization(int $orgId, array $data): void
    {
        if (array_key_exists('project_id', $data) && $data['project_id'] !== null) {
            $project = Project::find($data['project_id']);
            if (! $project || $project->organization_id !== $orgId) {
                abort(403, __('validation.tasks.project_not_in_organization'));
            }
        }

        if (array_key_exists('department_id', $data) && $data['department_id'] !== null) {
            $dept = Department::find($data['department_id']);
            if (! $dept || $dept->organization_id !== $orgId) {
                abort(403, __('validation.tasks.department_not_in_organization'));
            }
        }

        if (array_key_exists('parent_id', $data) && $data['parent_id'] !== null) {
            $parent = Task::find($data['parent_id']);
            if (! $parent || $parent->scopeOrganizationId() !== $orgId) {
                abort(403, __('validation.tasks.parent_not_in_organization'));
            }
        }
    }

    /**
     * عرض قائمة المهام
     */
    public function index(Request $request): AnonymousResourceCollection|JsonResponse
    {
        try {
            $this->authorize('viewAny', Task::class);

            $filters = array_merge($request->only([
                'type', 'project_id', 'department_id', 'status', 'priority',
                'assigned_to', 'my_tasks', 'overdue', 'upcoming', 'active',
                'root_only', 'search', 'sort_by', 'sort_dir',
            ]), ['user_id' => (int) auth()->id()]);

            $perPage = min((int) $request->get('per_page', 15), 100);

            return TaskResource::collection(
                $this->tasks->getPaginated($filters, $perPage, auth()->user())
            );
        } catch (\Throwable $e) {
            return $this->handleException($e, 'index');
        }
    }

    /**
     * المهام الشخصية للمستخدم الحالي
     */
    public function myTasks(Request $request): AnonymousResourceCollection|JsonResponse
    {
        try {
            $this->authorize('viewAny', Task::class);

            $filters = $request->only(['type', 'status']);
            $perPage = min((int) $request->get('per_page', 15), 100);

            return TaskResource::collection(
                $this->tasks->getUserTasksPaginated((int) auth()->id(), $filters, $perPage)
            );
        } catch (\Throwable $e) {
            return $this->handleException($e, 'myTasks');
        }
    }

    /**
     * إنشاء مهمة جديدة
     */
    public function store(StoreTaskRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();

            $this->assertTargetsInOrganization((int) $request->user()->organization_id, $data);

            $data['created_by'] = (int) auth()->id();

            // A personal task always belongs to its creator — never honor a
            // caller-supplied owner_id for personal tasks (H-03).
            if (($data['type'] ?? 'project') === 'personal') {
                $data['owner_id'] = (int) auth()->id();
            }

            $data['status'] = $data['status'] ?? TaskStatus::TODO->value;
            $data['priority'] = $data['priority'] ?? 'medium';
            $data['progress'] = $data['progress'] ?? 0;

            $task = $this->tasks->create($data);

            return response()->json([
                'message' => 'تم إنشاء المهمة بنجاح',
                'task' => new TaskResource($task),
            ], 201);
        } catch (\Throwable $e) {
            return $this->handleException($e, 'store');
        }
    }

    /**
     * عرض مهمة محددة
     */
    public function show(Task $task): TaskResource|JsonResponse
    {
        try {
            $this->authorizeTask($task, 'view');

            return new TaskResource(
                $this->tasks->findWithRelations($task->id) ?? $task
            );
        } catch (\Throwable $e) {
            return $this->handleException($e, 'show');
        }
    }

    /**
     * تحديث مهمة
     */
    public function update(UpdateTaskRequest $request, Task $task): JsonResponse
    {
        try {
            $data = $request->validated();

            $this->assertTargetsInOrganization((int) $request->user()->organization_id, $data);

            // Moving a task into a different project/department/type is effectively
            // creating it in a new scope — require create authority too (H-03).
            $movingScope = (array_key_exists('project_id', $data) && (int) ($data['project_id'] ?? 0) !== (int) ($task->project_id ?? 0))
                || (array_key_exists('department_id', $data) && (int) ($data['department_id'] ?? 0) !== (int) ($task->department_id ?? 0))
                || (array_key_exists('type', $data) && $data['type'] !== $task->type?->value);
            if ($movingScope) {
                $this->authorize('create', Task::class);
            }

            $oldStatus = $task->status?->value;
            $newStatus = $data['status'] ?? null;

            if ($msg = ImprovementTransitionGuard::check($task, $newStatus, $data)) {
                return response()->json(['message' => $msg], 422);
            }

            if ($newStatus === 'completed') {
                if ($task->hasIncompleteSubtasks()) {
                    return response()->json([
                        'message' => 'لا يمكن إكمال المهمة وبها مهام فرعية غير مكتملة',
                    ], 422);
                }
                $data['completed_date'] = now();
                $data['progress'] = 100;
            }

            if ($newStatus && $newStatus !== 'completed' && $task->status === TaskStatus::COMPLETED) {
                $data['completed_date'] = null;
            }

            $statusComment = $data['status_comment'] ?? null;

            $task = $this->tasks->update($task, $data);

            if ($newStatus === 'in_review' && $statusComment && $oldStatus !== 'in_review') {
                $task->comments()->create([
                    'user_id' => auth()->id(),
                    'content' => "📋 **سبب الإرسال للمراجعة:**\n{$statusComment}",
                ]);
            }

            if ($newStatus === 'completed' && $oldStatus !== 'completed') {
                $completionComment = '';

                if (! empty($data['challenges'])) {
                    $completionComment .= "🎯 **التحديات وكيف تم حلها:**\n{$data['challenges']}\n\n";
                }

                if (! empty($data['lessons_learned'])) {
                    $completionComment .= "💡 **الدروس المستفادة:**\n{$data['lessons_learned']}";
                }

                if ($completionComment) {
                    $task->comments()->create([
                        'user_id' => auth()->id(),
                        'content' => trim($completionComment),
                    ]);
                }
            }

            return response()->json([
                'message' => 'تم تحديث المهمة بنجاح',
                'task' => new TaskResource($task),
            ]);
        } catch (\Throwable $e) {
            return $this->handleException($e, 'update');
        }
    }

    /**
     * تحديث حالة المهمة فقط
     */
    public function updateStatus(UpdateTaskStatusRequest $request, Task $task): JsonResponse
    {
        try {
            $newStatus = $request->status;

            if ($msg = ImprovementTransitionGuard::check($task, $newStatus, $request->all())) {
                return response()->json(['message' => $msg], 422);
            }

            if ($newStatus === 'completed' && $task->hasIncompleteSubtasks()) {
                return response()->json([
                    'message' => 'لا يمكن إكمال المهمة وبها مهام فرعية غير مكتملة',
                ], 422);
            }

            $updateData = ['status' => $newStatus];

            if ($request->filled('status_comment')) {
                $updateData['status_comment'] = $request->status_comment;
            }
            if ($request->filled('lessons_learned')) {
                $updateData['lessons_learned'] = $request->lessons_learned;
            }

            if ($newStatus === 'completed') {
                $updateData['completed_date'] = now();
                $updateData['progress'] = 100;
            } elseif ($task->status === TaskStatus::COMPLETED) {
                $updateData['completed_date'] = null;
            }

            $task = $this->tasks->update($task, $updateData);

            return response()->json([
                'message' => 'تم تحديث حالة المهمة بنجاح',
                'task' => new TaskResource($task),
            ]);
        } catch (\Throwable $e) {
            return $this->handleException($e, 'updateStatus');
        }
    }

    /**
     * حذف مهمة
     */
    public function destroy(DestroyTaskRequest $request, Task $task): JsonResponse
    {
        try {
            $this->authorizeTask($task, 'delete');

            $this->tasks->delete($task);

            return response()->json([
                'message' => 'تم حذف المهمة بنجاح',
            ]);
        } catch (\Throwable $e) {
            return $this->handleException($e, 'destroy');
        }
    }

    /**
     * سجل نشاطات المهمة
     */
    public function activityLog(Task $task): JsonResponse
    {
        try {
            $this->authorizeTask($task, 'view');

            $actor = request()->user();
            $query = $task->activityLogs()
                ->with('user:id,name')
                ->orderBy('created_at', 'desc')
                ->limit(50);
            if ($actor instanceof User) {
                app(UserActivityLogScope::class)->apply($query, $actor);
            }

            $logs = $query->get();

            // استخدم ActivityLogResource لمنع تسريب old_values/new_values/user_agent/
            // ip_address/metadata الخام (تطبَّق redaction من isSensitiveKey()).
            return response()->json([
                'data' => ActivityLogResource::collection($logs)->resolve(request()),
            ]);
        } catch (\Throwable $e) {
            return $this->handleException($e, 'activityLog');
        }
    }

    /**
     * إحصائيات المهام
     */
    public function stats(Request $request): JsonResponse
    {
        try {
            $this->authorize('viewAny', Task::class);

            $filters = array_merge(
                $request->only(['type', 'my_tasks']),
                ['user_id' => (int) auth()->id()]
            );

            return response()->json($this->tasks->getStats($filters, auth()->user()));
        } catch (\Throwable $e) {
            return $this->handleException($e, 'stats');
        }
    }

    /**
     * تعيين مهمة لموظف
     */
    public function assign(AssignTaskRequest $request, Task $task): JsonResponse
    {
        try {
            // Phase 2: gate the assign action through Capability::TASKS_ASSIGN
            // (not Capability::TASKS_EDIT). Edit-only users can no longer
            // delegate; assign-only users can. Defense-in-depth IDOR floor on
            // the assignee's organization_id is preserved below.
            $this->authorizeTask($task, 'assign');

            // D-04: قيّد هدف التعيين لمنظمة المهمة (org-floor) — منع IDOR على هدف الكتابة.
            // المخوّل أُثبت أعلاه؛ هذا يقيّد الـ assigned_to نفسه (حمولة من المستخدم).
            $target = User::findOrFail($request->input('assigned_to'));

            if ($task->project_id) {
                // مهمة مرتبطة بمشروع: عضو المشروع أو عضو نفس مؤسسة المشروع (نفس المؤسسة يكفي — D-04 محسوم).
                abort_unless(
                    $target->hasRoleInProject($task->project_id)
                        || $target->organization_id === optional($task->project)->organization_id,
                    403,
                    'لا يمكن تعيين المهمة لمستخدم خارج المشروع/المؤسسة'
                );
            } else {
                // Department / personal task: a personal task has no department,
                // so the old optional($task->department)->organization_id floor
                // resolved to null and let cross-org assignment through (H-03).
                // Floor on the acting user's organization instead.
                abort_unless(
                    $request->user()->isSuperAdmin()
                        || $target->organization_id === $request->user()->organization_id,
                    403,
                    'لا يمكن تعيين المهمة لمستخدم خارج المؤسسة'
                );
            }

            $task = $this->tasks->update($task, ['assigned_to' => $request->assigned_to]);

            return response()->json([
                'message' => 'تم تعيين المهمة بنجاح',
                'task' => new TaskResource($task),
            ]);
        } catch (\Throwable $e) {
            return $this->handleException($e, 'assign');
        }
    }
}
