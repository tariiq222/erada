<?php

namespace App\Modules\Core\Contracts;

use App\Modules\Core\Models\User;

/**
 * CapabilityProvider: legacy/advisory module contract.
 *
 * Why this still exists
 * ---------------------
 * Modules continue to tag CapabilityProvider implementations under
 * `engined_capability_providers` for backwards compatibility with the
 * earlier /me iteration path. However, the canonical /api/user
 * projection no longer iterates this tag. The current source of truth
 * for /api/user capabilities is `User::canonicalCapabilityNames()`,
 * which derives canonical dotted capabilities (e.g. `hr.view`,
 * `projects.view`) directly from active canonical role assignments
 * through the AccessDecision engine — see
 * `App\Modules\Core\Models\User::canonicalCapabilityNames()` and
 * `App\Modules\Core\Authorization\Capability`.
 *
 * In other words, providers are no longer on the wire-format critical
 * path. They remain as advisory helpers and a future cleanup may
 * retire them. Adding a new module capability does NOT require a new
 * provider — register the canonical resource/action mapping so it
 * flows through `Capability` and `CapabilityToAuthorizationRolePermission`.
 *
 * Contract
 * --------
 * userCapabilities() returns an associative array of
 *   flag-string => bool
 * for any module-specific decision that wants to surface a non-canonical
 * projection. Callers MUST NOT treat the result as the wire-format
 * authority; the /api/user capability list is canonicalCapabilityNames().
 *
 * Implementations MUST be safe to call and SHOULD be cheap (a handful of
 * checks against the engine's memoized scope chain). If a provider
 * becomes expensive, memoize its result for the request.
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
