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
 *   - توحيد same-org gate عبر KpiOrgGuard للكتابة.
 *   - إعطاء الـ Gate نقطة تسجيل صريحة (Gate::policy) لتوجيه
 *     view/create/update/delete من الكنترولرات و authorization helpers.
 *
 * السلوك:
 *  - super_admin ⇒ true دائماً (via Gate::before + before()).
 *  - actor بلا organization_id ⇒ deny.
 *  - kpi من منظمة أخرى ⇒ deny (مع استثناء cluster_tree في view فقط، أدناه).
 *  - kpi بلا organization_id ⇒ deny (orphan).
 *  - KPIS_VIEW للقراءة، KPIS_MANAGE للتعديل/الحذف/الإنشاء.
 *
 * Phase 9-D-D1a — Cluster tree read widening:
 *   - view() يسمح بـ AccessDecision::can(CLUSTER_TREE_VIEW, $kpi) كمسار ثانٍ
 *     إذا وفقط إذا كان actor يحمل Capability::KPIS_VIEW + CLUSTER_TREE_VIEW
 *     على actor.organization_id. الـ rescue branch في الـ engine يتحقّق من
 *     الـ ancestor walk + non-sensitive target.
 *   - update / delete / create / manage تبقى كما هي (strict same-org عبر precheck).
 *   - لا يُوسّع لاكتساب كتابة في أيّ موديول آخر.
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

    /**
     * Phase 9-D-D1a — Cluster tree widening applies to view() only.
     *
     * مسارات القرار:
     *  1) KPIS_VIEW على kpi (نفس المنظمة): engine's same-org + role check.
     *  2) CLUSTER_TREE_VIEW على kpi (cluster ancestor): engine's rescue branch
     *     يتحقّق من ancestor walk + non-sensitive + scoped-role grant. لا يُفعَّل
     *     إلا إذا كان actor يحمل Capability::KPIS_VIEW + CLUSTER_TREE_VIEW
     *     على actor.organization_id — فحصان صريحان قبل الـ rescue.
     *
     * غياب أيّ من القدرةَين ⇒ deny. الكتابة لا تتأثّر (تذهب عبر update/delete/create).
     */
    public function view(User $user, Kpi $kpi): bool
    {
        // super_admin يُعالَج في الـ engine (short-circuit في whyCan::step 1).
        // null-org actor يُعالَج في الـ engine (org_isolation_denied في step 2).

        // Path 1: same-org KPIS_VIEW via engine.
        if (AccessDecision::can($user, Capability::KPIS_VIEW, $kpi)) {
            return true;
        }

        // Path 2: cross-org cluster_tree widening — requires BOTH entitlements on actor.org.
        if (! AccessDecision::can($user, Capability::KPIS_VIEW)) {
            return false;
        }
        if (! AccessDecision::can($user, Capability::CLUSTER_TREE_VIEW)) {
            return false;
        }

        return AccessDecision::can($user, Capability::CLUSTER_TREE_VIEW, $kpi);
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
     *
     * يُستخدم في الكتابة فقط (update / delete) — لا يُطبَّق على view() لأن
     * الـ cluster_tree widening يحتاج path ثاني خارج strict same-org.
     */
    protected function precheck(User $user, Kpi $kpi): bool
    {
        if (! $user->isSuperAdmin() && $user->organization_id === null) {
            return false;
        }

        return app(KpiOrgGuard::class)->sameOrganizationForKpi($user, $kpi);
    }
}
