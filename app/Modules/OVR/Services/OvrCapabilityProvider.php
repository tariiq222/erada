<?php

namespace App\Modules\OVR\Services;

use App\Modules\Core\Contracts\CapabilityProvider;
use App\Modules\Core\Models\User;

/**
 * OVR module's contribution to auth/me.permissions.
 *
 * Same pattern as ProjectCapabilityProvider and RiskCapabilityProvider:
 * the engine speaks record-scoped ovr.* capabilities; the SPA gates
 * routes/menus/buttons on the flat `ovr.create` / `ovr.view_own` strings
 * that mean "anywhere in the user's scope".
 *
 * Surfaced legacy flat strings (consumed by the SPA):
 *   - ovr.create
 *   - ovr.view_own
 */
class OvrCapabilityProvider implements CapabilityProvider
{
    public function __construct(
        private readonly OvrAuthorizationService $authorization
    ) {}

    public function userCapabilities(User $user): array
    {
        return [
            'ovr.create' => $this->authorization->canCreateAny($user),
            'ovr.view_own' => $this->authorization->canViewAny($user),
        ];
    }
}
