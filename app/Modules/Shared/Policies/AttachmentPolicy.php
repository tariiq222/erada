<?php

namespace App\Modules\Shared\Policies;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Models\Recommendation;
use App\Modules\OVR\Models\IncidentReport;
use App\Modules\Performance\Models\Kpi;
use App\Modules\Projects\Models\Project;
use App\Modules\RiskManagement\Models\Risk;
use App\Modules\Shared\Models\Attachment;
use App\Modules\Shared\Models\Comment;
use App\Modules\Tasks\Models\Task;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * AttachmentPolicy - سياسة الوصول للمرفقات
 *
 * مهم: لا تستخدم روابط مباشرة للملفات!
 * استخدم endpoint محمي يولد signed URL بعد التحقق من الصلاحيات
 *
 * Phase 4.4 (AuthZ unification): visibility routes through the
 * ScopeAware contract + a capability map keyed by parent class, so a
 * risk/OVR/meeting/department-scoped attachable inherits its parent's
 * authorization without per-type branching.
 */
class AttachmentPolicy
{
    use HandlesAuthorization;

    /**
     * Map of attachable parent class -> the engine capability the parent
     * itself honors for "view". The engine applies organization isolation
     * + scope-chain grants + sensitive gating uniformly, so the
     * attachment just rides the parent's decision.
     *
     * Adding a new ScopeAware parent that attachments can hang off =
     * adding one entry here AND a row in
     * docs/authz/resource-authorization-graph.md.
     *
     * @var array<class-string, string>
     */
    private const ATTACHABLE_VIEW_CAPABILITY = [
        Project::class => Capability::PROJECTS_VIEW,
        Task::class => Capability::TASKS_VIEW,
        Risk::class => Capability::RISKS_VIEW,
        IncidentReport::class => Capability::OVR_VIEW,
        Meeting::class => Capability::MEETINGS_VIEW,
        Recommendation::class => Capability::MEETINGS_VIEW,
        Department::class => Capability::DEPARTMENTS_VIEW,
        Kpi::class => Capability::KPIS_VIEW,
    ];

    /**
     * Super Admin يتجاوز كل الصلاحيات
     *
     * Defense-in-depth: super_admin already short-circuits in
     * AccessDecision::whyCan() step 1. Keeping this before() means non-engine
     * call sites (`User->can('create', Attachment::class)` without target) also
     * bypass — without it, an admin uploading via a code path that skips the
     * engine would silently 403.
     */
    public function before(User $user, string $ability): ?bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return null;
    }

    /**
     * عرض قائمة المرفقات
     *
     * List-level access: defer to the engine with no target. The engine walks
     * organization-scoped functional roles only (admin functional role grants
     * ATTACHMENTS_VIEW via ScopedRoleDefinition permissions mapping).
     */
    public function viewAny(User $user): bool
    {
        return AccessDecision::can($user, Capability::ATTACHMENTS_VIEW);
    }

    /**
     * عرض/تنزيل مرفق
     *
     * Per-record access: must enforce visibility on the attachable. We delegate
     * to AccessDecision::can() with the matching capability on the attachable
     * itself — the engine applies organization isolation + scope-chain grants +
     * inline roles uniformly, so attachment visibility inherits the parent
     * (project/task/comment) policy semantics automatically.
     */
    public function view(User $user, Attachment $attachment): bool
    {
        return $this->canAccessAttachable($user, $attachment);
    }

    /**
     * تنزيل مرفق (نفس view)
     */
    public function download(User $user, Attachment $attachment): bool
    {
        return $this->view($user, $attachment);
    }

    /**
     * رفع مرفق جديد
     */
    public function create(User $user): bool
    {
        return AccessDecision::can($user, Capability::ATTACHMENTS_UPLOAD);
    }

    /**
     * تعديل معلومات المرفق (الاسم مثلاً)
     */
    public function update(User $user, Attachment $attachment): bool
    {
        // صاحب المرفق
        if ($attachment->user_id === $user->id) {
            return true;
        }

        // مدير العنصر المرتبط
        return $this->isAttachableAdmin($user, $attachment);
    }

    /**
     * حذف مرفق
     */
    public function delete(User $user, Attachment $attachment): bool
    {
        // صلاحية حذف جميع المرفقات — engine routes this through the
        // organization-scoped functional role and ScopedRoleDefinition grants.
        if (AccessDecision::can($user, Capability::ATTACHMENTS_DELETE)) {
            return true;
        }

        // صاحب المرفق
        if ($attachment->user_id === $user->id) {
            return true;
        }

        // مدير العنصر المرتبط
        return $this->isAttachableAdmin($user, $attachment);
    }

    // ========== Helper Methods ==========

    /**
     * التحقق من إمكانية الوصول للعنصر المرتبط بالمرفق
     *
     * Routes visibility through the unified engine on the attachable model.
     * Phase 4.4: lookup is keyed by attachable class in
     * ATTACHABLE_VIEW_CAPABILITY so a Risk/OVR/Meeting-scoped attachable
     * inherits its parent's authorization without per-type branching.
     * Comments are not ScopeAware; they delegate to their commentable.
     */
    private function canAccessAttachable(User $user, Attachment $attachment): bool
    {
        $attachable = $attachment->attachable;

        if (! $attachable) {
            // Distinguish two null-attachable cases:
            //   1. attachable_type is null/unset (orphan attachment) —
            //      fall back to owner-only.
            //   2. attachable_type is set but the row is missing
            //      (dangling FK) — also fall back to owner-only. The
            //      parent's authorization cannot be evaluated without
            //      the row, so we cannot apply a parent-derived policy.
            //      The owner-only fallback preserves the user's ability
            //      to see / clean up their own uploads when the parent
            //      record was hard-deleted out from under the attachment.
            return $attachment->user_id === $user->id;
        }

        if ($attachable instanceof Comment) {
            // Comment is not ScopeAware; resolve organization via the commentable
            // (project/task/...) so the engine can enforce isolation correctly.
            $commentable = $attachable->commentable;

            if (! $commentable) {
                // Same dangling-FK guard as the orphan branch above: if
                // commentable_type is unset, fall back to owner-only;
                // otherwise deny because the comment's parent cannot be
                // authorized.
                if ($attachable->commentable_type === null || $attachable->commentable_type === '') {
                    return $attachment->user_id === $user->id;
                }

                return false;
            }

            return AccessDecision::can($user, Capability::COMMENTS_VIEW, $commentable);
        }

        $capability = self::ATTACHABLE_VIEW_CAPABILITY[get_class($attachable)] ?? null;

        if ($capability === null) {
            // Unknown parent type: fall back to owner-only so an unmapped
            // parent never silently widens access.
            return $attachment->user_id === $user->id;
        }

        return AccessDecision::can($user, $capability, $attachable);
    }

    /**
     * التحقق من كون المستخدم مدير للعنصر المرتبط
     */
    private function isAttachableAdmin(User $user, Attachment $attachment): bool
    {
        $attachable = $attachment->attachable;

        if (! $attachable) {
            return false;
        }

        // إذا كان المرفق على مهمة
        if ($attachable instanceof Task) {
            if ($attachable->project_id && $user->isProjectAdmin($attachable->project_id)) {
                return true;
            }
        }

        // إذا كان المرفق على مشروع
        if ($attachable instanceof Project) {
            return $user->isProjectAdmin($attachable);
        }

        // إذا كان المرفق على تعليق - تحقق من العنصر المرتبط بالتعليق
        if ($attachable instanceof Comment) {
            $commentable = $attachable->commentable;
            if ($commentable instanceof Task && $commentable->project_id) {
                return $user->isProjectAdmin($commentable->project_id);
            }
            if ($commentable instanceof Project) {
                return $user->isProjectAdmin($commentable);
            }
        }

        return false;
    }
}
