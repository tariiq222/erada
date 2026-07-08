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
 *
 * Phase 9-D-D1a — Cluster tree widening applies to view() only:
 *   - measurement inherits org من kpi الأب (denormalized at write time).
 *   - view() يستخدم نفس نمط الـ engine rescue: KPIS_VIEW || CLUSTER_TREE_VIEW.
 *   - create / update تبقى strict same-org عبر precheck.
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

    /**
     * Phase 9-D-D1a — Cluster tree widening applies to view() only.
     *
     * مسارات القرار (نفس نمط KpiPolicy::view):
     *  1) KPIS_VIEW على measurement (نفس المنظمة): engine's same-org + role check.
     *  2) CLUSTER_TREE_VIEW على measurement: engine's rescue branch يتحقّق من
     *     ancestor walk عبر measurement.organization_id (المطابق لـ kpi الأب).
     */
    public function view(User $user, KpiMeasurement $measurement): bool
    {
        // super_admin يُعالَج في الـ engine (short-circuit في whyCan::step 1).
        // null-org actor يُعالَج في الـ engine (org_isolation_denied في step 2).

        // Path 1: same-org KPIS_VIEW via engine.
        if (AccessDecision::can($user, Capability::KPIS_VIEW, $measurement)) {
            return true;
        }

        // Path 2: cross-org cluster_tree widening — requires BOTH entitlements on actor.org.
        if (! AccessDecision::can($user, Capability::KPIS_VIEW)) {
            return false;
        }
        if (! AccessDecision::can($user, Capability::CLUSTER_TREE_VIEW)) {
            return false;
        }

        return AccessDecision::can($user, Capability::CLUSTER_TREE_VIEW, $measurement);
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
     *
     * يُستخدم في الكتابة فقط (update / delete) — لا يُطبَّق على view().
     */
    protected function precheck(User $user, KpiMeasurement $measurement): bool
    {
        if (! $user->isSuperAdmin() && $user->organization_id === null) {
            return false;
        }

        return app(KpiOrgGuard::class)->sameOrganizationForMeasurement($user, $measurement);
    }
}
