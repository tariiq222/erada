<?php

namespace App\Modules\Strategy\Policies;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\User;
use App\Modules\Strategy\Models\Program;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * ProgramPolicy — سياسة صلاحيات المبادرات (Programs)
 *
 * engine-only: يعتمد كلياً على AccessDecision::can().
 * المنطق القديم (Spatie flat / مقارنات FK) أُزيل بعد تثبيت engine=ON في Phase هـ.
 */
class ProgramPolicy
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
     * عرض قائمة المبادرات.
     */
    public function viewAny(User $user): bool
    {
        return AccessDecision::can($user, Capability::STRATEGY_VIEW);
    }

    /**
     * عرض مبادرة معينة.
     */
    public function view(User $user, Program $program): bool
    {
        return AccessDecision::can($user, Capability::STRATEGY_VIEW, $program);
    }

    /**
     * إنشاء مبادرة.
     */
    public function create(User $user): bool
    {
        if (! $user->organization_id) {
            return false;
        }

        return AccessDecision::can($user, Capability::STRATEGY_CREATE);
    }

    /**
     * تعديل مبادرة.
     */
    public function update(User $user, Program $program): bool
    {
        return AccessDecision::can($user, Capability::STRATEGY_EDIT, $program);
    }

    /**
     * حذف مبادرة.
     */
    public function delete(User $user, Program $program): bool
    {
        return AccessDecision::can($user, Capability::STRATEGY_DELETE, $program);
    }

    /**
     * استعادة مبادرة محذوفة — Super Admin فقط (يتم في before).
     */
    public function restore(User $user, Program $program): bool
    {
        return false;
    }

    /**
     * حذف نهائي — Super Admin فقط (يتم في before).
     */
    public function forceDelete(User $user, Program $program): bool
    {
        return false;
    }

    /**
     * تغيير محفظة المبادرة أو وزنها — يتطلب manage_priority.
     */
    public function changePortfolio(User $user, Program $program): bool
    {
        return AccessDecision::can($user, Capability::STRATEGY_MANAGE_PRIORITY, $program);
    }

    /**
     * إدارة وزن المبادرة.
     */
    public function manageWeight(User $user, Program $program): bool
    {
        return AccessDecision::can($user, Capability::STRATEGY_MANAGE_PRIORITY, $program);
    }

    /**
     * إدارة المشاريع داخل المبادرة.
     */
    public function manageProjects(User $user, Program $program): bool
    {
        return AccessDecision::can($user, Capability::STRATEGY_MANAGE_PROJECTS, $program);
    }

    /**
     * تعيين مدير برنامج.
     */
    public function assignProgramManager(User $user, Program $program): bool
    {
        return AccessDecision::can($user, Capability::STRATEGY_ASSIGN_OWNER, $program);
    }

    /**
     * تعيين راعٍ تنفيذي.
     */
    public function assignExecutiveSponsor(User $user, Program $program): bool
    {
        return AccessDecision::can($user, Capability::STRATEGY_ASSIGN_OWNER, $program);
    }

    /**
     * ربط/فك مشروع.
     */
    public function linkProject(User $user, Program $program): bool
    {
        return $this->manageProjects($user, $program);
    }

    /**
     * عرض تقارير ومؤشرات المبادرة.
     */
    public function viewReports(User $user, Program $program): bool
    {
        return AccessDecision::can($user, Capability::STRATEGY_VIEW, $program);
    }
}
