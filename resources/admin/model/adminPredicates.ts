import type { AdminUser } from '@admin/model/admin';

/**
 * Predicates that drive the OrgSuper-vs-super gating inside the admin SPA.
 *
 * Authorization is flag/role-name based and intentionally side-effect-free so
 * the predicates can be unit-tested without mounting any boundary component.
 * Every helper is the single source of truth for one UI rule; pages and
 * widgets import these instead of recomputing the same condition.
 *
 * Two protected roles may never be mutated through the OrgSuper lifecycle
 * surface, no matter how clean the actor's flags look:
 *
 *   - `super_admin`               → the platform super admin
 *   - `organization_super_admin`  → the per-organization super admin
 *
 * The backend's User module rejects UPDATE/DELETE on these targets
 * (`feat(users): reject Org-Super UPDATE + DELETE on protected admin targets`);
 * the FE hides the controls so callers never fire the rejected request.
 */

export const PLATFORM_SUPER_ADMIN_ROLE = 'super_admin';
export const ORG_SUPER_ADMIN_ROLE = 'organization_super_admin';
export const PROTECTED_ADMIN_ROLES: readonly string[] = [
  PLATFORM_SUPER_ADMIN_ROLE,
  ORG_SUPER_ADMIN_ROLE,
];

export function isPlatformSuperAdmin(
  user: Pick<AdminUser, 'roles'> | null | undefined,
): boolean {
  return Boolean(user?.roles?.includes(PLATFORM_SUPER_ADMIN_ROLE));
}

export function isOrganizationSuperAdmin(
  user: Pick<AdminUser, 'roles'> | null | undefined,
): boolean {
  return Boolean(user?.roles?.includes(ORG_SUPER_ADMIN_ROLE));
}

/**
 * Returns true when `target` holds a protected admin role. Lifecycle
 * actions (activate, deactivate, unlock, delete) must be hidden or
 * disabled on these rows regardless of the actor's authority level.
 */
export function isProtectedAdminTarget(
  target: Pick<AdminUser, 'roles'> | null | undefined,
): boolean {
  const roles = target?.roles ?? [];
  return roles.some((role) => PROTECTED_ADMIN_ROLES.includes(role));
}

/**
 * OrgSuper lifecycle actions are only safe on users whose organization
 * matches the actor's. Cross-org admin work is super-admin territory.
 */
export function isSameOrgAsActor(
  actor: { organization_id?: number | null } | null | undefined,
  target: Pick<AdminUser, 'organization_id'> | null | undefined,
): boolean {
  if (!actor || !target) return false;
  const actorOrgId = actor.organization_id ?? null;
  const targetOrgId = target.organization_id ?? null;
  if (actorOrgId === null || targetOrgId === null) return false;
  return actorOrgId === targetOrgId;
}

/**
 * Local view of the actor. Mirrors the two flags the backend exposes on
 * `/api/user` plus the actor's organization id (which is mirrored on the
 * shared `User` payload for the admin SPA via `useAuth().user`).
 */
export interface ActorView {
  is_super_admin?: boolean;
  is_organization_super_admin?: boolean;
  organization_id?: number | null;
}

/**
 * Single predicate consulted before rendering activate / deactivate /
 * unlock / delete actions on a target row. Locks the controls on:
 *   - protected-admin targets (PlatformSuperAdmin, OrganizationSuperAdmin),
 *   - cross-org rows when the actor is OrgSuper (super admin is always
 *     permitted regardless of org scope).
 */
export function canMutateTargetLifecycle(
  actor: ActorView | null | undefined,
  target: Pick<AdminUser, 'roles' | 'organization_id'> | null | undefined,
): boolean {
  if (!target) return false;
  if (isProtectedAdminTarget(target)) return false;
  if (actor?.is_super_admin === true) return true;
  if (actor?.is_organization_super_admin === true) {
    return isSameOrgAsActor(actor, target);
  }
  return false;
}

/**
 * Whether the actor may export activity logs. The backend's activity-log
 * export endpoint is platform-wide and gated by a super-admin capability;
 * OrgSuper is never permitted to call it. Used by the activity-logs page
 * to decide whether to render the CSV/JSON export buttons.
 */
export function canExportActivityLogs(actor: ActorView | null | undefined): boolean {
  return actor?.is_super_admin === true;
}
