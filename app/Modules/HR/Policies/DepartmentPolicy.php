<?php

namespace App\Modules\HR\Policies;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use Illuminate\Auth\Access\HandlesAuthorization;

class DepartmentPolicy
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
     * عرض قسم معين
     */
    public function view(User $user, Department $department): bool
    {
        return AccessDecision::can($user, Capability::DEPARTMENTS_VIEW, $department);
    }

    /**
     * إنشاء قسم
     *
     * A null-org user has no organization to manage — deny before asking
     * the engine. The engine's grantedViaOrgFunctionalRole grants any
     * capability to holders of the admin Spatie role (is_admin_role = true)
     * but does not guard the no-target (create) path against null-org users,
     * because the org-isolation check (step 2 of whyCan) only runs when a
     * target model is present. This explicit gate closes that gap.
     */
    public function create(User $user): bool
    {
        if (! $user->isSuperAdmin() && $user->organization_id === null) {
            return false;
        }

        return AccessDecision::can($user, Capability::DEPARTMENTS_CREATE);
    }

    /**
     * تعديل قسم
     */
    public function update(User $user, Department $department): bool
    {
        return AccessDecision::can($user, Capability::DEPARTMENTS_EDIT, $department);
    }

    /**
     * حذف قسم
     */
    public function delete(User $user, Department $department): bool
    {
        return AccessDecision::can($user, Capability::DEPARTMENTS_DELETE, $department);
    }
}
