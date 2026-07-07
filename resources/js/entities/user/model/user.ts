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

// الأدوار السياقية
export interface ScopedRole {
  role: string;
  role_display: string;
  scope_id: number;
  scope_name: string;
  expires_at: string | null;
}

export interface ScopedRolesResponse {
  data: {
    projects: ScopedRole[];
    departments: ScopedRole[];
  };
}
