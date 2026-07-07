<?php

namespace App\Modules\Core\Authorization;

use RuntimeException;

/**
 * AuthzShadowMismatchException -- thrown when the SHADOW runtime-mode branch
 * finds the legacy engine decision and the new
 * `authorization_role_permissions` + `authorization_record_rules` path
 * disagree on the same (user, capability, target) triple.
 *
 * Phase 1 Task 1.1.4: the shadow branch is compare-only. It runs both paths
 * and surfaces a mismatch as a thrown exception so the test suite (and the
 * staging cutover) can flag drift between the legacy and new engines without
 * silently rewriting the user's existing decision. No audit row is written.
 */
final class AuthzShadowMismatchException extends RuntimeException
{
    public function __construct(
        public readonly string $capability,
        public readonly bool $legacyDecision,
        public readonly bool $newPathDecision,
        string $reason = '',
    ) {
        parent::__construct(sprintf(
            'AuthZ shadow mismatch on capability [%s]: legacy=%s, new=%s.%s',
            $capability,
            $legacyDecision ? 'allow' : 'deny',
            $newPathDecision ? 'allow' : 'deny',
            $reason !== '' ? ' '.$reason : ''
        ));
    }
}
