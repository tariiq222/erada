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
  OrganizationSettingsEnvelope,
  OrganizationSettingsPayload,
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
  organizationSettings: {
    /**
     * GET /api/organizations/{organization}/settings
     *
     * Strictly non-mutating on the server (no row write, no audit row).
     * Reads the persisted payload or the default payload if no row exists.
     */
    get: (organizationId: number) =>
      api.get<OrganizationSettingsEnvelope>(`/organizations/${organizationId}/settings`),
    /**
     * PUT /api/organizations/{organization}/settings
     *
     * Sends an `X-Idempotency-Key` header so that retries within the
     * backend's 300s idempotency window don't double-apply the merge.
     * The endpoint is mounted behind `idempotency` middleware
     * (`App\Http\Middleware\IdempotencyKey`); without the header the
     * request still works, but the dedup guarantee is lost.
     *
     * Scope is `resources/admin` only: we use raw fetch here (instead
     * of `api.put`) so the extra header can ride along without
     * modifying the shared `@shared/api/client` surface.
     */
    update: async (
      organizationId: number,
      payload: Partial<OrganizationSettingsPayload>,
      idempotencyKey: string,
    ): Promise<OrganizationSettingsEnvelope> => {
      const csrfToken = readMetaCsrfToken();
      const headers: Record<string, string> = {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-Idempotency-Key': idempotencyKey,
      };
      if (csrfToken) {
        headers['X-CSRF-TOKEN'] = csrfToken;
      }
      const xsrf = readCookie('XSRF-TOKEN');
      if (xsrf) {
        headers['X-XSRF-TOKEN'] = xsrf;
      }
      const response = await fetch(`/api/organizations/${organizationId}/settings`, {
        method: 'PUT',
        credentials: 'include',
        headers,
        body: JSON.stringify(payload),
      });
      if (!response.ok) {
        const errorBody = await safeReadJson(response);
        throw {
          status: response.status,
          message: errorBody?.message ?? `Request failed (${response.status})`,
          errors: errorBody?.errors,
        };
      }
      return (await response.json()) as OrganizationSettingsEnvelope;
    },
  },
};

/**
 * Read the same CSRF token the shared ApiClient uses. Mirrors
 * `getCsrfToken` in `resources/js/shared/api/client.ts` so the admin
 * PUT path picks up the latest token without us touching the shared
 * client surface.
 */
function readMetaCsrfToken(): string | null {
  if (typeof document === 'undefined') return null;
  return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || null;
}

function readCookie(name: string): string | null {
  if (typeof document === 'undefined') return null;
  const cookie = document.cookie
    .split('; ')
    .find((row) => row.startsWith(`${name}=`));
  if (!cookie) return null;
  return decodeURIComponent(cookie.split('=')[1] ?? '');
}

async function safeReadJson(response: Response): Promise<{ message?: string; errors?: Record<string, string[]> } | null> {
  try {
    return (await response.json()) as { message?: string; errors?: Record<string, string[]> };
  } catch {
    return null;
  }
}
