<?php

namespace App\Modules\Performance\Policies;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\User;
use App\Modules\Performance\Models\Kpi;
use App\Modules\Performance\Support\KpiOrgGuard;

/**
 * KpiPolicy - Phase 4: per-record org-isolation for Kpi.
 *
 * الـ engine `AccessDecision::can($user, Capability::KPIS_*, $kpi)` يستطيع
 * اشتقاق organization_id لأن Kpi يطبّق ScopeAware ويحمل العمود مباشرةً.
 * ومع ذلك نضيف هذه الـ Policy كحاجز موحّد (نمط Phase 2/3) لـ:
 *   - توحيد fail-closed على null-org actor.
 *   - توحيد same-org gate عبر KpiOrgGuard.
 *   - إعطاء الـ Gate نقطة تسجيل صريحة (Gate::policy) لتوجيه
 *     view/create/update/delete من الكنترولرات و authorization helpers.
 *
 * السلوك:
 *  - super_admin ⇒ true دائماً (via Gate::before + before()).
 *  - actor بلا organization_id ⇒ deny.
 *  - kpi من منظمة أخرى ⇒ deny.
 *  - kpi بلا organization_id ⇒ deny (orphan).
 *  - KPIS_VIEW للقراءة، KPIS_MANAGE للتعديل/الحذف/الإنشاء.
 *
 * لا تعتمد على Spatie direct. الـ Capability constants تمر عبر AccessDecision
 * ليتحقّق المحرك من الأدوار السياقية.
 */
class KpiPolicy
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

        return AccessDecision::can($user, Capability::KPIS_VIEW);
    }

    public function view(User $user, Kpi $kpi): bool
    {
        if (! $this->precheck($user, $kpi)) {
            return false;
        }

        return AccessDecision::can($user, Capability::KPIS_VIEW);
    }

    public function create(User $user): bool
    {
        if (! $user->isSuperAdmin() && $user->organization_id === null) {
            return false;
        }

        return AccessDecision::can($user, Capability::KPIS_MANAGE);
    }

    public function update(User $user, Kpi $kpi): bool
    {
        if (! $this->precheck($user, $kpi)) {
            return false;
        }

        return AccessDecision::can($user, Capability::KPIS_MANAGE);
    }

    public function delete(User $user, Kpi $kpi): bool
    {
        if (! $this->precheck($user, $kpi)) {
            return false;
        }

        return AccessDecision::can($user, Capability::KPIS_MANAGE);
    }

    /**
     * precheck: actor/org gate + same-org عبر KpiOrgGuard.
     */
    protected function precheck(User $user, Kpi $kpi): bool
    {
        if (! $user->isSuperAdmin() && $user->organization_id === null) {
            return false;
        }

        return app(KpiOrgGuard::class)->sameOrganizationForKpi($user, $kpi);
    }
}
