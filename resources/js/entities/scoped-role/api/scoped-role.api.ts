import { api } from '@shared/api/client';
import type { ScopedRoleAuditLog, ScopedRoleAuditLogParams } from '../model/scoped-role';

export interface ScopedRolePaginated<T> {
  data: T[];
  current_page?: number;
  last_page?: number;
  per_page?: number;
  total?: number;
  meta?: { current_page: number; last_page: number; per_page: number; total: number };
}

function buildQuery(params?: ScopedRoleAuditLogParams): string {
  if (!params) return '';
  const sp = new URLSearchParams();
  Object.entries(params).forEach(([k, v]) => {
    if (v !== undefined && v !== null && v !== '') sp.append(k, String(v));
  });
  const qs = sp.toString();
  return qs ? `?${qs}` : '';
}

export const scopedRolesApi = {
  auditLogs: (params?: ScopedRoleAuditLogParams) =>
    api.get<ScopedRolePaginated<ScopedRoleAuditLog>>('/scoped-roles/audit-logs' + buildQuery(params)),
};
