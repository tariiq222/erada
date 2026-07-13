<?php

namespace App\Modules\Meetings\Services;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Contracts\CapabilityProvider;
use App\Modules\Core\Models\User;

/**
 * Meetings module's contribution to auth/me.capabilities.
 *
 * Mirrors HRCapabilityProvider: the unified AccessDecision engine exposes
 * record-scoped meetings / recommendations capabilities (Capability::MEETINGS_*
 * and Capability::RECOMMENDATIONS_*). For Phase 5.A we forward each one as a
 * flat "anywhere in the user's scope" boolean so the SPA's `useCan(...)` hooks
 * resolve against the canonical dotted capability strings (`meetings.view`,
 * `recommendations.approve`, ...).
 *
 * Recommendation flags aggregate any granting scope so the SPA can expose an
 * action where it is usable; the FormRequest / Policy layer still performs the
 * target-bound decision. Recommendation create remains the engine's
 * record-less check because it has no existing target.
 *
 * Wire keys (canonical after Phase 5.A):
 *   - meetings.view                          <-> Capability::MEETINGS_VIEW
 *   - meetings.create                        <-> Capability::MEETINGS_CREATE
 *   - meetings.edit                          <-> Capability::MEETINGS_EDIT
 *   - meetings.delete                        <-> Capability::MEETINGS_DELETE
 *   - meetings.record_decisions              <-> Capability::MEETINGS_RECORD_DECISIONS
 *   - recommendations.view                   <-> Capability::RECOMMENDATIONS_VIEW
 *   - recommendations.create                 <-> Capability::RECOMMENDATIONS_CREATE
 *   - recommendations.edit                   <-> Capability::RECOMMENDATIONS_EDIT
 *   - recommendations.delete                 <-> Capability::RECOMMENDATIONS_DELETE
 *   - recommendations.approve                <-> Capability::RECOMMENDATIONS_APPROVE
 *   - recommendations.reject                 <-> Capability::RECOMMENDATIONS_REJECT
 *   - recommendations.defer                  <-> Capability::RECOMMENDATIONS_DEFER
 *   - recommendations.accept                 <-> Capability::RECOMMENDATIONS_ACCEPT
 *   - recommendations.complete               <-> Capability::RECOMMENDATIONS_COMPLETE
 *
 * Phase 1 / Direction R additions (Meeting Resolutions Foundation):
 *   - meeting_resolutions.view               <-> Capability::MEETING_RESOLUTIONS_VIEW
 *   - meeting_resolutions.create             <-> Capability::MEETING_RESOLUTIONS_CREATE
 *   - meeting_resolutions.update             <-> Capability::MEETING_RESOLUTIONS_UPDATE
 *   - meeting_resolutions.delete             <-> Capability::MEETING_RESOLUTIONS_DELETE
 *   - meeting_resolutions.hold               <-> Capability::MEETING_RESOLUTIONS_HOLD
 *   - meeting_resolutions.release_hold       <-> Capability::MEETING_RESOLUTIONS_RELEASE_HOLD
 *   - meeting_resolutions.convert_to_tasks   <-> Capability::MEETING_RESOLUTIONS_CONVERT_TO_TASKS
 *   - meeting_resolutions.complete           <-> Capability::MEETING_RESOLUTIONS_COMPLETE
 *   - meeting_resolutions.cancel             <-> Capability::MEETING_RESOLUTIONS_CANCEL
 */
class MeetingsCapabilityProvider implements CapabilityProvider
{
    public function userCapabilities(User $user): array
    {
        return [
            Capability::MEETINGS_VIEW => AccessDecision::can($user, Capability::MEETINGS_VIEW),
            Capability::MEETINGS_CREATE => AccessDecision::can($user, Capability::MEETINGS_CREATE),
            Capability::MEETINGS_EDIT => AccessDecision::can($user, Capability::MEETINGS_EDIT),
            Capability::MEETINGS_DELETE => AccessDecision::can($user, Capability::MEETINGS_DELETE),
            Capability::MEETINGS_RECORD_DECISIONS => AccessDecision::can($user, Capability::MEETINGS_RECORD_DECISIONS),
            Capability::RECOMMENDATIONS_VIEW => $this->hasRecommendationGrant($user, Capability::RECOMMENDATIONS_VIEW),
            Capability::RECOMMENDATIONS_CREATE => AccessDecision::can($user, Capability::RECOMMENDATIONS_CREATE),
            Capability::RECOMMENDATIONS_EDIT => $this->hasRecommendationGrant($user, Capability::RECOMMENDATIONS_EDIT),
            Capability::RECOMMENDATIONS_DELETE => $this->hasRecommendationGrant($user, Capability::RECOMMENDATIONS_DELETE),
            Capability::RECOMMENDATIONS_APPROVE => $this->hasRecommendationGrant($user, Capability::RECOMMENDATIONS_APPROVE),
            Capability::RECOMMENDATIONS_REJECT => $this->hasRecommendationGrant($user, Capability::RECOMMENDATIONS_REJECT),
            Capability::RECOMMENDATIONS_DEFER => $this->hasRecommendationGrant($user, Capability::RECOMMENDATIONS_DEFER),
            Capability::RECOMMENDATIONS_ACCEPT => $this->hasRecommendationGrant($user, Capability::RECOMMENDATIONS_ACCEPT),
            Capability::RECOMMENDATIONS_COMPLETE => $this->hasRecommendationGrant($user, Capability::RECOMMENDATIONS_COMPLETE),
            Capability::MEETING_RESOLUTIONS_VIEW => AccessDecision::can($user, Capability::MEETING_RESOLUTIONS_VIEW),
            Capability::MEETING_RESOLUTIONS_CREATE => AccessDecision::can($user, Capability::MEETING_RESOLUTIONS_CREATE),
            Capability::MEETING_RESOLUTIONS_UPDATE => AccessDecision::can($user, Capability::MEETING_RESOLUTIONS_UPDATE),
            Capability::MEETING_RESOLUTIONS_DELETE => AccessDecision::can($user, Capability::MEETING_RESOLUTIONS_DELETE),
            Capability::MEETING_RESOLUTIONS_HOLD => AccessDecision::can($user, Capability::MEETING_RESOLUTIONS_HOLD),
            Capability::MEETING_RESOLUTIONS_RELEASE_HOLD => AccessDecision::can($user, Capability::MEETING_RESOLUTIONS_RELEASE_HOLD),
            Capability::MEETING_RESOLUTIONS_CONVERT_TO_TASKS => AccessDecision::can($user, Capability::MEETING_RESOLUTIONS_CONVERT_TO_TASKS),
            Capability::MEETING_RESOLUTIONS_COMPLETE => AccessDecision::can($user, Capability::MEETING_RESOLUTIONS_COMPLETE),
            Capability::MEETING_RESOLUTIONS_CANCEL => AccessDecision::can($user, Capability::MEETING_RESOLUTIONS_CANCEL),
        ];
    }

    /**
     * The SPA asks whether a record-scoped recommendation action is available
     * anywhere in the user's granted scope. Create intentionally remains a
     * record-less check because it has no existing recommendation target.
     */
    private function hasRecommendationGrant(User $user, string $capability): bool
    {
        if ($user->isSuperAdmin()
            || AccessDecision::can($user, $capability)
            || AccessDecision::grantsAtOrganization($user, $capability)) {
            return true;
        }

        foreach (AccessDecision::grantingScopes($user, $capability) as $scopeIds) {
            if ($scopeIds !== []) {
                return true;
            }
        }

        return false;
    }
}
