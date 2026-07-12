<?php

namespace App\Modules\HR\Services;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Contracts\CapabilityProvider;
use App\Modules\Core\Models\User;

/**
 * HR module's legacy/advisory CapabilityProvider.
 *
 * Verified residual (2026-07-12): this provider is NOT the /api/user
 * authority. The canonical /api/user projection derives capabilities via
 * `User::canonicalCapabilityNames()`, which returns canonical dotted
 * capabilities (`hr.view`, `hr.manage`) for HR grants. AuthController no
 * longer iterates the `engined_capability_providers` tag.
 *
 * The provider remains in place as a non-canonical helper. Each flag is
 * still resolved through AccessDecision, so changes there still flow —
 * but consumers must treat the output as advisory, not as the
 * wire-format source of truth. The keys it returns (`view_hr`,
 * `manage_hr`) are intentionally flat strings to preserve historical
 * consumers; the canonical dotted names belong to
 * `User::canonicalCapabilityNames()`.
 *
 * Decision source for each flag:
 *   - view_hr   <-> AccessDecision::can(user, Capability::HR_VIEW)
 *   - manage_hr <-> AccessDecision::can(user, Capability::HR_MANAGE)
 */
class HRCapabilityProvider implements CapabilityProvider
{
    public function userCapabilities(User $user): array
    {
        // Each flag is computed via AccessDecision; the provider is
        // advisory only — see class docblock.
        return [
            'view_hr' => AccessDecision::can($user, Capability::HR_VIEW),
            'manage_hr' => AccessDecision::can($user, Capability::HR_MANAGE),
        ];
    }
}
