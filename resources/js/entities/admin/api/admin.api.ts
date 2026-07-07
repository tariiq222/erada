/**
 * Admin entity — API (Organizations, ScopeTypes, ActivityLog,
 * M1 Super Admin System Governance Console).
 */

import { api } from '@shared/api/client';
import type {
  Organization,
  ScopeType,
  ActivityLogEntry,
  PaginatedResponse,
  OverviewCounts,
  AuditRecentResponse,
  SecurityAlerts,
} from '../model/admin';

function buildQuery(params?: Record<string, string | number | boolean>): string {
  if (!params) return '';
  const entries = Object.entries(params).map(([k, v]) => [k, String(v)] as [string, string]);
  return '?' + new URLSearchParams(Object.fromEntries(entries)).toString();
}

function appendToQuery(base: string, extra: string): string {
  if (!extra) return base;
  return base + (base.includes('?') ? '&' : '?') + extra.replace(/^\?/, '');
}

export const organizationsApi = {
  list: (params?: Record<string, string | number | boolean>) =>
    api.get<PaginatedResponse<Organization>>('/organizations' + buildQuery(params)),
  get: (id: number) => api.get<{ data: Organization }>(`/organizations/${id}`),
  create: (data: Partial<Organization>) => api.post('/organizations', data),
  update: (id: number, data: Partial<Organization>) =>
    api.put(`/organizations/${id}`, data),
  delete: (id: number) => api.delete(`/organizations/${id}`),
};

export const scopeTypesApi = {
  list: (params?: Record<string, string | number | boolean>) =>
    api.get<PaginatedResponse<ScopeType>>('/scope-types' + buildQuery(params)),
  get: (id: number) => api.get<{ data: ScopeType }>(`/scope-types/${id}`),
  create: (data: Partial<ScopeType>) => api.post('/scope-types', data),
  update: (id: number, data: Partial<ScopeType>) =>
    api.put(`/scope-types/${id}`, data),
  delete: (id: number) => api.delete(`/scope-types/${id}`),
};

export const activityLogsApi = {
  list: (params?: Record<string, string | number | boolean>) =>
    api.get<PaginatedResponse<ActivityLogEntry>>('/activity-logs' + buildQuery(params)),
  get: (id: number) => api.get<{ data: ActivityLogEntry }>(`/activity-logs/${id}`),
  exportCsv: (params?: Record<string, string>): Promise<Blob> => {
    const qs = buildQuery(params);
    const url = appendToQuery('/activity-logs/export?format=csv', qs);
    return api.blob(url);
  },
  exportJson: (params?: Record<string, string>): Promise<Blob> => {
    const qs = buildQuery(params);
    const url = appendToQuery('/activity-logs/export?format=json', qs);
    return api.blob(url);
  },
};

/**
 * M1 Super Admin System Governance Console (read-mostly).
 * Mounted under /api/admin/* and gated by role:super_admin server-side.
 */
export const superAdminDashboardApi = {
  overview: () =>
    api.get<{ data: OverviewCounts }>('/admin/overview'),
  securityAlerts: () =>
    api.get<{ data: SecurityAlerts }>('/admin/security/alerts'),
  auditRecent: (params?: { page?: number; per_page?: number }) =>
    api.get<AuditRecentResponse>(
      '/admin/audit/recent' + buildQuery(params as Record<string, string | number> | undefined),
    ),
};
