<?php

namespace App\Modules\HR\Policies;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\EmployeeCertificate;
use App\Modules\HR\Support\EmployeeOrgGuard;

/**
 * EmployeeCertificatePolicy - Phase 2: per-record org-isolation for certificates.
 *
 * EmployeeCertificate لا يحمل organization_id. الـ org يُشتق عبر
 * employeeProfile.user.organization_id. الـ download المسار يعتمد على
 * signed URL فالـ FormRequest يحتوي الفحص الفعلي، لكن هذه الـ Policy
 * متاحة لأي استهلاك لاحق (admin UI، listing، إلخ).
 *
 * Orphan certificates (بلا employeeProfile أو بلا user مرتبط) ترفض
 * صراحةً — لا يمكن اشتقاق org منها.
 */
class EmployeeCertificatePolicy
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

    public function view(User $user, EmployeeCertificate $certificate): bool
    {
        if (! $this->precheck($user, $certificate)) {
            return false;
        }

        return AccessDecision::can($user, Capability::HR_VIEW);
    }

    /**
     * Download is gated by HR_VIEW (read-only). Same-org via EmployeeOrgGuard.
     */
    public function download(User $user, EmployeeCertificate $certificate): bool
    {
        if (! $this->precheck($user, $certificate)) {
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

    public function delete(User $user, EmployeeCertificate $certificate): bool
    {
        if (! $this->precheck($user, $certificate)) {
            return false;
        }

        return AccessDecision::can($user, Capability::HR_MANAGE);
    }

    /**
     * precheck: actor/org + same-org عبر EmployeeOrgGuard.
     */
    protected function precheck(User $user, EmployeeCertificate $certificate): bool
    {
        if (! $user->isSuperAdmin() && $user->organization_id === null) {
            return false;
        }

        return app(EmployeeOrgGuard::class)->sameOrganizationForCertificate($user, $certificate);
    }
}
