<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Contracts\CapabilityProvider;
use App\Modules\Core\Models\User;

/**
 * Core module's contribution to auth/me.capabilities.
 *
 * The Core module historically exposed no CapabilityProvider because its
 * legacy flat strings (`view_dashboard`, `view_users`, ...) all had
 * canonical Capability equivalents and were surfaced through other
 * modules' providers (or, for `view_dashboard`, were a ponytail in
 * CapabilityAlias and never wired to an engine capability).
 *
 * Phase 8-C introduces Capability::DASHBOARD_VIEW to surface
 * `view_dashboard` on the engine. The /api/dashboard/* route group is now
 * gated by `engine_capability:dashboard.view`, so this provider is the
 * one place that makes the boolean reach the SPA's `useCan(...)` hook
 * for the dashboard.
 *
 * Each entry is the engine's record-less check (no target Model), which
 * AccessDecision::can() walks through the actor's scoped roles to decide.
 *
 * Wire keys (Phase 8-C):
 *   - dashboard.view <-> Capability::DASHBOARD_VIEW
 */
class CoreCapabilityProvider implements CapabilityProvider
{
    public function userCapabilities(User $user): array
    {
        return [
            Capability::DASHBOARD_VIEW => AccessDecision::can($user, Capability::DASHBOARD_VIEW),
        ];
    }
}
