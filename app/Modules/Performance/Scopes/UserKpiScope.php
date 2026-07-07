<?php

namespace App\Modules\Performance\Scopes;

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
 * لا تعتمد على السلسلة الهرمية للأقسام؛ الـ AccessDecision engine يتولّى
 * التفصيل الهرمي عبر scope-chain. هذا الـ Scope مسؤول فقط عن القطع
 * الأفقي لمؤسسة المستخدم (org isolation floor).
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

        return $query->where('kpis.organization_id', $actor->organization_id);
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

        return $query->whereHas(
            'kpi',
            fn (Builder $k) => $k->where('kpis.organization_id', $actor->organization_id)
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

        return $query->whereHas(
            'kpi',
            fn (Builder $k) => $k->where('kpis.organization_id', $actor->organization_id)
        );
    }
}
