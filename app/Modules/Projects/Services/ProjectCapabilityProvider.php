<?php

namespace App\Modules\Projects\Services;

use App\Modules\Core\Contracts\CapabilityProvider;
use App\Modules\Core\Models\User;

/**
 * Projects module's contribution to auth/me.capabilities.
 *
 * The unified engine's `projects.view` / `projects.create` capabilities are
 * record-scoped — they answer "can this user view/create THIS project?"
 * via AccessDecision::can($user, $capability, $project). The SPA's route
 * guards and menu/button gating, however, ask the org/role-scoped
 * question "can this user view/create a project ANYWHERE?". That is what
 * this provider answers.
 *
 * After Phase 9.3 (2026-07-05) the legacy `permissions[]` flat blob was
 * removed from the /api/auth/me payload and the SPA access bridge no
 * longer reads it. This provider therefore surfaces the canonical
 * dotted capabilities (`projects.view`, `projects.create`) so the SPA
 * `useCan('projects.view')` / `useCan('projects.create')` hooks resolve
 * correctly for any user with org/role-scoped project access — without
 * depending on the legacy dotted-flat lookup.
 */
class ProjectCapabilityProvider implements CapabilityProvider
{
    public function __construct(
        private readonly ProjectAuthorizationService $authorization
    ) {}

    public function userCapabilities(User $user): array
    {
        return [
            'projects.view' => $this->authorization->canViewAny($user),
            'projects.create' => $this->authorization->canCreateAny($user),
        ];
    }
}
