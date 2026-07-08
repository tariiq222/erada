<?php

namespace App\Modules\Performance\Scopes;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * UserKpiScope - الفلتر الموحّد لعزل قوائم مؤشرات الأداء على مستوى المؤسسة.
 *
 * هذا هو المكان الوحيد الذي يطبّق فلتر organization_id على استعلامات
 * Kpi / KpiMeasurement / KpiLink. لا يُعاد تنفيذه في أي Controller.
 * عند الإضافة على Builder أي استعلام Eloquent يخصّ هذه الكيانات،
 * يجب استدعاء applyTo* المناسب.
 *
 * السلوك (لكل variant):
 *   - super_admin: لا فلتر.
 *   - actor بلا organization_id: whereRaw('false') — fail-closed (لا يرى شيئاً).
 *   - actor عادي: فلتر organization_id مباشرة على عمود الجدول.
 *
 * Phase 9-D-D1a — Cluster tree widening (read-only):
 *   - actor يحمل Capability::KPIS_VIEW + Capability::CLUSTER_TREE_VIEW على
 *     actor.organization_id ⇒ الفلتر يتوسّع ليشمل المؤسسات الفرعية (descendants).
 *   - شروط الـ widening: لا wildcard، لا is_admin_role shortcut، read-only.
 *   - لا يُوسّع لـ siblings (one-directional: user.org ⇒ descendants).
 *
 * لا تعتمد على السلسلة الهرمية للأقسام؛ الـ AccessDecision engine يتولّى
 * التفصيل الهرمي عبر scope-chain. هذا الـ Scope مسؤول فقط عن القطع
 * الأفقي لمؤسسة المستخدم (org isolation floor) مع توسيع cluster_tree.
 *
 * Phase 4 — Performance Org-Isolation: تم إنشاؤه لتقوية العزل الأفقي
 * لموديول Performance بعد أن تبيّن أن scopeToCurrentOrganization كان
 * مكرّراً inline في 3 كنترولرات.
 */
class UserKpiScope
{
    /**
     * فلتر استعلام Kpi (المؤشرات نفسها).
     * يُستخدم في KpiController::filteredKpiQuery و contextKpis وغيرها.
     */
    public function applyToKpis(Builder $query, User $actor): Builder
    {
        if ($actor->isSuperAdmin()) {
            return $query;
        }

        if ($actor->organization_id === null) {
            return $query->whereRaw('false');
        }

        return $query->whereIn('kpis.organization_id', $this->clusterVisibleOrgIds($actor));
    }

    /**
     * فلتر استعلام KpiMeasurement عبر كpi الأب.
     * يطبّق الفلتر على العلاقة kpi.organization_id لأن القياسات دائماً
     * تُقرأ من خلال سياق KPI.
     */
    public function applyToMeasurements(Builder $query, User $actor): Builder
    {
        if ($actor->isSuperAdmin()) {
            return $query;
        }

        if ($actor->organization_id === null) {
            return $query->whereRaw('false');
        }

        $visibleOrgIds = $this->clusterVisibleOrgIds($actor);

        return $query->whereHas(
            'kpi',
            fn (Builder $k) => $k->whereIn('kpis.organization_id', $visibleOrgIds)
        );
    }

    /**
     * فلتر استعلام KpiLink عبر kpi الأب.
     */
    public function applyToLinks(Builder $query, User $actor): Builder
    {
        if ($actor->isSuperAdmin()) {
            return $query;
        }

        if ($actor->organization_id === null) {
            return $query->whereRaw('false');
        }

        $visibleOrgIds = $this->clusterVisibleOrgIds($actor);

        return $query->whereHas(
            'kpi',
            fn (Builder $k) => $k->whereIn('kpis.organization_id', $visibleOrgIds)
        );
    }

    /**
     * قائمة بمعرفات المنظمات المرئية للمستخدم حسب سياسات الـ cluster_tree.
     *
     * - الافتراضي: [actor.organization_id] فقط (strict same-org). يحافظ على
     *   سلوك UserKpiScope ما قبل 9-D-D1a (المستخدم بلا capability grants يرى
     *   منظمته فقط) لتفادي كسر الـ regression tests القائمة.
     *
     * - التوسّع (read-only): إذا وفقط إذا كان actor يحمل
     *   Capability::KPIS_VIEW + Capability::CLUSTER_TREE_VIEW على actor.organization_id،
     *   تُضاف المؤسسات الفرعية (descendants عبر parent_id) إلى القائمة.
     *
     * شروط الـ widening:
     *   - KPIS_VIEW + CLUSTER_TREE_VIEW: كلاهما مطلوب (CLUSTER_TREE_VIEW وحده
     *     لا يكفي — يلزم الـ engine capability لضمان أن actor يحق له أصلاً
     *     رؤية KPIs).
     *   - لا يعتمد على is_admin_role. لا يُوسّع لـ siblings.
     *   - لا يعتمد على materialized path — يستخدم parent_id + visited set + depth cap 32.
     *
     * @return list<int>
     */
    protected function clusterVisibleOrgIds(User $actor): array
    {
        $orgId = (int) $actor->organization_id;
        $visible = [$orgId];

        // كلا القدرةَين مطلوبتين لتوسيع cluster_tree. غياب أيّ منهما ⇒ strict same-org.
        $hasKpisView = AccessDecision::can($actor, Capability::KPIS_VIEW);
        $hasClusterTreeView = AccessDecision::can($actor, Capability::CLUSTER_TREE_VIEW);
        if (! $hasKpisView || ! $hasClusterTreeView) {
            return $visible;
        }

        $org = Organization::query()->find($orgId);
        if (! $org instanceof Organization) {
            return $visible;
        }

        return array_values(array_unique(array_merge($visible, $org->descendantIds())));
    }
}
