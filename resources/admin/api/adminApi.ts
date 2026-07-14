import { api } from '@shared/api/client';
import type {
  AbilityRegistry,
  AccessSummary,
  ActivityLogEntry,
  AdminDepartment,
  AdminDepartmentInput,
  AdminUser,
  AdminUserInput,
  AdminUserSummary,
  AuditRecentResponse,
  DepartmentSummary,
  GovernanceRule,
  IncidentType,
  IncidentTypeInput,
  Organization,
  OrganizationInput,
  OrganizationSettingsInput,
  OrganizationSettingsResponse,
  OperationalRoleAssignmentInput,
  OverviewCounts,
  PaginatedResponse,
  RoleDefinition,
  RoleInput,
  RawPaginatedResponse,
  ScopeType,
  SecurityAlertsData,
  ScopedRoleAuditLog,
  UserSecurityStatus,
} from '@admin/model/admin';

type QueryValue = string | number | boolean | null | undefined;
type DepartmentPage = { data: DepartmentSummary[]; current_page?: number; last_page?: number; per_page?: number; total?: number };

async function pathBoundFetch<T>(endpoint: string, options: { method: 'GET' | 'PUT'; body?: unknown; idempotencyKey?: string }): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json' };
  if (options.method === 'PUT') {
    headers['Content-Type'] = 'application/json';
    if (options.idempotencyKey) headers['Idempotency-Key'] = options.idempotencyKey;
  }
  const response = await fetch(`/api${endpoint}`, {
    method: options.method,
    credentials: 'include',
    headers,
    body: options.body === undefined ? undefined : JSON.stringify(options.body),
  });
  if (!response.ok) {
    const body = await response.json().catch(() => ({})) as { message?: string; errors?: Record<string, string[]> };
    throw { message: body.errors ? Object.values(body.errors).flat()[0] : body.message, errors: body.errors, status: response.status };
  }
  return response.json() as Promise<T>;
}

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
  return `/activity-logs/export?format=${format}${suffix ? `&${suffix.slice(1)}` : ''}`;
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
      api.get<PaginatedResponse<Organization>>(`/organizations${queryString(params)}`),
    get: (id: number) => api.get<{ data: Organization }>(`/organizations/${id}`),
    create: (data: Partial<OrganizationInput>) => api.post('/organizations', data),
    update: (id: number, data: Partial<OrganizationInput>) =>
      api.put(`/organizations/${id}`, data),
    delete: (id: number) => api.delete(`/organizations/${id}`),
    all: async () => {
      const data: Organization[] = [];
      let page = 1;
      let lastPage = 1;
      do {
        const response = await api.get<PaginatedResponse<Organization>>(`/organizations${queryString({ per_page: 100, page })}`);
        data.push(...response.data);
        lastPage = response.meta.last_page;
        page += 1;
      } while (page <= lastPage);
      return { data };
    },
  },
  organizationSettings: {
    get: (organizationId: number) => pathBoundFetch<OrganizationSettingsResponse>(`/organizations/${organizationId}/settings/`, { method: 'GET' }),
    update: (organizationId: number, data: OrganizationSettingsInput, options: { idempotencyKey?: string } = {}) =>
      pathBoundFetch<OrganizationSettingsResponse>(`/organizations/${organizationId}/settings/`, {
        method: 'PUT', body: data, idempotencyKey: options.idempotencyKey ?? crypto.randomUUID(),
      }),
  },
  roles: {
    list: () => api.get<{ data: RoleDefinition[]; meta: { total: number } }>('/roles'),
    get: (id: number) => api.get<{ data: RoleDefinition }>(`/roles/${id}`),
    abilities: () => api.get<{ data: AbilityRegistry }>('/roles/abilities'),
    scopeOptions: () => api.get<{ scopes: { key: string; label: string }[] }>('/roles/scope-options'),
    create: (data: RoleInput) => api.post('/roles', data),
    update: (id: number, data: RoleInput) => api.put(`/roles/${id}`, data),
    delete: (id: number) => api.delete(`/roles/${id}`),
  },
  users: {
    summary: () => api.get<{ data: AdminUserSummary[] }>('/users?per_page=100'),
    list: (params?: Record<string, QueryValue>) =>
      api.get<RawPaginatedResponse<AdminUser>>(`/users${queryString(params)}`),
    get: (id: number) => api.get<AdminUser>(`/users/${id}`),
    security: (id: number) => api.get<{ security: UserSecurityStatus }>(`/users/${id}/security`),
    create: (data: Partial<AdminUserInput>) => api.post('/users', data),
    update: (id: number, data: Partial<AdminUserInput>) => api.put(`/users/${id}`, data),
    unlock: (id: number) => api.post(`/users/${id}/unlock`, undefined),
    delete: (id: number) => api.delete(`/users/${id}`),
    all: async (organizationId: number) => {
      const data: AdminUser[] = [];
      let page = 1;
      let lastPage = 1;
      do {
        const response = await api.get<RawPaginatedResponse<AdminUser>>(`/users${queryString({ organization_id: organizationId, per_page: 100, page })}`);
        data.push(...response.data);
        lastPage = response.last_page;
        page += 1;
      } while (page <= lastPage);
      return { data };
    },
  },
  access: {
    summary: (userId: number) =>
      api.get<{ data: AccessSummary }>(`/authorization-role-assignments/user/${userId}/access-summary`),
    assignOperationalRole: (data: OperationalRoleAssignmentInput) =>
      api.post<{ message: string; data: { user_id: number; assignments: unknown[] } }>(
        '/org-super/role-assignments',
        data,
      ),
  },
  departments: {
    list: (params?: Record<string, QueryValue>) =>
      api.get<RawPaginatedResponse<AdminDepartment>>(`/admin/departments${queryString(params)}`),
    get: (id: number) => api.get<AdminDepartment>(`/admin/departments/${id}`),
    hierarchy: () => api.get('/admin/departments/hierarchy'),
    create: (data: Partial<AdminDepartmentInput>) => api.post('/admin/departments', data),
    update: (id: number, data: Partial<AdminDepartmentInput>) => api.put(`/admin/departments/${id}`, data),
    delete: (id: number) => api.delete(`/admin/departments/${id}`),
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
    list: () => api.get<{ data: GovernanceRule[] }>('/governance-rules'),
    update: (data: { resource_type: string; resource_subtype?: string | null; governing_unit_id: number | null }) =>
      api.put('/governance-rules', data),
  },
  activityLogs: {
    list: (params?: Record<string, QueryValue>) =>
      api.get<PaginatedResponse<ActivityLogEntry>>(`/activity-logs${queryString(params)}`),
    export: (format: 'csv' | 'json', params?: Record<string, QueryValue>) =>
      api.blob(exportQuery(format, params)),
  },
  scopedRoleAudit: {
    list: (params?: Record<string, QueryValue>) =>
      api.get<PaginatedResponse<ScopedRoleAuditLog>>(`/authorization-role-assignments/audit-logs${queryString(params)}`),
  },
  scopeTypes: {
    list: (params?: Record<string, QueryValue>) =>
      api.get<PaginatedResponse<ScopeType>>(`/scope-types${queryString(params)}`),
  },
  incidentTypes: {
    list: (params?: { include_inactive?: boolean }) =>
      api.get<{ data: IncidentType[] }>(`/admin/incident-types${queryString({
        include_inactive: params?.include_inactive ? 1 : undefined,
      })}`),
    create: (data: IncidentTypeInput) => api.post('/admin/incident-types', data),
    update: (id: string, data: Partial<IncidentTypeInput>) => api.put(`/admin/incident-types/${id}`, data),
    delete: (id: string) => api.delete(`/admin/incident-types/${id}`),
    addReportableType: (id: string, data: { name: string; name_ar: string }) =>
      api.post(`/admin/incident-types/${id}/reportable-types`, data),
  },
};
