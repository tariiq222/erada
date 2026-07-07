<?php

namespace App\Modules\Performance\Policies;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\User;
use App\Modules\Performance\Models\Kpi;
use App\Modules\Performance\Models\KpiLink;
use App\Modules\Performance\Support\KpiOrgGuard;

/**
 * KpiLinkPolicy - Phase 4: per-record org-isolation for KpiLink.
 *
 * عمليات link تستخدم KPIS_MANAGE (تعديل على الـ KPI):
 *  - view عبر /kpis/{kpi}/links (route-bound kpi) ⇒ KPIS_VIEW.
 *  - create/update عبر /kpis/{kpi}/links ⇒ KPIS_MANAGE.
 *  - destroy ⇒ KPIS_MANAGE.
 *
 * الـ same-org gate يتحقّق من link.organization_id مباشرةً
 * (العمود موجود في kpi_links).
 */
class KpiLinkPolicy
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

    public function view(User $user, KpiLink $link): bool
    {
        if (! $this->precheck($user, $link)) {
            return false;
        }

        return AccessDecision::can($user, Capability::KPIS_VIEW);
    }

    public function create(User $user, ?Kpi $kpi = null): bool
    {
        if (! $user->isSuperAdmin() && $user->organization_id === null) {
            return false;
        }

        // إذا تم تمرير kpi، نتحقق من same-org (المسار create عبر /kpis/{kpi}/links).
        if ($kpi !== null && ! app(KpiOrgGuard::class)->sameOrganizationForKpi($user, $kpi)) {
            return false;
        }

        return AccessDecision::can($user, Capability::KPIS_MANAGE);
    }

    public function update(User $user, KpiLink $link): bool
    {
        if (! $this->precheck($user, $link)) {
            return false;
        }

        return AccessDecision::can($user, Capability::KPIS_MANAGE);
    }

    public function delete(User $user, KpiLink $link): bool
    {
        if (! $this->precheck($user, $link)) {
            return false;
        }

        return AccessDecision::can($user, Capability::KPIS_MANAGE);
    }

    /**
     * precheck: actor/org gate + same-org عبر KpiOrgGuard.
     */
    protected function precheck(User $user, KpiLink $link): bool
    {
        if (! $user->isSuperAdmin() && $user->organization_id === null) {
            return false;
        }

        return app(KpiOrgGuard::class)->sameOrganizationForLink($user, $link);
    }
}
