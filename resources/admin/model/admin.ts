export interface TwoFactorCoverage {
  enabled: number;
  active_users: number;
  percent: number;
}

export interface OverviewCounts {
  organizations: { active: number; total: number };
  users: {
    active: number;
    total: number;
    two_factor_coverage: TwoFactorCoverage;
  };
  login_attempts: {
    last_24h: { successful: number; failed: number; total: number };
  };
  generated_at: string;
}

export interface SecurityAlertFailedLogin {
  email?: string;
  ip_address?: string;
  attempts: number;
  distinct_emails?: number;
  first_attempted_at: string | null;
  last_attempted_at: string | null;
}

export interface SecurityAlertAccessDenied {
  id: number;
  user_id: number | null;
  action: string;
  route: string | null;
  ip_address: string | null;
  created_at: string | null;
}

export interface SecurityAlertsData {
  windows: {
    minutes: number;
    cutoff: string;
    repeated_failure_threshold: number;
  };
  failed_logins_repeated: SecurityAlertFailedLogin[];
  access_denied_events: SecurityAlertAccessDenied[];
  generated_at: string;
}

export interface AuditRecentRow {
  id: number;
  action: string;
  description: string | null;
  actor: { id: number; name: string } | null;
  target_user: { id: number; name: string } | null;
  scope_type: string | null;
  scope_id: number | null;
  role: string | null;
  ip_address: string | null;
  created_at: string | null;
}

export interface AuditRecentResponse {
  data: AuditRecentRow[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    limit: number;
    returned: number;
  };
}

export type OrganizationType =
  | 'cluster'
  | 'hospital'
  | 'center'
  | 'organization'
  | 'other';

export interface Organization {
  id: number;
  name: string;
  code: string;
  type: OrganizationType;
  parent_id: number | null;
  sort_order: number;
  description: string | null;
  email: string | null;
  phone: string | null;
  address: string | null;
  website: string | null;
  logo: string | null;
  is_active: boolean;
  is_root: boolean;
  can_have_children: boolean;
  allowed_child_types: OrganizationType[];
  children_count: number;
  parent: { id: number; name: string; code: string; type: OrganizationType } | null;
  users_count?: number;
  projects_count?: number;
}

export interface OrganizationInput {
  name: string;
  code: string;
  type: OrganizationType;
  parent_id: number | null;
  description: string | null;
  email: string | null;
  phone: string | null;
  address: string | null;
  website: string | null;
  is_active: boolean;
  sort_order: number;
}

export interface PaginatedResponse<T> {
  data: T[];
  meta: { current_page: number; last_page: number; per_page: number; total: number };
}

export interface RoleDefinition {
  id: number;
  name: string;
  display_name: string;
  permissions: string[];
  permissions_count: number;
  users_count: number;
  is_system: boolean;
  scope_type: string;
  label_ar?: string;
  label_en?: string;
  capabilities?: string[];
  reach?: Record<string, Reach>;
  is_admin_role?: boolean;
}

export type Reach = 'own' | 'department' | 'all';

export interface RoleInput {
  name?: string;
  scope_type?: string;
  label_ar?: string;
  label_en?: string;
  permissions_capabilities?: string[];
  reach?: Record<string, Reach>;
}

export interface AbilityRegistry {
  groups: {
    key: string;
    label: string;
    store: 'engine' | 'flat';
    abilities: { id: string; label: string }[];
  }[];
}

export interface AccessSummary {
  functional_roles: string[];
  scoped: {
    role: string;
    label: string;
    scope_type: string;
    scope_id: number;
    scope_name: string | null;
    source: string;
    reach: Record<string, Reach>;
  }[];
}

export interface AdminUserSummary {
  id: number;
  name: string;
  email: string;
  department?: { id: number; name: string } | null;
}

export interface GovernanceRule {
  resource_type: string;
  label: string;
  governing_unit_id: number | null;
  governing_unit_name: string | null;
  applies_to_children: boolean;
}

export interface DepartmentSummary {
  id: number;
  name: string;
}

export interface ActivityLogEntry {
  id: number;
  user?: { id: number; name: string } | null;
  action: string;
  description: string | null;
  loggable_type: string | null;
  loggable_id: number | string | null;
  ip_address: string | null;
  user_agent: string | null;
  created_at: string;
  updated_at: string;
}

export interface ScopedRoleAuditLog {
  id: number;
  user: { id: number; name: string } | null;
  action: string;
  description: string | null;
  target_user: { id: number; name: string } | null;
  scope_type: string | null;
  scope_id: number | null;
  role: string | null;
  ip_address: string | null;
  created_at: string;
}

export interface ScopeType {
  id: number;
  key: string;
  label_ar: string;
  label_en: string;
  icon: string | null;
  color: string | null;
  sort_order: number;
  is_active: boolean;
}
