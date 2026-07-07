<?php

namespace App\Modules\Performance\Support;

use App\Modules\Core\Models\User;
use App\Modules\Performance\Models\Kpi;
use App\Modules\Performance\Models\KpiLink;
use App\Modules\Performance\Models\KpiMeasurement;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * KpiOrgGuard - اشتقاق organization_id وفحص العزل على مستوى المؤسسة لموديول Performance.
 *
 * هذه الـ Helper هي المرجع الوحيد لاستخراج `organization_id` لأي كيان
 * يخصّ مؤشرات الأداء. لا تُكرّر في الـ FormRequests أو الـ Policies أو
 * الكنترولرات. كل الكيانات الفرعية (KpiMeasurement, KpiLink) تحمل
 * organization_id مباشرة، فالاشتقاق مباشر من العمود.
 *
 * القواعد الموحّدة (تطابق UserKpiScope و EmployeeOrgGuard):
 *   - super_admin ⇒ مسموح دائماً.
 *   - actor بلا organization_id ⇒ مرفوض (fail-closed).
 *   - targetOrgId = null ⇒ مرفوض (orphaned record).
 *   - mismatch ⇒ مرفوض.
 *
 * Phase 4 — Performance Org-Isolation: حلّ محل inline checks
 * (e.g. (int) $user->organization_id !== (int) $kpi->organization_id)
 * الموزّعة في 4 FormRequests.
 */
class KpiOrgGuard
{
    /**
     * استخراج organization_id من Kpi مباشرةً.
     */
    public function kpiOrgId(?Kpi $kpi): ?int
    {
        if ($kpi === null) {
            return null;
        }

        return $kpi->organization_id !== null
            ? (int) $kpi->organization_id
            : null;
    }

    /**
     * استخراج organization_id من KpiMeasurement مباشرةً.
     * العمود موجود في kpi_measurements (FK NOT NULL).
     */
    public function measurementOrgId(?KpiMeasurement $measurement): ?int
    {
        if ($measurement === null) {
            return null;
        }

        return $measurement->organization_id !== null
            ? (int) $measurement->organization_id
            : null;
    }

    /**
     * استخراج organization_id من KpiLink مباشرةً.
     * العمود موجود في kpi_links (FK NOT NULL).
     */
    public function linkOrgId(?KpiLink $link): ?int
    {
        if ($link === null) {
            return null;
        }

        return $link->organization_id !== null
            ? (int) $link->organization_id
            : null;
    }

    /**
     * فحص Same-Organization بين actor و targetOrgId.
     *
     * - super_admin ⇒ true دائماً.
     * - actor بلا organization_id ⇒ false (fail-closed).
     * - targetOrgId null ⇒ false.
     * - mismatch ⇒ false.
     * - match ⇒ true.
     */
    public function sameOrganization(User $actor, ?int $targetOrgId): bool
    {
        if ($actor->isSuperAdmin()) {
            return true;
        }

        if ($actor->organization_id === null) {
            return false;
        }

        if ($targetOrgId === null) {
            return false;
        }

        return (int) $actor->organization_id === (int) $targetOrgId;
    }

    /**
     * فحص Same-Organization لـ Kpi target.
     */
    public function sameOrganizationForKpi(User $actor, ?Kpi $kpi): bool
    {
        return $this->sameOrganization($actor, $this->kpiOrgId($kpi));
    }

    /**
     * فحص Same-Organization لـ KpiMeasurement target.
     */
    public function sameOrganizationForMeasurement(User $actor, ?KpiMeasurement $measurement): bool
    {
        return $this->sameOrganization($actor, $this->measurementOrgId($measurement));
    }

    /**
     * فحص Same-Organization لـ KpiLink target.
     */
    public function sameOrganizationForLink(User $actor, ?KpiLink $link): bool
    {
        return $this->sameOrganization($actor, $this->linkOrgId($link));
    }

    /**
     * abort مع AccessDeniedHttpException إن لم يكن same-org.
     * للاستخدام في الكنترولر حيث النمط يرمي بدلاً من إرجاع false.
     */
    public function abortUnlessSameOrganization(User $actor, ?int $targetOrgId): void
    {
        if (! $this->sameOrganization($actor, $targetOrgId)) {
            throw new AccessDeniedHttpException('مؤشر الأداء خارج نطاق مؤسستك');
        }
    }
}
