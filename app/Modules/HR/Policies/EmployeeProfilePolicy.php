<?php

namespace App\Modules\HR\Policies;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\EmployeeProfile;
use App\Modules\HR\Support\EmployeeOrgGuard;

/**
 * EmployeeProfilePolicy - Phase 2: per-record org-isolation for EmployeeProfile.
 *
 * الـ engine `AccessDecision::can($user, Capability::HR_*, $profile)` لا يستطيع
 * اشتقاق organization_id لأن EmployeeProfile لا يطبّق ScopeAware
 * ولا يحمل العمود مباشرةً. لذا هذه الـ Policy تستخدم
 * EmployeeOrgGuard لاشتقاق الـ org عبر profile.user.organization_id.
 *
 * السلوك:
 *  - super_admin ⇒ true دائماً (via Gate::before + before()).
 *  - actor بلا organization_id ⇒ deny.
 *  - profile.user من منظمة أخرى ⇒ deny.
 *  - profile بلا user مرتبط ⇒ deny (orphan).
 *  - HR_VIEW للقراءة، HR_MANAGE للتعديل/الحذف/الإنشاء.
 *
 * لا تعتمد على Spatie direct. الـ Capability constants تمر عبر AccessDecision
 * ليتحقّق المحرك من الأدوار السياقية.
 */
class EmployeeProfilePolicy
{
    /**
     * Super Admin يتجاوز كل الصلاحيات.
     */
    public function before(User $user, string $ability): ?bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return null;
    }

    public function viewAny(User $user): bool
    {
        if (! $user->isSuperAdmin() && $user->organization_id === null) {
            return false;
        }

        return AccessDecision::can($user, Capability::HR_VIEW);
    }

    public function view(User $user, EmployeeProfile $profile): bool
    {
        if (! $this->precheck($user, $profile)) {
            return false;
        }

        return AccessDecision::can($user, Capability::HR_VIEW);
    }

    public function create(User $user): bool
    {
        if (! $user->isSuperAdmin() && $user->organization_id === null) {
            return false;
        }

        return AccessDecision::can($user, Capability::HR_MANAGE);
    }

    public function update(User $user, EmployeeProfile $profile): bool
    {
        if (! $this->precheck($user, $profile)) {
            return false;
        }

        return AccessDecision::can($user, Capability::HR_MANAGE);
    }

    public function delete(User $user, EmployeeProfile $profile): bool
    {
        if (! $this->precheck($user, $profile)) {
            return false;
        }

        return AccessDecision::can($user, Capability::HR_MANAGE);
    }

    /**
     * precheck: actor/org gate + same-org عبر EmployeeOrgGuard.
     */
    protected function precheck(User $user, EmployeeProfile $profile): bool
    {
        if (! $user->isSuperAdmin() && $user->organization_id === null) {
            return false;
        }

        return app(EmployeeOrgGuard::class)->sameOrganizationForProfile($user, $profile);
    }
}
