<?php

namespace App\Modules\Shared\Traits;

use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * عزل المؤسسة (deny-not-bypass) المشترك عبر الموديولات.
 *
 * super_admin يتجاوز العزل؛ أي مستخدم آخر بلا organization_id يُرفض،
 * وأي عنصر بلا organization_id يُرفض، والاختلاف بين المنظمتين يُرفض.
 */
trait HasOrganizationScope
{
    /**
     * نمط الـ Controllers: يرمي 403 عند اختلاف المؤسسة.
     * يُستدعى بعد ربط الموديل عبر الراوت وقبل أي تعديل.
     */
    protected function assertSameOrganization(mixed $model): void
    {
        $user = Auth::user();

        if ($user?->isSuperAdmin()) {
            return;
        }

        $userOrgId = $user?->organization_id;

        if ($userOrgId === null
            || ! isset($model->organization_id)
            || $model->organization_id !== $userOrgId) {
            abort(403, 'ليس لديك صلاحية الوصول لهذا العنصر');
        }
    }

    /**
     * نمط الـ Policies: يعيد bool. super_admin يُعالَج في before()،
     * ودفاعياً نعيد true له هنا أيضاً.
     */
    protected function sharesOrganization(User $user, ?int $modelOrgId): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if ($user->organization_id === null || $modelOrgId === null) {
            return false;
        }

        return $user->organization_id === $modelOrgId;
    }
}
