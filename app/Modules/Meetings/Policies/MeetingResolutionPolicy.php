<?php

namespace App\Modules\Meetings\Policies;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\User;
use App\Modules\Meetings\Models\MeetingResolution;
use App\Modules\Meetings\Support\MeetingOrgGuard;

/**
 * MeetingResolutionPolicy — Phase 1 / Direction R.
 *
 * Canonical authorization. The capability consulted
 * depends on the transition / action:
 *
 *   view / viewAny   -> Capability::MEETING_RESOLUTIONS_VIEW
 *   create           -> Capability::MEETING_RESOLUTIONS_CREATE
 *   update           -> Capability::MEETING_RESOLUTIONS_UPDATE
 *   delete           -> Capability::MEETING_RESOLUTIONS_DELETE
 *   hold             -> Capability::MEETING_RESOLUTIONS_HOLD
 *   releaseHold      -> Capability::MEETING_RESOLUTIONS_RELEASE_HOLD
 *   convertToTasks   -> Capability::MEETING_RESOLUTIONS_CONVERT_TO_TASKS
 *   complete         -> Capability::MEETING_RESOLUTIONS_COMPLETE
 *   cancel           -> Capability::MEETING_RESOLUTIONS_CANCEL
 *
 * Per-record org-isolation goes through MeetingOrgGuard so we share the same
 * fail-closed floor as MeetingPolicy / RecommendationPolicy.
 */
class MeetingResolutionPolicy
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

        return AccessDecision::can($user, Capability::MEETING_RESOLUTIONS_VIEW);
    }

    public function view(User $user, MeetingResolution $resolution): bool
    {
        if (! $this->precheck($user, $resolution)) {
            return false;
        }

        return AccessDecision::can($user, Capability::MEETING_RESOLUTIONS_VIEW, $resolution);
    }

    public function create(User $user): bool
    {
        if (! $user->isSuperAdmin() && $user->organization_id === null) {
            return false;
        }

        return AccessDecision::can($user, Capability::MEETING_RESOLUTIONS_CREATE);
    }

    public function update(User $user, MeetingResolution $resolution): bool
    {
        if (! $this->precheck($user, $resolution)) {
            return false;
        }

        return AccessDecision::can($user, Capability::MEETING_RESOLUTIONS_UPDATE, $resolution);
    }

    public function delete(User $user, MeetingResolution $resolution): bool
    {
        if (! $this->precheck($user, $resolution)) {
            return false;
        }

        return AccessDecision::can($user, Capability::MEETING_RESOLUTIONS_DELETE, $resolution);
    }

    public function hold(User $user, MeetingResolution $resolution): bool
    {
        if (! $this->precheck($user, $resolution)) {
            return false;
        }

        return AccessDecision::can($user, Capability::MEETING_RESOLUTIONS_HOLD, $resolution);
    }

    public function releaseHold(User $user, MeetingResolution $resolution): bool
    {
        if (! $this->precheck($user, $resolution)) {
            return false;
        }

        return AccessDecision::can($user, Capability::MEETING_RESOLUTIONS_RELEASE_HOLD, $resolution);
    }

    public function convertToTasks(User $user, MeetingResolution $resolution): bool
    {
        if (! $this->precheck($user, $resolution)) {
            return false;
        }

        return AccessDecision::can($user, Capability::MEETING_RESOLUTIONS_CONVERT_TO_TASKS, $resolution);
    }

    public function complete(User $user, MeetingResolution $resolution): bool
    {
        if (! $this->precheck($user, $resolution)) {
            return false;
        }

        return AccessDecision::can($user, Capability::MEETING_RESOLUTIONS_COMPLETE, $resolution);
    }

    public function cancel(User $user, MeetingResolution $resolution): bool
    {
        if (! $this->precheck($user, $resolution)) {
            return false;
        }

        return AccessDecision::can($user, Capability::MEETING_RESOLUTIONS_CANCEL, $resolution);
    }

    /**
     * precheck: actor/org gate + same-org عبر MeetingOrgGuard.
     */
    protected function precheck(User $user, MeetingResolution $resolution): bool
    {
        if (! $user->isSuperAdmin() && $user->organization_id === null) {
            return false;
        }

        return app(MeetingOrgGuard::class)->sameOrganization(
            $user,
            $resolution->scopeOrganizationId()
        );
    }
}
