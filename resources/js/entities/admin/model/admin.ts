/**
 * Admin entity — model (Organizations, ScopeTypes, ActivityLog,
 * M1 Super Admin dashboard governance types).
 */

export type OrganizationType =
  | 'cluster'
  | 'hospital'
  | 'center'
  | 'organization'
  | 'other';

export const ORGANIZATION_TYPES: OrganizationType[] = [
  'cluster',
  'hospital',
  'center',
  'organization',
  'other',
];

export interface OrganizationParent {
  id: number;
  name: string;
  code: string;
  type: OrganizationType;
}

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
  parent: OrganizationParent | null;
  users_count?: number;
  projects_count?: number;
  created_at?: string;
  updated_at?: string;
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
  model_class?: string;
  description_ar?: string | null;
  description_en?: string | null;
  created_at?: string;
  updated_at?: string;
}

/**
 * Phase 4B — ActivityLogEntry FE shape.
 *
 * The backend ActivityLogResource (Phase 1) emits actor as { id, name }
 * only — the FE must NOT expect email on the user / actor pointer.
 * The earlier type leaked a stable `email: string` field; the page
 * still rendered it which made the page re-add email to the DOM
 * from a column the BE never returned. The Phase 1 backend contract
 * is opaque email. Both the resource and any actor pointer on the
 * same row comply with that contract — the FE honors the contract
 * by NOT typing email here.
 */
export interface ActivityLogEntry {
  id: number;
  user_id: number | null;
  user?: { id: number; name: string } | null;
  action: string;
  description: string;
  loggable_type: string | null;
  loggable_id: string | null;
  old_values: Record<string, unknown> | null;
  new_values: Record<string, unknown> | null;
  ip_address: string | null;
  user_agent: string | null;
  created_at: string;
  updated_at: string;
}

export interface PaginatedResponse<T> {
  data: T[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
}

// ========================================================
// M1 — Super Admin System Governance Console
// See docs/superpowers/specs/2026-07-03-super-admin-dashboard-proposal.md
// ========================================================

export interface TwoFactorCoverage {
  enabled: number;
  active_users: number;
  percent: number;
}

export interface OverviewCounts {
  organizations: {
    active: number;
    total: number;
  };
  users: {
    active: number;
    total: number;
    two_factor_coverage: TwoFactorCoverage;
  };
  login_attempts: {
    last_24h: {
      successful: number;
      failed: number;
      total: number;
    };
  };
  registrations: {
    pending: number;
    avg_pending_age_days: number | null;
  };
  generated_at: string;
}

export interface AuditRecentRow {
  id: number;
  action: string;
  description: string | null;
  // Phase 4B — Phase 1's AuditRecentResource returns actor as
  // { id, name } (no email). The earlier FE type leaked the email
  // field; the page was reading activityLogsApi.actor.email which
  // would render as undefined or surface a stale backend cache for
  // legacy rows. The FE honors the BE contract.
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
    per_page: number;
    limit: number;
    returned: number;
  };
}

export interface SecurityAlertWindow {
  minutes: number;
  cutoff: string;
  repeated_failure_threshold: number;
}

export interface SecurityAlertFailedLogin {
  // Either an email-bucket (has email, no ip_address) or an IP-bucket.
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

export interface SecurityAlerts {
  windows: SecurityAlertWindow;
  failed_logins_repeated: SecurityAlertFailedLogin[];
  access_denied_events: SecurityAlertAccessDenied[];
  generated_at: string;
}
