<?php

namespace App\Modules\Surveys\Services;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Contracts\CapabilityProvider;
use App\Modules\Core\Models\User;

/**
 * Surveys module's contribution to auth/me.capabilities.
 *
 * Mirrors the Phase 5.A pattern used by OVR, Projects, RiskManagement, HR,
 * and Meetings CapabilityProviders: the unified AccessDecision engine
 * exposes record-scoped surveys capabilities (Capability::SURVEYS_*). This
 * provider forwards each one as a flat "anywhere in the user's scope"
 * boolean so the SPA's `useCan(...)` hooks resolve against the canonical
 * dotted capability strings (`surveys.view`, `surveys.create`, ...).
 *
 * Each entry is the engine's record-less check (no target Model), which
 * AccessDecision::can() walks through the actor's scoped roles to decide.
 * Record-scoped checks happen at the FormRequest authorize() / Policy layer.
 *
 * Wire keys (canonical after Phase 6 — Surveys Org-Isolation):
 *   - surveys.view                <-> Capability::SURVEYS_VIEW
 *   - surveys.create              <-> Capability::SURVEYS_CREATE
 *   - surveys.edit                <-> Capability::SURVEYS_EDIT
 *   - surveys.delete              <-> Capability::SURVEYS_DELETE
 *   - surveys.review_responses    <-> Capability::SURVEYS_REVIEW_RESPONSES
 *   - surveys.review_data_imports <-> Capability::SURVEYS_REVIEW_DATA_IMPORTS
 */
class SurveysCapabilityProvider implements CapabilityProvider
{
    public function userCapabilities(User $user): array
    {
        return [
            Capability::SURVEYS_VIEW => AccessDecision::can($user, Capability::SURVEYS_VIEW),
            Capability::SURVEYS_CREATE => AccessDecision::can($user, Capability::SURVEYS_CREATE),
            Capability::SURVEYS_EDIT => AccessDecision::can($user, Capability::SURVEYS_EDIT),
            Capability::SURVEYS_DELETE => AccessDecision::can($user, Capability::SURVEYS_DELETE),
            Capability::SURVEYS_REVIEW_RESPONSES => AccessDecision::can($user, Capability::SURVEYS_REVIEW_RESPONSES),
            Capability::SURVEYS_REVIEW_DATA_IMPORTS => AccessDecision::can($user, Capability::SURVEYS_REVIEW_DATA_IMPORTS),
        ];
    }
}
