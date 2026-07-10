<?php

namespace App\Modules\Tasks\Policies;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Authorization\Contracts\SensitivelyScoped;
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
            // Personal tasks are owner-floor only — NEVER widen via the
            // cluster rescue branch. The personal-task floor is the
            // single source of truth (mirrors update/delete); CFA-00
            // owner decision.
            return $this->isPersonalTaskOwner($user, $task);
        }

        // Phase CFA-08 — Cluster tree widening applies to view() only.
        //
        // Decision paths (mirrors ProjectPolicy::view / CFA-04):
        //   1) TASKS_VIEW on task (same org): engine's same-org + role check.
        //      The existing source-sensitivity confidential gate runs inside
        //      AccessDecision::whyCan via Task::isSensitive() +
        //      Task::mayAccessSensitive() (the SensitivelyScoped contract).
        //   2) CLUSTER_TREE_VIEW on task (cluster ancestor): engine's rescue
        //      branch verifies the ancestor walk + non-sensitive target. Only
        //      fires if the actor holds Capability::TASKS_VIEW +
        //      Capability::CLUSTER_TREE_VIEW on actor.organization_id —
        //      two explicit checks before the rescue.
        //
        // Missing either capability ⇒ deny. The sensitive-target floor is
        // preserved: a sensitive task (SensitivelyScoped + isSensitive=true)
        // cannot reach the cluster rescue branch (engine pre-flight checks
        // isSensitive first), so cluster actors do NOT see confidential
        // OVR-sourced tasks even with both grants held.
        // The Source-sensitivity gate below closes the OVR-confidential
        // leak on the per-record (show) path. Task::scopeVisibleTo +
        // UserTaskScope handle the list endpoint; this method agrees so a
        // confidential task cannot be reached via /api/tasks/{id} even if
        // the list is skipped.
        if ($this->isConfidentialSource($task) && ! $this->userMayViewConfidential($user)) {
            return false;
        }

        // Path 1: same-org TASKS_VIEW via engine.
        if (AccessDecision::can($user, Capability::TASKS_VIEW, $task)) {
            return true;
        }

        // Path 2: cross-org cluster_tree widening — requires BOTH entitlements
        // on actor.org. The engine's clusterTreeRescueApplies short-circuits
        // on a sensitive target (cluster grant bypasses nothing), and the
        // ancestor walk + scoped-role check runs inside
        // crossOrgClusterTreeAdmitted. Returning false here on a sensitive
        // target is the engine's structural answer — but we add an explicit
        // isSensitive() deny above so the early return is documented and
        // grep-able.
        if ($task instanceof SensitivelyScoped && $task->isSensitive()) {
            return false;
        }

        if (! AccessDecision::can($user, Capability::TASKS_VIEW)) {
            return false;
        }
        if (! AccessDecision::can($user, Capability::CLUSTER_TREE_VIEW)) {
            return false;
        }

        return AccessDecision::can($user, Capability::CLUSTER_TREE_VIEW, $task);
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
     *
     * Phase CFA-08 — Cluster tree widening applies to status / PDCA transitions
     * ONLY (governance writes). Same shape as ProjectPolicy::updateStatus /
     * CFA-04: same-org via engine TASKS_EDIT, cross-org via cluster rescue on
     * Capability::CLUSTER_TREE_MANAGE.
     *
     * The controller (UpdateTaskStatusRequest::authorize) is the ONLY caller —
     * the general update() flow continues to use update() and stays strict
     * same-org per CFA-00 owner decision.
     */
    public function changeStatus(User $user, Task $task): bool
    {
        if ($task->isPersonalTask()) {
            // Personal tasks (owner_id floor) NEVER widen via cluster —
            // the personal-task floor is owner-only.
            return $task->owner_id === $user->id;
        }

        return $this->clusterManagedChangeStatus($user, $task);
    }

    /**
     * Two-path cluster_tree.manage rescue for status / PDCA transitions on
     * Tasks (governance writes only).
     *
     * Mirrors ProjectPolicy::clusterManagedUpdate (CFA-04) and
     * StrategyPolicy::clusterManagedUpdate (CFA-03):
     *   - Path 1: same-org via engine TASKS_EDIT.
     *   - Path 2: TASKS_EDIT + CLUSTER_TREE_MANAGE on actor.org + engine
     *     rescue branch verifies ancestor walk + non-sensitive target.
     *
     * Both grants are required IN ADDITION TO the actor's authority on
     * TASKS_EDIT — neither primitive implies the other. Sensitive-target
     * floor preserved (SensitivelyScoped + isSensitive=true is final):
     * cluster actors cannot transition status on confidential OVR-sourced
     * tasks even with both cluster grants held.
     */
    private function clusterManagedChangeStatus(User $user, Task $task): bool
    {
        // Path 1: same-org via engine.
        if (AccessDecision::can($user, Capability::TASKS_EDIT, $task)) {
            return true;
        }

        // Path 2 pre-flight — explicit sensitive deny. The engine's
        // clusterTreeRescueApplies also short-circuits on isSensitive=true;
        // this explicit check makes the floor grep-able at the policy
        // boundary and matches the ProjectResource / IncidentReportPolicy
        // documentation contract.
        if ($task instanceof SensitivelyScoped && $task->isSensitive()) {
            return false;
        }

        // Path 2: cross-org rescue — both grants required on actor.org.
        if (! AccessDecision::can($user, Capability::TASKS_EDIT)) {
            return false;
        }
        if (! AccessDecision::can($user, Capability::CLUSTER_TREE_MANAGE)) {
            return false;
        }

        return AccessDecision::can($user, Capability::CLUSTER_TREE_MANAGE, $task);
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
