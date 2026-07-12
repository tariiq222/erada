/**
 * Projects API - إدارة المشاريع
 */

import { api } from '@shared/api/client';
import type { CreateProjectRequest } from '@shared/api/types';

// Admin: project + attachment system-wide settings.
// Each top-level group is optional on the payload (partial updates allowed);
// the server merges with the stored settings.
export interface ProjectSettings {
  project: {
    default_status: 'draft' | 'planning' | 'in_progress' | 'on_hold' | 'completed' | 'cancelled';
  };
  attachments: {
    max_size_mb: number;
    allowed_types: string[];
  };
}

export type ProjectSettingsPayload = Partial<{
  project: Partial<ProjectSettings['project']>;
  attachments: Partial<ProjectSettings['attachments']>;
}>;

export interface CanonicalRoleOption {
  id: number;
  name: string;
  label: string;
}

export interface CanonicalProjectRoleAssignment {
  id: number;
  user_id?: number;
  role_id: number;
  role_name: string;
  role_display: string;
  scope_type: 'project';
  scope_id: number;
  expires_at: string | null;
  user?: { id: number; name: string; email?: string; job_title?: string | null };
}

export interface CanonicalProjectRolesResponse {
  data: CanonicalProjectRoleAssignment[];
  available_roles: CanonicalRoleOption[];
}

export interface CanonicalProjectRoleWrite {
  user_id: number;
  role_id: number;
  expires_at?: string | null;
}

export const projectsApi = {
  getAll: (params?: Record<string, string>) => {
    const query = params ? '?' + new URLSearchParams(params).toString() : '';
    return api.get(`/projects${query}`);
  },
  getOne: (id: number) => api.get(`/projects/${id}`),
  // Departments the current user may target when creating a project of `type`.
  getCreatableDepartments: (type?: string) =>
    api.get(`/projects/creatable-departments${type ? `?type=${encodeURIComponent(type)}` : ''}`),
  // Users the current user may assign as the project manager when creating
  // a project of `type`. Returned shape (per backend contract):
  // { id, name, email, job_title, department_id }.
  // Active, in-scope, project-creation-eligible users only.
  getAssignableManagers: (type: 'development' | 'improvement') =>
    api
      .get<{ data: Array<{ id: number; name: string; email: string; job_title: string | null; department_id: number | null }> }>(
        `/projects/assignable-managers?type=${encodeURIComponent(type)}`
      )
      .then((res: any) => res?.data ?? []),
  // Admin: governing-department-per-type configuration.
  getGoverningDepartments: () => api.get('/projects/governing-departments'),
  updateGoverningDepartments: (mapping: Record<string, number | null>) =>
    api.put('/projects/governing-departments', { mapping }),
  // Admin: general project + attachment system-wide settings.
  getSettings: () => api.get('/projects/settings'),
  updateSettings: (payload: ProjectSettingsPayload) =>
    api.put('/projects/settings', payload),
  create: (data: CreateProjectRequest) => api.post('/projects', data),
  update: (id: number, data: Partial<CreateProjectRequest>) => api.put(`/projects/${id}`, data),
  delete: (id: number) => api.delete<{ message: string }>(`/projects/${id}`),
  getStats: (id: number) => api.get(`/projects/${id}/stats`),
  getActivityLog: (id: number, params?: Record<string, string>) => {
    const query = params ? '?' + new URLSearchParams(params).toString() : '';
    return api.get(`/projects/${id}/activity-log${query}`);
  },

  // أعضاء الفريق
  getMembers: (projectId: number) =>
    api.get(`/projects/${projectId}/members`),
  getRoleAssignments: (projectId: number) =>
    api.get<CanonicalProjectRolesResponse>(`/projects/${projectId}/roles`),
  addMember: (projectId: number, data: { user_id: number; role?: string }) =>
    api.post(`/projects/${projectId}/members`, data),
  assignRoleAssignment: (projectId: number, data: CanonicalProjectRoleWrite) =>
    api.post<{ data: CanonicalProjectRoleAssignment }>(`/projects/${projectId}/roles`, data),
  updateMemberRole: (projectId: number, userId: number, roleId: number) =>
    api.put<{ data: CanonicalProjectRoleAssignment }>(`/projects/${projectId}/roles/${userId}`, {
      role_id: roleId,
    }),
  removeMember: (projectId: number, userId: number, roleId?: number) =>
    roleId === undefined
      ? api.delete(`/projects/${projectId}/members/${userId}`)
      : api.delete(`/projects/${projectId}/roles/${userId}?role_id=${roleId}`),

  // مراحل PDCA (مشاريع التحسين)
  updatePdcaPhase: (
    id: number,
    payload: { phase: string; measurements?: Array<{ kpi_id: number; value: number; measurement_date: string }> },
  ) => api.patch(`/projects/${id}/pdca-phase`, payload),

  // أصحاب المصلحة
  addStakeholder: (projectId: number, data: { name: string; role: string; organization?: string; email?: string; phone?: string }) =>
    api.post(`/projects/${projectId}/stakeholders`, data),
  updateStakeholder: (projectId: number, stakeholderId: number, data: { name?: string; role?: string; organization?: string | null; email?: string | null; phone?: string | null; influence?: string }) =>
    api.put(`/projects/${projectId}/stakeholders/${stakeholderId}`, data),
  getStakeholder: (projectId: number, stakeholderId: number) =>
    api.get(`/projects/${projectId}/stakeholders/${stakeholderId}`),
  removeStakeholder: (projectId: number, stakeholderId: number) =>
    api.delete(`/projects/${projectId}/stakeholders/${stakeholderId}`),

  // المخاطر
  addRisk: (projectId: number, data: { risk: string; probability: string; impact: string; response?: string; status?: string }) =>
    api.post(`/projects/${projectId}/risks`, data),
  removeRisk: (projectId: number, riskId: number) =>
    api.delete(`/projects/${projectId}/risks/${riskId}`),
  updateRisk: (projectId: number, riskId: number, data: { risk?: string; probability?: string; impact?: string; response?: string; status?: string }) =>
    api.put(`/projects/${projectId}/risks/${riskId}`, data),

  // المصروفات (Expenses)
  getExpenses: (projectId: number, params?: Record<string, string>) => {
    const query = params ? '?' + new URLSearchParams(params).toString() : '';
    return api.get(`/projects/${projectId}/expenses${query}`);
  },
  getExpensesSummary: (projectId: number) =>
    api.get(`/projects/${projectId}/expenses/summary`),
  createExpense: async (projectId: number, data: {
    title: string;
    description?: string;
    amount: number;
    category: string;
    expense_date: string;
    task_id?: number;
    reference_number?: string;
    attachment?: File;
  }) => {
    // إذا كان هناك مرفق، استخدم FormData
    if (data.attachment) {
      const formData = new FormData();
      formData.append('title', data.title);
      if (data.description) formData.append('description', data.description);
      formData.append('amount', String(data.amount));
      formData.append('category', data.category);
      formData.append('expense_date', data.expense_date);
      if (data.task_id) formData.append('task_id', String(data.task_id));
      if (data.reference_number) formData.append('reference_number', data.reference_number);
      formData.append('attachment', data.attachment);

      return api.post(`/projects/${projectId}/expenses`, formData);
    }

    // بدون مرفق، استخدم JSON
    return api.post(`/projects/${projectId}/expenses`, data);
  },
  updateExpense: async (projectId: number, expenseId: number, data: {
    title?: string;
    description?: string;
    amount?: number;
    category?: string;
    expense_date?: string;
    task_id?: number;
    reference_number?: string;
    attachment?: File | null;
    remove_attachment?: boolean;
  }) => {
    // إذا كان هناك مرفق جديد (أو طلب إزالة)، استخدم FormData مع _method=PUT
    // لأن multipart لا يدعم PUT في أغلب بيئات PHP/Laravel بدون _method override
    if (data.attachment || data.remove_attachment) {
      const formData = new FormData();
      if (data.title !== undefined) formData.append('title', String(data.title));
      if (data.description !== undefined && data.description !== null) {
        formData.append('description', data.description);
      }
      if (data.amount !== undefined) formData.append('amount', String(data.amount));
      if (data.category !== undefined) formData.append('category', data.category);
      if (data.expense_date !== undefined) formData.append('expense_date', data.expense_date);
      if (data.task_id !== undefined && data.task_id !== null) {
        formData.append('task_id', String(data.task_id));
      }
      if (data.reference_number !== undefined && data.reference_number !== null) {
        formData.append('reference_number', data.reference_number);
      }
      if (data.attachment) formData.append('attachment', data.attachment);
      if (data.remove_attachment) formData.append('remove_attachment', '1');
      formData.append('_method', 'PUT');

      // POST to the URL with _method=PUT so Laravel routes it to PUT on the controller.
      // The api client should NOT set Content-Type for FormData (browser sets boundary).
      return api.post(`/projects/${projectId}/expenses/${expenseId}`, formData);
    }

    // بدون مرفق/إزالة: JSON عادي
    return api.put(`/projects/${projectId}/expenses/${expenseId}`, data);
  },
  deleteExpense: (projectId: number, expenseId: number) =>
    api.delete(`/projects/${projectId}/expenses/${expenseId}`),
};
