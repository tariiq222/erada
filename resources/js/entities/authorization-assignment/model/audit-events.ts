/**
 * Canonical authorization assignment audit events.
 *
 * Single source of truth for the FE side of the authorization-assignment
 * audit log filter. Every value listed here MUST be one of the strings
 * written to `authorization_assignment_audits.event` by:
 *
 *   - App\Modules\Core\Authorization\Services\AuthorizationAssignmentService
 *     (canonical_assignment_assigned, canonical_assignment_revoked,
 *      canonical_assignment_synced)
 *   - App\Modules\Core\Http\Controllers\RoleController::writeAudit
 *     (role_created, role_updated, role_disabled)
 *   - App\Modules\HR\Services\ScopedDepartmentRoleSyncService::auditMutation
 *     (canonical_assignment_assigned, canonical_assignment_revoked,
 *      canonical_assignment_synced — same prefix, distinct service)
 *
 * Adding a new audit event requires:
 *   1. Wiring the new event in the appropriate backend writer.
 *   2. Adding the constant here AND to a corresponding translator key in
 *      lang/{ar,en}.json (or accept the fallback to the raw event text).
 *   3. Extending the FE Vitest coverage so every filter option is asserted
 *      against its backend wire value.
 *
 * Do NOT reintroduce Spatie-era audit strings
 * (permission_granted / permission_revoked / access_denied / role_assigned /
 *  role_revoked) — the canonical audit table no longer writes them.
 */
export const AUTHORIZATION_ASSIGNMENT_AUDIT_EVENTS = {
  canonical_assignment_assigned: 'canonical_assignment_assigned',
  canonical_assignment_revoked: 'canonical_assignment_revoked',
  canonical_assignment_synced: 'canonical_assignment_synced',
  role_created: 'role_created',
  role_updated: 'role_updated',
  role_disabled: 'role_disabled',
} as const;

export type AuthorizationAssignmentAuditEvent =
  (typeof AUTHORIZATION_ASSIGNMENT_AUDIT_EVENTS)[keyof typeof AUTHORIZATION_ASSIGNMENT_AUDIT_EVENTS];

/**
 * Ordered list used by the FE picker. The order is stable so the FE
 * test can iterate exactly the same list the UI exposes.
 */
export const AUTHORIZATION_ASSIGNMENT_AUDIT_EVENT_LIST: AuthorizationAssignmentAuditEvent[] = [
  AUTHORIZATION_ASSIGNMENT_AUDIT_EVENTS.canonical_assignment_assigned,
  AUTHORIZATION_ASSIGNMENT_AUDIT_EVENTS.canonical_assignment_revoked,
  AUTHORIZATION_ASSIGNMENT_AUDIT_EVENTS.canonical_assignment_synced,
  AUTHORIZATION_ASSIGNMENT_AUDIT_EVENTS.role_created,
  AUTHORIZATION_ASSIGNMENT_AUDIT_EVENTS.role_updated,
  AUTHORIZATION_ASSIGNMENT_AUDIT_EVENTS.role_disabled,
];