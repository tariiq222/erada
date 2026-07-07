<?php

namespace App\Modules\Core\Policies;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\RoleHierarchy;
use Illuminate\Auth\Access\HandlesAuthorization;

class UserPolicy
{
    use HandlesAuthorization;

    /**
     * Super Admin يتجاوز كل الصلاحيات
     *
     * super_admin already short-circuits inside AccessDecision::can() (step 1 of
     * whyCan), so this before() is a belt-and-braces bypass for any call site
     * that invokes the policy method without going through the engine. Keeping
     * it means a stale `User->can('update', $other)` from a feature test or
     * non-engine path still fails closed if the engine is removed later.
     */
    public function before(User $user, string $ability): ?bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return null;
    }

    /**
     * عرض قائمة المستخدمين
     *
     * Engine handles super_admin bypass + role mapping at the organization level
     * (admin functional role grants USERS_VIEW). No model target: capability is
     * evaluated against organization-scoped roles only.
     */
    public function viewAny(User $user): bool
    {
        return AccessDecision::can($user, Capability::USERS_VIEW);
    }

    /**
     * عرض مستخدم معين
     */
    public function view(User $user, User $model): bool
    {
        // المستخدم يرى نفسه
        if ($user->id === $model->id) {
            return true;
        }

        // super_admin already bypassed above; engine handles the role grant.
        if (! AccessDecision::can($user, Capability::USERS_VIEW)) {
            return false;
        }

        // User model is NOT ScopeAware — the engine cannot derive organization_id
        // from it via the scope chain. We must gate cross-org access explicitly so
        // a user with USERS_VIEW in org A cannot read org B's users. The engine
        // already validated grants; this layer enforces isolation.
        return $this->belongsToUserOrganization($user, $model);
    }

    /**
     * إنشاء مستخدم
     */
    public function create(User $user): bool
    {
        return AccessDecision::can($user, Capability::USERS_CREATE);
    }

    /**
     * تعديل مستخدم
     */
    public function update(User $user, User $model): bool
    {
        // المستخدم يعدل نفسه
        if ($user->id === $model->id) {
            return true;
        }

        if (! AccessDecision::can($user, Capability::USERS_EDIT)) {
            return false;
        }

        // لا يمكن لغير Super Admin تعديل Super Admin آخر — defense-in-depth:
        // super_admin already short-circuits in before(), so this branch is only
        // reachable for non-super_admin, and they must not edit another super_admin.
        if ($model->isSuperAdmin()) {
            return false;
        }

        // كل مستخدم غير Super Admin يعدل مستخدمي مؤسسته فقط
        return $this->belongsToUserOrganization($user, $model);
    }

    /**
     * حذف مستخدم
     */
    public function delete(User $user, User $model): bool
    {
        // لا يمكن حذف نفسك
        if ($user->id === $model->id) {
            return false;
        }

        if (! AccessDecision::can($user, Capability::USERS_DELETE)) {
            return false;
        }

        // لا يمكن لغير Super Admin حذف Super Admin
        if ($model->isSuperAdmin()) {
            return false;
        }

        // كل مستخدم غير Super Admin يحذف مستخدمي مؤسسته فقط
        return $this->belongsToUserOrganization($user, $model);
    }

    /**
     * استعادة مستخدم محذوف
     */
    public function restore(User $user, User $model): bool
    {
        return $this->delete($user, $model);
    }

    /**
     * حذف نهائي
     */
    public function forceDelete(User $user, User $model): bool
    {
        return false; // لا يمكن الحذف النهائي للمستخدمين
    }

    /**
     * هل يمكن للمُعطي تعيين الأدوار المطلوبة؟
     *
     * super_admin و admin يستطيعان تعيين أي دور.
     * غيرهم يستطيعون تعيين الأدوار الأدنى من دورهم فقط (لا المساوي ولا الأعلى).
     */
    public function canAssignRole(User $actor, User $model, array $roles): bool
    {
        return RoleHierarchy::canAssignAll($actor, $roles);
    }

    /**
     * التحقق من انتماء المستخدم لنفس المؤسسة
     *
     * User is not ScopeAware, so the engine's organization-isolation gate cannot
     * resolve a target organization_id for a User model. Every per-record User
     * policy method that is NOT "self" MUST call this helper, or a viewer in
     * org A could enumerate org B's users despite having no scoped role on them.
     */
    private function belongsToUserOrganization(User $user, User $model): bool
    {
        if ($user->organization_id === null || $model->organization_id === null) {
            return false;
        }

        return $user->organization_id === $model->organization_id;
    }
}
