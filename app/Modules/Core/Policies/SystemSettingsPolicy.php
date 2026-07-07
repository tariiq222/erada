<?php

namespace App\Modules\Core\Policies;

use App\Modules\Core\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * سياسة الوصول لإعدادات النظام
 *
 * فقط Super Admin و Admin يمكنهم تعديل إعدادات النظام
 */
class SystemSettingsPolicy
{
    use HandlesAuthorization;

    /**
     * Super Admin يتجاوز كل الصلاحيات
     *
     * Defense-in-depth: the engine's super_admin bypass (step 1 of whyCan) already
     * grants every capability, so SETTINGS_EDIT returns true for super_admin.
     * Keeping this before() means legacy `User->can('update', SystemSetting::...)`
     * call sites (without a target) also short-circuit before reaching the engine.
     */
    public function before(User $user, string $ability): ?bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return null;
    }

    /**
     * عرض إعدادات النظام - متاح للجميع (القراءة عامة)
     */
    public function viewAny(): bool
    {
        return true;
    }

    /**
     * عرض إعدادات النظام - متاح للجميع
     */
    public function view(?User $user): bool
    {
        return true;
    }

    /**
     * تحديث إعدادات النظام
     *
     * Global system settings are platform-wide (no organization_id), so only a
     * super admin may write them — never an org-scoped admin (M-01). The
     * super_admin short-circuit in before() already returns true; an org admin
     * carrying SETTINGS_EDIT must NOT pass here.
     */
    public function update(User $user): bool
    {
        return $user->isSuperAdmin();
    }
}
