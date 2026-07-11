import { api } from '@shared/api/client';
import type {
  AuditRecentResponse,
  OverviewCounts,
  SecurityAlertsData,
} from '@admin/model/admin';

function auditQuery(params?: { page?: number; per_page?: number }): string {
  if (!params) return '';

  const query = new URLSearchParams();
  if (params.page !== undefined) query.set('page', String(params.page));
  if (params.per_page !== undefined) query.set('per_page', String(params.per_page));

  const serialized = query.toString();
  return serialized ? `?${serialized}` : '';
}

export const adminApi = {
  overview: () => api.get<{ data: OverviewCounts }>('/admin/overview'),
  securityAlerts: () =>
    api.get<{ data: SecurityAlertsData }>('/admin/security/alerts'),
  auditRecent: (params?: { page?: number; per_page?: number }) =>
    api.get<AuditRecentResponse>(`/admin/audit/recent${auditQuery(params)}`),
};
