<?php

namespace App\Modules\HR\Scopes;

use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * UserEmployeeScope - الفلتر الموحّد لعزل قوائم الموظفين على مستوى المؤسسة.
 *
 * هذا هو المكان الوحيد الذي يطبّق فلتر organization_id على استعلامات
 * User/EmployeeProfile/EmployeeCertificate/EmployeePersonalInfo.
 * لا يُعاد تنفيذه في أي Controller. عند الإضافة على Builder أي استعلام
 * Eloquent يخصّ هذه الكيانات، يجب استدعاء applyTo* المناسب.
 *
 * السلوك (لكل variant):
 *   - super_admin: لا فلتر.
 *   - actor بلا organization_id: whereRaw('false') — fail-closed (لا يرى شيئاً).
 *   - actor عادي: فلتر organization_id عبر العلاقة الصحيحة.
 *
 * لا تعتمد على السلسلة الهرمية للأقسام؛ الـ AccessDecision engine يتولّى
 * التفصيل الهرمي عبر scope-chain. هذا الـ Scope مسؤول فقط عن القطع
 * الأفقي لمؤسسة المستخدم (org isolation floor).
 *
 * تم تصميمه ليتوسّع لاحقًا إذا فُصل Employee عن User Account
 * (Phase HR-Model المقترحة): الـ methods تقبل Builder لا Model محدد.
 */
class UserEmployeeScope
{
    /**
     * فلتر استعلام User (employees = users + HR enrichment).
     * المستخدم في EmployeeController::index / statistics / show.
     */
    public function applyToUsers(Builder $query, User $actor): Builder
    {
        if ($actor->isSuperAdmin()) {
            return $query;
        }

        if ($actor->organization_id === null) {
            return $query->whereRaw('false');
        }

        return $query->where('users.organization_id', $actor->organization_id);
    }

    /**
     * فلتر استعلام EmployeeProfile عبر user relation.
     */
    public function applyToProfiles(Builder $query, User $actor): Builder
    {
        if ($actor->isSuperAdmin()) {
            return $query;
        }

        if ($actor->organization_id === null) {
            return $query->whereRaw('false');
        }

        return $query->whereHas(
            'user',
            fn (Builder $u) => $u->where('users.organization_id', $actor->organization_id)
        );
    }

    /**
     * فلتر استعلام EmployeeCertificate عبر employeeProfile.user relation.
     */
    public function applyToCertificates(Builder $query, User $actor): Builder
    {
        if ($actor->isSuperAdmin()) {
            return $query;
        }

        if ($actor->organization_id === null) {
            return $query->whereRaw('false');
        }

        return $query->whereHas(
            'employeeProfile.user',
            fn (Builder $u) => $u->where('users.organization_id', $actor->organization_id)
        );
    }

    /**
     * فلتر استعلام EmployeePersonalInfo عبر employeeProfile.user relation.
     */
    public function applyToPersonalInfo(Builder $query, User $actor): Builder
    {
        if ($actor->isSuperAdmin()) {
            return $query;
        }

        if ($actor->organization_id === null) {
            return $query->whereRaw('false');
        }

        return $query->whereHas(
            'employeeProfile.user',
            fn (Builder $u) => $u->where('users.organization_id', $actor->organization_id)
        );
    }
}
