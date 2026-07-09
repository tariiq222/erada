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
 *
 * Phase CFA-01 — cluster_tree primitives also surface through this provider
 * so the SPA can reflect cluster authority on the role-management UI:
 *   - core.cluster_tree.view <-> Capability::CLUSTER_TREE_VIEW
 *   - core.cluster_tree.manage <-> Capability::CLUSTER_TREE_MANAGE
 *   - core.cluster_tree.export <-> Capability::CLUSTER_TREE_EXPORT
 *
 * Each is the engine's record-less check; AccessDecision::can() returns
 * true ONLY when the actor holds a scoped role on actor.organization_id
 * whose permissions[] contains the constant (no is_admin_role shortcut).
 * The cross-org rescue branch requires a Model $target, which is NOT
 * surfaced here — UI code should treat auth/me.cluster_tree.view as
 * "the actor MAY have cluster read access" and rely on the per-record
 * engine check for any actual cross-org read.
 */
class CoreCapabilityProvider implements CapabilityProvider
{
    public function userCapabilities(User $user): array
    {
        return [
            Capability::DASHBOARD_VIEW => AccessDecision::can($user, Capability::DASHBOARD_VIEW),

            // Phase CFA-01 — cluster_tree primitives (UI surface only).
            // The engine's per-record rescue branch is what actually
            // authorizes cross-org access; auth/me is the UI signal.
            Capability::CLUSTER_TREE_VIEW => AccessDecision::can($user, Capability::CLUSTER_TREE_VIEW),
            Capability::CLUSTER_TREE_MANAGE => AccessDecision::can($user, Capability::CLUSTER_TREE_MANAGE),
            Capability::CLUSTER_TREE_EXPORT => AccessDecision::can($user, Capability::CLUSTER_TREE_EXPORT),
        ];
    }
}
