<?php

namespace App\Modules\HR\Policies;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\EmployeePersonalInfo;
use App\Modules\HR\Support\EmployeeOrgGuard;

/**
 * EmployeePersonalInfoPolicy - Phase 2: per-record org-isolation for personal info.
 *
 * EmployeePersonalInfo يحمل بيانات حساسة (national_id, iqama, address) ولا
 * يحمل organization_id مباشرة. الـ org يُشتق عبر
 * employeeProfile.user.organization_id (سلسلة علاقتين).
 *
 * السلوك مطابق لـ EmployeeProfilePolicy لكن البوابة هي HR_VIEW للقراءة
 * و HR_MANAGE للتعديل/الحذف. self-access (الموظف نفسه) مسموح دائماً
 * كـ owner-floor حتى لو لم يملك HR_VIEW (pattern يطابق ما في EmployeeController).
 */
class EmployeePersonalInfoPolicy
{
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

    public function view(User $user, EmployeePersonalInfo $info): bool
    {
        if (! $this->precheck($user, $info)) {
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

    public function update(User $user, EmployeePersonalInfo $info): bool
    {
        if (! $this->precheck($user, $info)) {
            return false;
        }

        return AccessDecision::can($user, Capability::HR_MANAGE);
    }

    public function delete(User $user, EmployeePersonalInfo $info): bool
    {
        if (! $this->precheck($user, $info)) {
            return false;
        }

        return AccessDecision::can($user, Capability::HR_MANAGE);
    }

    /**
     * precheck: actor/org + same-org عبر EmployeeOrgGuard. orphan records
     * (info بلا employeeProfile) ترفض لأن الـ org غير قابل للاشتقاق.
     */
    protected function precheck(User $user, EmployeePersonalInfo $info): bool
    {
        if (! $user->isSuperAdmin() && $user->organization_id === null) {
            return false;
        }

        return app(EmployeeOrgGuard::class)->sameOrganizationForPersonalInfo($user, $info);
    }
}
