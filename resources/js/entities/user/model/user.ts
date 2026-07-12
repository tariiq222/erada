/**
 * User entity — model (types/interfaces).
 */

// حالة الأمان
export interface UserSecurity {
  is_locked: boolean;
  locked_until: string | null;
  failed_attempts: number;
  last_failed_login: string | null;
  last_login: string | null;
  last_login_ip: string | null;
}

export interface UserSecurityResponse {
  security: UserSecurity;
}

/** Canonical authorization assignment summary returned for a user. */
export interface UserRoleAssignment {
  id: number;
  role_id: number;
  role: string;
  label: string;
  scope_type: string;
  scope_id: number | null;
  scope_name: string | null;
  organization_id: number | null;
  inherit_to_children: boolean;
  expires_at: string | null;
  source: string;
  granted_by: number | null;
}

export interface UserRoleAssignmentsResponse {
  data: UserRoleAssignment[];
}
