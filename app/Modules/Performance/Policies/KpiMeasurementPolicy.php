<?php

namespace App\Modules\Performance\Policies;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\User;
use App\Modules\Performance\Models\Kpi;
use App\Modules\Performance\Models\KpiMeasurement;
use App\Modules\Performance\Support\KpiOrgGuard;

/**
 * KpiMeasurementPolicy - Phase 4: per-record org-isolation for KpiMeasurement.
 *
 * تسجيل القياس هو عملية edit على KPI (يحدّث current_value عبر booted hook).
 * لذا:
 *  - view يستخدم KPIS_VIEW.
 *  - create/update يستخدم KPIS_MANAGE.
 *  - delete غير موجود في الـ routes (لا يوجد DELETE endpoint للقياسات).
 *
 * الـ same-org gate يتحقّق من measurement.organization_id مباشرةً
 * (العمود موجود في kpi_measurements).
 */
class KpiMeasurementPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return null;
    }

    public function viewAny(User $user, ?Kpi $kpi = null): bool
    {
        if (! $user->isSuperAdmin() && $user->organization_id === null) {
            return false;
        }

        return AccessDecision::can($user, Capability::KPIS_VIEW);
    }

    public function view(User $user, KpiMeasurement $measurement): bool
    {
        if (! $this->precheck($user, $measurement)) {
            return false;
        }

        return AccessDecision::can($user, Capability::KPIS_VIEW);
    }

    public function create(User $user, ?Kpi $kpi = null): bool
    {
        if (! $user->isSuperAdmin() && $user->organization_id === null) {
            return false;
        }

        // إذا تم تمرير kpi، نتحقق من same-org (المسار create عبر /kpis/{kpi}/measurements).
        if ($kpi !== null && ! app(KpiOrgGuard::class)->sameOrganizationForKpi($user, $kpi)) {
            return false;
        }

        return AccessDecision::can($user, Capability::KPIS_MANAGE);
    }

    public function update(User $user, KpiMeasurement $measurement): bool
    {
        if (! $this->precheck($user, $measurement)) {
            return false;
        }

        return AccessDecision::can($user, Capability::KPIS_MANAGE);
    }

    public function delete(User $user, KpiMeasurement $measurement): bool
    {
        if (! $this->precheck($user, $measurement)) {
            return false;
        }

        return AccessDecision::can($user, Capability::KPIS_MANAGE);
    }

    /**
     * precheck: actor/org gate + same-org عبر KpiOrgGuard.
     */
    protected function precheck(User $user, KpiMeasurement $measurement): bool
    {
        if (! $user->isSuperAdmin() && $user->organization_id === null) {
            return false;
        }

        return app(KpiOrgGuard::class)->sameOrganizationForMeasurement($user, $measurement);
    }
}
