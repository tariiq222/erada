export interface ScopedRoleAuditLog {
  id: number;
  user: { id: number; name: string } | null;
  action: string;
  description: string;
  target_user: { id: number; name: string } | null;
  scope_type: string | null;
  scope_id: number | null;
  role: string | null;
  ip_address: string | null;
  created_at: string;
}

export interface ScopedRoleAuditLogParams {
  action?: string;
  user_id?: number | string;
  scope_type?: string;
  from_date?: string;
  to_date?: string;
  per_page?: number;
  page?: number;
}
