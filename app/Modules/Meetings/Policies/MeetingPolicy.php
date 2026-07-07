<?php

namespace App\Modules\Meetings\Policies;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\User;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Support\MeetingOrgGuard;

/**
 * MeetingPolicy - Phase 5.B: per-record org-isolation for Meeting.
 *
 * نمط موحّد مع KpiPolicy / EmployeeProfilePolicy:
 *   - super_admin ⇒ true دائماً (via before()).
 *   - actor بلا organization_id ⇒ deny (fail-closed).
 *   - meeting من منظمة أخرى ⇒ deny.
 *   - meeting بلا organization_id ⇒ deny (orphan).
 *
 * لا تعتمد على Spatie direct. الـ Capability constants تمر عبر AccessDecision
 * ليتحقّق المحرك من الأدوار السياقية.
 */
class MeetingPolicy
{
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

    public function viewAny(User $user): bool
    {
        if (! $user->isSuperAdmin() && $user->organization_id === null) {
            return false;
        }

        return AccessDecision::can($user, Capability::MEETINGS_VIEW);
    }

    public function view(User $user, Meeting $meeting): bool
    {
        if (! $this->precheck($user, $meeting)) {
            return false;
        }

        return AccessDecision::can($user, Capability::MEETINGS_VIEW, $meeting);
    }

    public function create(User $user): bool
    {
        if (! $user->isSuperAdmin() && $user->organization_id === null) {
            return false;
        }

        return AccessDecision::can($user, Capability::MEETINGS_CREATE);
    }

    public function update(User $user, Meeting $meeting): bool
    {
        if (! $this->precheck($user, $meeting)) {
            return false;
        }

        return AccessDecision::can($user, Capability::MEETINGS_EDIT, $meeting);
    }

    public function delete(User $user, Meeting $meeting): bool
    {
        if (! $this->precheck($user, $meeting)) {
            return false;
        }

        return AccessDecision::can($user, Capability::MEETINGS_DELETE, $meeting);
    }

    /**
     * precheck: actor/org gate + same-org عبر MeetingOrgGuard.
     */
    protected function precheck(User $user, Meeting $meeting): bool
    {
        if (! $user->isSuperAdmin() && $user->organization_id === null) {
            return false;
        }

        return app(MeetingOrgGuard::class)->sameOrganizationForMeeting($user, $meeting);
    }
}
