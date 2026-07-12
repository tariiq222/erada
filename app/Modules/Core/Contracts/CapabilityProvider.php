<?php

namespace App\Modules\Core\Contracts;

use App\Modules\Core\Models\User;

/**
 * CapabilityProvider: module-owned contract for declaring the capabilities
 * exposed to the SPA by auth/me.
 *
 * Why this exists
 * ---------------
 * The unified AccessDecision engine exposes capabilities as `module.action`
 * (see App\Modules\Core\Authorization\Capability). The SPA, however, has
 * route guards, menus, and button gates include contextual capabilities
 * that a resource-free engine call cannot express — e.g. "can the user
 * create at least one project *somewhere* in their department subtree
 * OR the governing department for a project type?" Those are
 * context-aware decisions owned by the Projects module, not by the engine.
 *
 * Rather than hard-coding calls to ProjectAuthorizationService /
 * RiskAuthorizationService / OvrAuthorizationService inside AuthController
 * (which re-couples Core to every module and bloats /me), each owning
 * module tags a CapabilityProvider into the container under
 * `engined_capability_providers`. AuthController iterates the tag and
 * merges the returned flags. Adding a new module capability becomes
 * a one-file, one-tag-line change with no edits to AuthController.
 *
 * Contract
 * --------
 * userCapabilities() returns an associative array of
 *   flag-string => bool
 * where the flag-string matches the capability name the SPA gates on
 * (e.g. 'view_projects', 'create_projects', 'ovr.create'). A `true` value
 * means "this user holds this capability"; `false` means they do
 * not. AuthController only appends `true` flags to the permissions array.
 *
 * Implementations MUST be safe to call on every /me hit. They SHOULD be
 * cheap (a handful of checks against the engine's memoized scope chain);
 * if a provider becomes expensive, memoize its result for the request.
 */
interface CapabilityProvider
{
    /**
     * Return the capabilities the given user holds.
     *
     * @return array<string, bool>
     */
    public function userCapabilities(User $user): array;
}
