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
