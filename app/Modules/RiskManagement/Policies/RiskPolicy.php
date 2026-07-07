<?php

namespace App\Modules\RiskManagement\Policies;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\User;
use App\Modules\RiskManagement\Models\Risk;
use App\Modules\RiskManagement\Services\RiskAuthorizationService;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * RiskPolicy — سياسة الوصول للمخاطر
 *
 * يعتمد كلياً على محرّك AuthZ الموحّد (AccessDecision::can).
 * المنطق القديم (Spatie flat permissions) أُزيل في Phase هـ Task 4.
 */
class RiskPolicy
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

    public function view(User $user, Risk $risk): bool
    {
        return AccessDecision::can($user, Capability::RISKS_VIEW, $risk);
    }

    /**
     * Generic create gate (can the user create a risk at all?). The context-aware
     * decision (target department) is enforced in StoreRiskRequest::authorize via
     * RiskAuthorizationService::canCreate.
     */
    public function create(User $user): bool
    {
        return app(RiskAuthorizationService::class)->canCreateAny($user);
    }

    public function update(User $user, Risk $risk): bool
    {
        return AccessDecision::can($user, Capability::RISKS_EDIT, $risk);
    }

    public function delete(User $user, Risk $risk): bool
    {
        return AccessDecision::can($user, Capability::RISKS_DELETE, $risk);
    }

    public function reassess(User $user, Risk $risk): bool
    {
        return AccessDecision::can($user, Capability::RISKS_REASSESS, $risk);
    }

    public function changeStatus(User $user, Risk $risk): bool
    {
        return AccessDecision::can($user, Capability::RISKS_CHANGE_STATUS, $risk);
    }

    public function viewReports(User $user): bool
    {
        return AccessDecision::can($user, Capability::RISKS_VIEW_REPORTS);
    }
}
