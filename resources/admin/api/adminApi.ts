import { api } from '@shared/api/client';
import type {
  AbilityRegistry,
  AccessSummary,
  ActivityLogEntry,
  AdminUserSummary,
  AuditRecentResponse,
  DepartmentSummary,
  GovernanceRule,
  Organization,
  OrganizationInput,
  OverviewCounts,
  PaginatedResponse,
  RoleDefinition,
  RoleInput,
  ScopeType,
  SecurityAlertsData,
  ScopedRoleAuditLog,
} from '@admin/model/admin';

type QueryValue = string | number | boolean | null | undefined;
type DepartmentPage = { data: DepartmentSummary[]; current_page?: number; last_page?: number; per_page?: number; total?: number };

function queryString(params?: Record<string, QueryValue>): string {
  if (!params) return '';

  const query = new URLSearchParams();
  Object.entries(params).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '') {
      query.set(key, String(value));
    }
  });

  const serialized = query.toString();
  return serialized ? `?${serialized}` : '';
}

function exportQuery(format: 'csv' | 'json', params?: Record<string, QueryValue>): string {
  const suffix = queryString(params);
  return `/admin/activity-logs/export?format=${format}${suffix ? `&${suffix.slice(1)}` : ''}`;
}

export function apiErrorMessage(error: unknown, fallback: string): string {
  if (typeof error !== 'object' || error === null) return fallback;
  const candidate = error as { message?: string; errors?: Record<string, string[]> };
  const validation = candidate.errors ? Object.values(candidate.errors).flat()[0] : undefined;
  return validation ?? candidate.message ?? fallback;
}

export const adminApi = {
  overview: () => api.get<{ data: OverviewCounts }>('/admin/overview'),
  securityAlerts: () =>
    api.get<{ data: SecurityAlertsData }>('/admin/security/alerts'),
  auditRecent: (params?: { page?: number; per_page?: number }) =>
    api.get<AuditRecentResponse>(`/admin/audit/recent${queryString(params)}`),
  organizations: {
    list: (params?: Record<string, QueryValue>) =>
      api.get<PaginatedResponse<Organization>>(`/admin/organizations${queryString(params)}`),
    get: (id: number) => api.get<{ data: Organization }>(`/admin/organizations/${id}`),
    create: (data: Partial<OrganizationInput>) => api.post('/admin/organizations', data),
    update: (id: number, data: Partial<OrganizationInput>) =>
      api.put(`/admin/organizations/${id}`, data),
    delete: (id: number) => api.delete(`/admin/organizations/${id}`),
  },
  roles: {
    list: () => api.get<{ data: RoleDefinition[]; meta: { total: number } }>('/admin/roles'),
    get: (id: number) => api.get<{ data: RoleDefinition }>(`/admin/roles/${id}`),
    abilities: () => api.get<{ data: AbilityRegistry }>('/admin/roles/abilities'),
    scopeOptions: () => api.get<{ scopes: { key: string; label: string }[] }>('/admin/roles/scope-options'),
    create: (data: RoleInput) => api.post('/admin/roles', data),
    update: (id: number, data: RoleInput) => api.put(`/admin/roles/${id}`, data),
    delete: (id: number) => api.delete(`/admin/roles/${id}`),
  },
  users: {
    summary: () => api.get<{ data: AdminUserSummary[] }>('/admin/users?per_page=100'),
  },
  access: {
    summary: (userId: number) =>
      api.get<{ data: AccessSummary }>(`/admin/scoped-roles/user/${userId}/access-summary`),
  },
  departments: {
    summary: async (organizationId: number) => {
      const data: DepartmentSummary[] = [];
      let page = 1;
      let lastPage = 1;
      do {
        const response = await api.get<DepartmentPage>(`/admin/departments${queryString({ organization_id: organizationId, per_page: 100, page })}`);
        data.push(...response.data);
        lastPage = response.last_page ?? 1;
        page += 1;
      } while (page <= lastPage);
      return { data };
    },
  },
  governance: {
    list: () => api.get<{ data: GovernanceRule[] }>('/admin/governance-rules'),
    update: (data: { resource_type: string; resource_subtype?: string | null; governing_unit_id: number | null }) =>
      api.put('/admin/governance-rules', data),
  },
  activityLogs: {
    list: (params?: Record<string, QueryValue>) =>
      api.get<PaginatedResponse<ActivityLogEntry>>(`/admin/activity-logs${queryString(params)}`),
    export: (format: 'csv' | 'json', params?: Record<string, QueryValue>) =>
      api.blob(exportQuery(format, params)),
  },
  scopedRoleAudit: {
    list: (params?: Record<string, QueryValue>) =>
      api.get<PaginatedResponse<ScopedRoleAuditLog>>(`/admin/scoped-roles/audit-logs${queryString(params)}`),
  },
  scopeTypes: {
    list: (params?: Record<string, QueryValue>) =>
      api.get<PaginatedResponse<ScopeType>>(`/admin/scope-types${queryString(params)}`),
  },
};
