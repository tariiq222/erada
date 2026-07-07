<?php

namespace App\Modules\RiskManagement\Policies;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\User;
use App\Modules\RiskManagement\Models\RiskAction;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * RiskActionPolicy — سياسة الوصول لإجراءات المخاطر
 *
 * يعتمد كلياً على محرّك AuthZ الموحّد (AccessDecision::can).
 * RiskAction يطبّق ScopeAware — scopeParent() يرجع الـ Risk الأب —
 * فيُفرض عزل org عبر سلسلة الأب مباشرةً (إغلاق GAP-3).
 * المنطق القديم (Spatie flat permissions) أُزيل في Phase هـ Task 4.
 */
class RiskActionPolicy
{
    use HandlesAuthorization;

    public function before(User $user, string $ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return AccessDecision::can($user, Capability::RISKS_VIEW);
    }

    public function view(User $user, RiskAction $riskAction): bool
    {
        return AccessDecision::can($user, Capability::RISKS_VIEW, $riskAction);
    }

    public function create(User $user): bool
    {
        return AccessDecision::can($user, Capability::RISKS_EDIT);
    }

    public function update(User $user, RiskAction $riskAction): bool
    {
        return AccessDecision::can($user, Capability::RISKS_EDIT, $riskAction);
    }

    public function delete(User $user, RiskAction $riskAction): bool
    {
        return AccessDecision::can($user, Capability::RISKS_DELETE, $riskAction);
    }
}
