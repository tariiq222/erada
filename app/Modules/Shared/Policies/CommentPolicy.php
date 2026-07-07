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
use App\Modules\Shared\Models\Comment;
use App\Modules\Tasks\Models\Task;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * CommentPolicy - سياسة الوصول للتعليقات
 *
 * Phase 4.4 (AuthZ unification): visibility routes through a capability
 * map keyed by commentable class, so a Risk/OVR/Meeting/Department-scoped
 * commentable inherits its parent's authorization without per-type branching.
 */
class CommentPolicy
{
    use HandlesAuthorization;

    /**
     * Map of commentable parent class -> the engine capability the parent
     * itself honors for "view". Adding a new ScopeAware parent that
     * comments can hang off = adding one entry here AND a row in
     * docs/authz/resource-authorization-graph.md.
     *
     * @var array<class-string, string>
     */
    private const COMMENTABLE_VIEW_CAPABILITY = [
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
     * Defense-in-depth — AccessDecision::whyCan() step 1 short-circuits for
     * super_admin on every engine-routed call, but legacy `User->can('create',
     * Comment::class)` paths (no model target) still need a fast bypass here.
     */
    public function before(User $user, string $ability): ?bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return null;
    }

    /**
     * عرض قائمة التعليقات
     *
     * Per-record visibility is enforced inside `view()` via the commentable;
     * the list itself can be requested by any authenticated user.
     */
    public function viewAny(User $user): bool
    {
        // أي مستخدم مسجل يمكنه رؤية التعليقات
        return true;
    }

    /**
     * عرض تعليق معين
     */
    public function view(User $user, Comment $comment): bool
    {
        // التحقق من إمكانية الوصول للعنصر المرتبط
        return $this->canAccessCommentable($user, $comment);
    }

    /**
     * إنشاء تعليق
     */
    public function create(User $user): bool
    {
        // (التحقق من العنصر المرتبط يتم في Controller)
        return AccessDecision::can($user, Capability::COMMENTS_CREATE);
    }

    /**
     * تعديل تعليق
     */
    public function update(User $user, Comment $comment): bool
    {
        // صلاحية تعديل جميع التعليقات — engine routes through organization
        // functional roles + scoped role definitions that grant COMMENTS_EDIT.
        if (AccessDecision::can($user, Capability::COMMENTS_EDIT)) {
            return true;
        }

        // صاحب التعليق فقط يعدله
        return $comment->user_id === $user->id;
    }

    /**
     * حذف تعليق
     */
    public function delete(User $user, Comment $comment): bool
    {
        // صلاحية حذف جميع التعليقات
        if (AccessDecision::can($user, Capability::COMMENTS_DELETE)) {
            return true;
        }

        // صاحب التعليق يحذفه
        if ($comment->user_id === $user->id) {
            return true;
        }

        // مدير المشروع/المهمة المرتبطة يحذف التعليقات
        return $this->isCommentableAdmin($user, $comment);
    }

    // ========== Helper Methods ==========

    /**
     * التحقق من إمكانية الوصول للعنصر المرتبط بالتعليق
     *
     * Routes through the unified engine on the commentable. Phase 4.4:
     * lookup is keyed by commentable class in COMMENTABLE_VIEW_CAPABILITY
     * so a Risk/OVR/Meeting-scoped commentable inherits its parent's
     * authorization without per-type branching.
     */
    private function canAccessCommentable(User $user, Comment $comment): bool
    {
        $commentable = $comment->commentable;

        if (! $commentable) {
            // Distinguish two null-commentable cases:
            //   1. commentable_type is null/unset (orphan comment) —
            //      fall back to owner-only so a log entry without a
            //      parent stays accessible to the author.
            //   2. commentable_type is set but the row is missing
            //      (dangling FK) — deny. The parent's authorization
            //      cannot be evaluated without the row, so fail closed.
            if ($comment->commentable_type === null || $comment->commentable_type === '') {
                return $comment->user_id === $user->id;
            }

            return false;
        }

        $capability = self::COMMENTABLE_VIEW_CAPABILITY[get_class($commentable)] ?? null;

        if ($capability === null) {
            return $comment->user_id === $user->id;
        }

        return AccessDecision::can($user, $capability, $commentable);
    }

    /**
     * التحقق من كون المستخدم مدير للعنصر المرتبط
     */
    private function isCommentableAdmin(User $user, Comment $comment): bool
    {
        $commentable = $comment->commentable;

        if (! $commentable) {
            return false;
        }

        // إذا كان التعليق على مهمة
        if ($commentable instanceof Task) {
            // مدير المشروع التابعة له المهمة
            if ($commentable->project_id && $user->isProjectAdmin($commentable->project_id)) {
                return true;
            }
        }

        // إذا كان التعليق على مشروع
        if ($commentable instanceof Project) {
            return $user->isProjectAdmin($commentable);
        }

        return false;
    }
}
