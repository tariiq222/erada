<?php

namespace App\Modules\RiskManagement\Services;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Contracts\CapabilityProvider;
use App\Modules\Core\Models\User;

/**
 * Risks module's contribution to auth/me.capabilities.
 *
 * The engine exposes the record-scoped Capability::RISKS_VIEW / RISKS_CREATE
 * capabilities, while the SPA gates routes, menus, and buttons on the
 * canonical dotted capabilities (`risks.view`, `risks.create`) that say
 * "anywhere in the user's scope".
 *
 * After Phase 9.3 (2026-07-05) the legacy `permissions[]` flat blob was
 * removed from /api/auth/me and the SPA access bridge no longer reads it.
 * Surfacing the canonical dotted form here keeps the SPA `useCan('risks.view')`
 * / `useCan('risks.create')` hooks resolving correctly without depending on
 * the legacy dotted-flat lookup. The decision still comes from
 * RiskAuthorizationService (engine-only since Wave 3 task 4).
 *
 * Wire keys (canonical after Phase 9.3):
 *   - risks.view    <-> Capability::RISKS_VIEW
 *   - risks.create  <-> Capability::RISKS_CREATE
 */
class RiskCapabilityProvider implements CapabilityProvider
{
    public function __construct(
        private readonly RiskAuthorizationService $authorization
    ) {}

    public function userCapabilities(User $user): array
    {
        return [
            Capability::RISKS_VIEW => $this->authorization->canViewAny($user),
            Capability::RISKS_CREATE => $this->authorization->canCreateAny($user),
        ];
    }
}
