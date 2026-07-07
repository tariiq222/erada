<?php

namespace App\Modules\Core\Scopes;

use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * UserOrganizationScope - الفلتر الموحّد لعزل قوائم المستخدمين على مستوى المؤسسة.
 *
 * هذا هو المكان الوحيد الذي يطبّق فلتر organization_id (وقسم subtree لغير admin)
 * على استعلامات User. لا يُعاد تنفيذه في أي Controller. عند الإضافة على Builder أي
 * استعلام Eloquent يخصّ User، يجب استدعاء applyToUsers.
 *
 * السلوك (يطابق UserController::applyUserVisibility السابق byte-for-byte):
 *   - super_admin: لا فلتر (يرى الكل).
 *   - actor بلا organization_id: whereRaw('false') — fail-closed (لا يرى شيئاً).
 *   - actor بـ admin (organization-wide role): كل مستخدمي المؤسسة.
 *   - غير admin: مستخدمو المؤسسة داخل القسم الفرعي المُدار (subtree) + قسمه الخاص.
 *
 * لا تعتمد على سلسلة الأقسام الهرمية لأبعد من الـ dept subtree الحالي؛ الـ
 * AccessDecision engine يتولّى التفصيل الهرفي عبر scope-chain للـ Per-target
 * abilities. هذا الـ Scope مسؤول فقط عن الـ horizontal org floor + dept narrowing
 * لقوائم الـ index/list/stats.
 *
 * Phase 3 — minimal-risk: السلوك مطابق تمامًا للمنطق المُهاجَر من
 * UserController::applyUserVisibility (لا توسيع رؤية، لا تغيير semantics).
 */
class UserOrganizationScope
{
    /**
     * فلتر استعلام User.
     *
     * يُستخدم في UserController::index / stats / list.
     *
     * @param  Builder<User>  $query
     */
    public function applyToUsers(Builder $query, User $actor): Builder
    {
        // super_admin: لا فلتر (يرى كل المستخدمين عبر كل المؤسسات).
        if ($actor->isSuperAdmin()) {
            return $query;
        }

        // actor بلا organization_id: fail-closed — لا يرى أي مستخدم.
        if ($actor->organization_id === null) {
            return $query->whereRaw('false');
        }

        // org floor: كل مستخدم يجب أن يكون في نفس المؤسسة.
        $query->where('users.organization_id', $actor->organization_id);

        // admin (organization-wide role عبر AccessDecision::can(SETTINGS_MANAGE)):
        // يرى كل مستخدمي المؤسسة بدون dept narrowing.
        if ($actor->isAdmin()) {
            return $query;
        }

        // غير admin: dept subtree فقط.
        $deptIds = $this->resolveUserDepartmentSubtree($actor);

        // ponytail: [0] sentinel — مستخدم بلا قسم ولا managed departments يرى لا أحد
        // (لا ينتج عنه "whereIn()" فارغ قد يطابق الكل في بعض drivers).
        return $query->whereIn('users.department_id', $deptIds ?: [0]);
    }

    /**
     * قائمة معرّفات الأقسام المرئية للـ actor: managed departments + own department.
     *
     * مطابق للمنطق في UserController::applyUserVisibility السابق:
     *   - getManagedDepartmentIds() يفترض أنه يوسّع للأبناء (السلوك المحفوظ).
     *   - نضيف قسم الـ actor نفسه لضمان أن member يرى زملاءه في نفس القسم.
     *
     * @return array<int, int>
     */
    private function resolveUserDepartmentSubtree(User $actor): array
    {
        $managed = $actor->getManagedDepartmentIds();
        $own = $actor->department_id !== null ? [(int) $actor->department_id] : [];

        $ids = array_values(array_unique(array_filter(array_merge($managed, $own))));

        return $ids;
    }
}
