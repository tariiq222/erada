<?php

namespace App\Modules\Shared\Scopes;

use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * UserActivityLogScope - الفلتر الموحّد لعزل سجلات النشاط على مستوى المؤسسة.
 *
 * هذا هو المكان الوحيد الذي يطبّق فلتر organization_id على ActivityLog.
 * لا يُعاد تنفيذه في أي Controller. عند الإضافة على Builder استعلام Eloquent
 * يخصّ ActivityLog، يجب استدعاء apply($query, $user).
 *
 * - super_admin: لا فلتر (يشمل organization_id IS NULL events النظامية).
 * - مستخدم عادي بلا organization_id: whereRaw(false) (fail-closed — لا يرى شيئاً).
 * - مستخدم عادي له organization_id: where('organization_id', $user->organization_id).
 */
class UserActivityLogScope
{
    /**
     * تطبيق الفلتر على الـ query.
     */
    public function apply(Builder $query, User $user): Builder
    {
        if ($user->isSuperAdmin()) {
            return $query;
        }

        if ($user->organization_id === null) {
            // fail-closed: مستخدم بلا مؤسسة لا يرى أي سجل نشاط.
            return $query->whereRaw('false');
        }

        return $query->where('activity_logs.organization_id', $user->organization_id);
    }
}
