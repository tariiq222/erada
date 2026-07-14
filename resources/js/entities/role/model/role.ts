/**
 * Role entity — model (types/interfaces).
 */

export interface Role {
  id: number;
  name: string;
  label: string;
  label_ar?: string;
  label_en?: string;
  scope_type: string;
  capabilities: string[];
  reach: Record<string, string>;
  users_count: number;
  is_system: boolean;
  is_admin_role: boolean;
  is_active: boolean;
  created_at?: string;
}

/**
 * Roles Organization Super Admin is NEVER allowed to assign.
 *
 * Mirrors the forbidden-name list baked into
 * App\Modules\Core\Authorization\Services\OrganizationSuperAdminRoleAssignmentActorGuard
 * (`FORBIDDEN_ROLE_NAMES`) and the `is_admin_role || is_system`
 * gate. The FE MUST keep this list in lock-step with the BE — any
 * divergence lets an OrgSuper actor submit a payload the BE rejects,
 * which surfaces as a 422 on the assignment route.
 */
export const ORG_SUPER_FORBIDDEN_ROLE_NAMES: readonly string[] = [
  'super_admin',
  'organization_super_admin',
  'admin',
] as const;

/**
 * Predicate: true when an Organization Super Admin actor is NOT
 * permitted to assign `role` — either the role is a forbidden name,
 * an admin-only role, or a system role. UI must use this to filter
 * the role picker before submitting to
 * `POST /api/org-super/role-assignments`; the BE enforces the same
 * condition in `OrganizationSuperAdminRoleAssignmentActorGuard`.
 */
export function isProtectedRoleForOrgSuper(role: Pick<Role, 'name' | 'is_admin_role' | 'is_system'>): boolean {
  if (role.is_admin_role || role.is_system) return true;
  return ORG_SUPER_FORBIDDEN_ROLE_NAMES.includes(role.name);
}

/**
 * Drop every role that Organization Super Admin is forbidden to
 * assign. Convenience wrapper around `isProtectedRoleForOrgSuper`
 * used by the user-role UI to remove protected roles from the
 * picker before the actor submits an assignment.
 */
export function filterAssignableRolesForOrgSuper<R extends Pick<Role, 'name' | 'is_admin_role' | 'is_system'>>(roles: readonly R[]): R[] {
  return roles.filter((role) => !isProtectedRoleForOrgSuper(role));
}

export interface RoleWrite {
  name: string;
  label: string;
  label_ar?: string;
  label_en?: string;
  scope_type: string;
  capabilities: string[];
  reach: Record<string, string>;
  is_active: boolean;
}

export type AuthorizationAssignmentScopeType =
  | 'all'
  | 'organization'
  | 'department'
  | 'own'
  | 'project'
  | 'program'
  | 'portfolio'
  | 'kpi'
  | 'meeting'
  | 'survey';

/** Explicit canonical assignment write contract. Server-owned provenance is omitted. */
export interface AuthorizationRoleAssignmentWrite {
  role_id: number;
  scope_type: AuthorizationAssignmentScopeType;
  scope_id: number | null;
  inherit_to_children: boolean;
  expires_at?: string | null;
}

export interface AuthorizationRoleAssignmentRequest {
  user_id: number;
  replace_all: true;
  assignments: AuthorizationRoleAssignmentWrite[];
}

/** Canonical assignment returned after the server has resolved tenancy and provenance. */
export interface AuthorizationRoleAssignment extends AuthorizationRoleAssignmentWrite {
  id: number;
  role_name: string;
  organization_id: number | null;
  expires_at: string | null;
  source: string;
}

export interface AuthorizationRoleAssignmentResponse {
  data: {
    user_id: number;
    assignments: AuthorizationRoleAssignment[];
  };
}

// ===== Unified ability registry (single source for the role builder) =====

export interface Ability {
  id: string;
  label: string;
}

export interface AbilityGroup {
  key: string;
  label: string;
  abilities: Ability[];
}

export interface AbilityRegistry {
  groups: AbilityGroup[];
}
