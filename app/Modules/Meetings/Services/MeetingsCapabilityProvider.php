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
 * Each entry is the engine's record-less check (no target Model), which
 * AccessDecision::can() walks through the actor's scoped roles to decide.
 * Record-scoped checks happen at the FormRequest authorize() / Policy layer.
 *
 * Wire keys (canonical after Phase 5.A):
 *   - meetings.view             <-> Capability::MEETINGS_VIEW
 *   - meetings.create           <-> Capability::MEETINGS_CREATE
 *   - meetings.edit             <-> Capability::MEETINGS_EDIT
 *   - meetings.delete           <-> Capability::MEETINGS_DELETE
 *   - meetings.record_decisions <-> Capability::MEETINGS_RECORD_DECISIONS
 *   - recommendations.view      <-> Capability::RECOMMENDATIONS_VIEW
 *   - recommendations.create    <-> Capability::RECOMMENDATIONS_CREATE
 *   - recommendations.edit      <-> Capability::RECOMMENDATIONS_EDIT
 *   - recommendations.delete    <-> Capability::RECOMMENDATIONS_DELETE
 *   - recommendations.approve   <-> Capability::RECOMMENDATIONS_APPROVE
 *   - recommendations.reject    <-> Capability::RECOMMENDATIONS_REJECT
 *   - recommendations.defer     <-> Capability::RECOMMENDATIONS_DEFER
 *   - recommendations.accept    <-> Capability::RECOMMENDATIONS_ACCEPT
 *   - recommendations.complete  <-> Capability::RECOMMENDATIONS_COMPLETE
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
            Capability::RECOMMENDATIONS_VIEW => AccessDecision::can($user, Capability::RECOMMENDATIONS_VIEW),
            Capability::RECOMMENDATIONS_CREATE => AccessDecision::can($user, Capability::RECOMMENDATIONS_CREATE),
            Capability::RECOMMENDATIONS_EDIT => AccessDecision::can($user, Capability::RECOMMENDATIONS_EDIT),
            Capability::RECOMMENDATIONS_DELETE => AccessDecision::can($user, Capability::RECOMMENDATIONS_DELETE),
            Capability::RECOMMENDATIONS_APPROVE => AccessDecision::can($user, Capability::RECOMMENDATIONS_APPROVE),
            Capability::RECOMMENDATIONS_REJECT => AccessDecision::can($user, Capability::RECOMMENDATIONS_REJECT),
            Capability::RECOMMENDATIONS_DEFER => AccessDecision::can($user, Capability::RECOMMENDATIONS_DEFER),
            Capability::RECOMMENDATIONS_ACCEPT => AccessDecision::can($user, Capability::RECOMMENDATIONS_ACCEPT),
            Capability::RECOMMENDATIONS_COMPLETE => AccessDecision::can($user, Capability::RECOMMENDATIONS_COMPLETE),
        ];
    }
}
