<?php

namespace App\Modules\HR\Services;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Contracts\CapabilityProvider;
use App\Modules\Core\Models\User;

/**
 * HR module's contribution to auth/me.capabilities.
 *
 * Mirrors ProjectCapabilityProvider / RiskCapabilityProvider: the unified
 * AccessDecision engine exposes record-scoped HR capabilities
 * (Capability::HR_VIEW / Capability::HR_MANAGE), and after Phase 9.3
 * (2026-07-05) the SPA gates routes, menus, and buttons on the canonical
 * dotted capabilities (`hr.view`, `hr.manage`).
 *
 * The HR module has no dedicated authorization service (no
 * HRAuthorizationService like Risks/Projects do) because HR decisions are
 * flat role-grant checks, not department/governing-dept compositions.
 * The engine resolves Capability::HR_VIEW / Capability::HR_MANAGE directly
 * via AccessDecision::can(), which already short-circuits for super_admin.
 *
 * Wire keys (canonical after Phase 9.3):
 *   - hr.view    <-> Capability::HR_VIEW
 *   - hr.manage  <-> Capability::HR_MANAGE
 */
class HRCapabilityProvider implements CapabilityProvider
{
    public function userCapabilities(User $user): array
    {
        // Expose the HR capability flags consumed by the SPA. AccessDecision
        // remains the source of each decision.
        return [
            'view_hr' => AccessDecision::can($user, Capability::HR_VIEW),
            'manage_hr' => AccessDecision::can($user, Capability::HR_MANAGE),
        ];
    }
}
