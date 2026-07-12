import { api } from '@shared/api/client';
import type {
  AuthorizationAccessSummary,
  AuthorizationAssignmentAuditLog,
  AuthorizationAssignmentAuditLogParams,
} from '../model/authorization-assignment';

export interface AuthorizationAssignmentPaginated<T> {
  data: T[];
  current_page?: number;
  last_page?: number;
  per_page?: number;
  total?: number;
  meta?: { current_page: number; last_page: number; per_page: number; total: number };
}

function buildQuery(params?: AuthorizationAssignmentAuditLogParams): string {
  if (!params) return '';
  const sp = new URLSearchParams();
  Object.entries(params).forEach(([k, v]) => {
    if (v !== undefined && v !== null && v !== '') sp.append(k, String(v));
  });
  const qs = sp.toString();
  return qs ? `?${qs}` : '';
}

export const authorizationAssignmentsApi = {
  auditLogs: (params?: AuthorizationAssignmentAuditLogParams) =>
    api.get<AuthorizationAssignmentPaginated<AuthorizationAssignmentAuditLog>>(
      '/authorization-role-assignments/audit-logs' + buildQuery(params),
    ),
  accessSummary: (userId: number) =>
    api.get<{ data: AuthorizationAccessSummary }>(
      `/authorization-role-assignments/user/${userId}/access-summary`,
    ),
};
