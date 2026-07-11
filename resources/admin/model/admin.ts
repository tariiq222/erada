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
