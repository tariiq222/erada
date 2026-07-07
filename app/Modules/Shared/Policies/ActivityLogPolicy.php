<?php

namespace App\Modules\Shared\Policies;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\User;
use App\Modules\Shared\Models\ActivityLog;

/**
 * ActivityLogPolicy - عزل سجل النشاط على مستوى المؤسسة.
 *
 * - super_admin ⇒ يرى الكل (يشمل organization_id IS NULL events).
 * - مستخدم عادي ⇒ يرى السجل فقط إذا organization_id === user.organization_id.
 * - السجل بلا organization_id ⇒ لا يُعرض لغير super_admin (fail-closed).
 */
class ActivityLogPolicy
{
    /**
     * هل يستطيع المستخدم رؤية قائمة سجلات النشاط؟
     */
    public function viewAny(User $user): bool
    {
        return AccessDecision::can($user, Capability::AUDIT_VIEW);
    }

    /**
     * هل يستطيع المستخدم تصدير سجلات النشاط؟
     */
    public function viewAnyForExport(User $user): bool
    {
        return AccessDecision::can($user, Capability::AUDIT_EXPORT);
    }

    /**
     * هل يستطيع المستخدم رؤية سجلّ نشاط واحد؟
     */
    public function view(User $user, ActivityLog $activityLog): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if ($activityLog->organization_id === null) {
            return false;
        }

        if ($user->organization_id === null) {
            return false;
        }

        return (int) $activityLog->organization_id === (int) $user->organization_id;
    }
}
