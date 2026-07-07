<?php

namespace App\Modules\Strategy\Policies;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\User;
use App\Modules\Strategy\Models\Portfolio;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * PortfolioPolicy — سياسة صلاحيات المحافظ (Portfolios)
 *
 * engine-only: يعتمد كلياً على AccessDecision::can().
 * المنطق القديم (Spatie flat / hasRole('pmo')) أُزيل بعد تثبيت engine=ON في Phase هـ.
 */
class PortfolioPolicy
{
    use HandlesAuthorization;

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

    /**
     * عرض قائمة المحافظ.
     */
    public function viewAny(User $user): bool
    {
        return AccessDecision::can($user, Capability::STRATEGY_VIEW);
    }

    /**
     * عرض محفظة معينة.
     */
    public function view(User $user, Portfolio $portfolio): bool
    {
        return AccessDecision::can($user, Capability::STRATEGY_VIEW, $portfolio);
    }

    /**
     * إنشاء محفظة.
     */
    public function create(User $user): bool
    {
        if (! $user->organization_id) {
            return false;
        }

        return AccessDecision::can($user, Capability::STRATEGY_CREATE);
    }

    /**
     * تعديل محفظة.
     */
    public function update(User $user, Portfolio $portfolio): bool
    {
        return AccessDecision::can($user, Capability::STRATEGY_EDIT, $portfolio);
    }

    /**
     * حذف محفظة.
     */
    public function delete(User $user, Portfolio $portfolio): bool
    {
        return AccessDecision::can($user, Capability::STRATEGY_DELETE, $portfolio);
    }

    /**
     * استعادة محفظة محذوفة — Super Admin فقط (يتم في before).
     */
    public function restore(User $user, Portfolio $portfolio): bool
    {
        return false;
    }

    /**
     * حذف نهائي — Super Admin فقط (يتم في before).
     */
    public function forceDelete(User $user, Portfolio $portfolio): bool
    {
        return false;
    }

    /**
     * إدارة أولوية ووزن المحفظة.
     */
    public function managePriority(User $user, Portfolio $portfolio): bool
    {
        return AccessDecision::can($user, Capability::STRATEGY_MANAGE_PRIORITY, $portfolio);
    }

    /**
     * تغيير الحالة الاستراتيجية للمحفظة.
     */
    public function changeStrategicStatus(User $user, Portfolio $portfolio): bool
    {
        return AccessDecision::can($user, Capability::STRATEGY_CHANGE_STATUS, $portfolio);
    }

    /**
     * الإغلاق القسري للمحفظة.
     */
    public function forceClose(User $user, Portfolio $portfolio): bool
    {
        return AccessDecision::can($user, Capability::STRATEGY_CHANGE_STATUS, $portfolio);
    }

    /**
     * تعيين مالك المحفظة.
     */
    public function assignOwner(User $user, Portfolio $portfolio): bool
    {
        return AccessDecision::can($user, Capability::STRATEGY_ASSIGN_OWNER, $portfolio);
    }
}
