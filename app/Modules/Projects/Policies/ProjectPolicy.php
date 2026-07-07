<?php

namespace App\Modules\Projects\Policies;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Services\ProjectAuthorizationService;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * ProjectPolicy - سياسة الوصول للمشاريع
 *
 * يعتمد كلياً على محرّك AuthZ الموحّد (AccessDecision::can).
 * المنطق القديم (Spatie flat permissions) أُزيل بعد تثبيت Engine=ON في Phase هـ.
 */
class ProjectPolicy
{
    use HandlesAuthorization;

    /**
     * Super Admin يتجاوز كل الصلاحيات
     */
    public function before(User $user, string $ability): ?bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return null;
    }

    /**
     * عرض قائمة المشاريع
     */
    public function viewAny(User $user): bool
    {
        return AccessDecision::can($user, Capability::PROJECTS_VIEW);
    }

    /**
     * عرض مشروع معين
     */
    public function view(User $user, Project $project): bool
    {
        return AccessDecision::can($user, Capability::PROJECTS_VIEW, $project);
    }

    /**
     * إنشاء مشروع — البوّابة العامة (هل يستطيع الإنشاء إطلاقاً؟).
     *
     * تشمل: الدور الوظيفي على مستوى المؤسسة، أو دوراً قسمياً يمنح projects.create
     * (مدير/عضو القسم ينشئ داخل قسمه)، أو عضوية القسم المُشرِّع على نوع ما. القرار
     * المدرك للسياق (النوع + القسم الهدف) يتم في StoreProjectRequest::authorize عبر
     * ProjectAuthorizationService::canCreate.
     */
    public function create(User $user): bool
    {
        return app(ProjectAuthorizationService::class)->canCreateAny($user);
    }

    /**
     * تعديل مشروع
     */
    public function update(User $user, Project $project): bool
    {
        return AccessDecision::can($user, Capability::PROJECTS_EDIT, $project);
    }

    /**
     * حذف مشروع
     */
    public function delete(User $user, Project $project): bool
    {
        return AccessDecision::can($user, Capability::PROJECTS_DELETE, $project);
    }

    /**
     * استعادة مشروع محذوف
     */
    public function restore(User $user, Project $project): bool
    {
        return $this->delete($user, $project);
    }

    /**
     * حذف نهائي — فقط Super Admin (يتم التحقق في before)
     */
    public function forceDelete(User $user, Project $project): bool
    {
        return false;
    }

    /**
     * تعيين أدوار للأعضاء في المشروع
     *
     * Single source of truth for the project-team management capability —
     * unified across the Projects `/projects/{id}/members/*` route family
     * and the Core `ScopedRoleController` `/projects/{id}/roles/*` alias.
     * Replaces the prior `PROJECTS_MANAGE_MEMBERS` capability (deleted
     * 2026-07-06) which was registered and seeded but never enforced.
     */
    public function assignProjectRoles(User $user, Project $project): bool
    {
        return AccessDecision::can($user, Capability::PROJECTS_ASSIGN_ROLES, $project);
    }
}
