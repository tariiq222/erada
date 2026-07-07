<?php

namespace App\Modules\Tasks\Policies;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\User;
use App\Modules\OVR\Models\IncidentReport;
use App\Modules\Tasks\Models\Task;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * TaskPolicy — مرحلة هـ: محرّك AuthZ فقط (engine-only)
 * مسارات Spatie flat أُزيلت. المسار الوحيد: AccessDecision::can().
 * استثناء: المهام الشخصية (isPersonalTask) محكومة بطبقة owner_id فوق المحرّك.
 */
class TaskPolicy
{
    use HandlesAuthorization;

    public function before(User $user, string $ability): ?bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return null;
    }

    public function viewAny(User $user): bool
    {
        return AccessDecision::can($user, Capability::TASKS_VIEW);
    }

    public function view(User $user, Task $task): bool
    {
        if ($task->isPersonalTask()) {
            return $this->isPersonalTaskOwner($user, $task);
        }

        // Source-sensitivity gate — closes the OVR-confidential leak on
        // the per-record (show) path. Task::scopeVisibleTo handles the
        // list endpoint; this method must agree so a confidential task
        // cannot be reached via /api/tasks/{id} even if the list is
        // skipped. The contract: a task whose source is an OVR
        // IncidentReport with source_sensitivity='confidential' is
        // need-to-know, not department-broadcast.
        if ($this->isConfidentialSource($task) && ! $this->userMayViewConfidential($user)) {
            return false;
        }

        return AccessDecision::can($user, Capability::TASKS_VIEW, $task);
    }

    /**
     * Is this task sourced from a confidential parent that requires
     * an explicit grant to view? Conservative: any source_type that
     * we know can carry sensitivity='confidential' is included, even
     * if the current row's sensitivity column is null (defensive —
     * missing sensitivity is treated as 'normal', which is the
     * legacy default; the check below catches the explicit
     * confidential stamp).
     */
    private function isConfidentialSource(Task $task): bool
    {
        if ($task->source_type === null || $task->source_sensitivity === null) {
            return false;
        }

        $sensitiveSources = [
            'App\\Modules\\OVR\\Models\\IncidentReport',
            'IncidentReport',
            'incident_report',
            IncidentReport::class,
        ];

        return in_array($task->source_type, $sensitiveSources, true)
            && $task->source_sensitivity === 'confidential';
    }

    /**
     * Mirrors Task::userMayViewConfidential and IncidentReport's
     * confidential-view grant check. A user with the OVR_CONFIDENTIAL
     * capability via any active scoped role can see confidential-source
     * tasks; everyone else is blocked at the policy boundary.
     */
    private function userMayViewConfidential(User $user): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->activeScopedRoles()
            ->with('roleDefinition')
            ->get()
            ->contains(function ($scopedRole) {
                $def = $scopedRole->roleDefinition;
                if ($def === null || ! is_array($def->permissions ?? null)) {
                    return false;
                }

                return in_array(Capability::OVR_CONFIDENTIAL, $def->permissions, true);
            });
    }

    public function create(User $user): bool
    {
        return AccessDecision::can($user, Capability::TASKS_CREATE);
    }

    public function update(User $user, Task $task): bool
    {
        if ($task->isPersonalTask()) {
            return $task->owner_id === $user->id;
        }

        return AccessDecision::can($user, Capability::TASKS_EDIT, $task);
    }

    public function delete(User $user, Task $task): bool
    {
        if ($task->isPersonalTask()) {
            return $task->owner_id === $user->id;
        }

        return AccessDecision::can($user, Capability::TASKS_DELETE, $task);
    }

    public function restore(User $user, Task $task): bool
    {
        return $this->delete($user, $task);
    }

    public function forceDelete(User $user, Task $task): bool
    {
        return false;
    }

    /**
     * تغيير حالة المهمة — يرتبط بـ tasks.edit.
     */
    public function changeStatus(User $user, Task $task): bool
    {
        if ($task->isPersonalTask()) {
            return $task->owner_id === $user->id;
        }

        return AccessDecision::can($user, Capability::TASKS_EDIT, $task);
    }

    /**
     * تأكيد إكمال المهمة — صلاحية قيادية فقط (tasks.complete).
     * المهام الشخصية لا تملك مفهوم الإكمال القيادي.
     */
    public function completeTask(User $user, Task $task): bool
    {
        if ($task->isPersonalTask()) {
            return false;
        }

        return AccessDecision::can($user, Capability::TASKS_COMPLETE, $task);
    }

    /**
     * تعيين المهمة لموظف — صلاحية مستقلة عن tasks.edit.
     *
     * Phase 2 of master AuthZ unification plan: a user may carry
     * Capability::TASKS_ASSIGN without carrying TASKS_EDIT (e.g. a
     * department manager who can delegate ownership of a task they
     * cannot edit). Conversely, edit-only users cannot delegate. The
     * controller and AssignTaskRequest both call this method through
     * the Gate.
     *
     * Personal tasks are scoped to owner/creator/assignee — the engine
     * has no scope parent to walk, so the personal-task floor is the
     * source of truth (mirroring view/update/delete).
     */
    public function assign(User $user, Task $task): bool
    {
        if ($task->isPersonalTask()) {
            return $this->isPersonalTaskOwner($user, $task);
        }

        return AccessDecision::can($user, Capability::TASKS_ASSIGN, $task);
    }

    /**
     * إضافة تعليق — من يرى المهمة يعلّق عليها.
     */
    public function comment(User $user, Task $task): bool
    {
        return $this->view($user, $task);
    }

    /**
     * رفع مرفق — يرتبط بـ tasks.edit.
     */
    public function uploadAttachment(User $user, Task $task): bool
    {
        if ($task->isPersonalTask()) {
            return $task->owner_id === $user->id;
        }

        return AccessDecision::can($user, Capability::TASKS_EDIT, $task);
    }

    // ========== Helper Methods ==========

    /**
     * التحقق من ملكية المهمة الشخصية: المالك أو المنشئ أو المكلف.
     */
    private function isPersonalTaskOwner(User $user, Task $task): bool
    {
        return $task->owner_id === $user->id
            || $task->created_by === $user->id
            || $task->assigned_to === $user->id;
    }
}
