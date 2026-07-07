/**
 * HR Module APIs - الموارد البشرية
 */

import { api } from '@shared/api/client';

function qs(params?: Record<string, string | number>): string {
  if (!params) return '';
  const usp = new URLSearchParams();
  Object.entries(params).forEach(([k, v]) => {
    if (v !== undefined && v !== null && v !== '') usp.set(k, String(v));
  });
  const s = usp.toString();
  return s ? `?${s}` : '';
}

// Departments API (الأقسام)
export const departmentsApi = {
  getAll: (params?: Record<string, string>) => {
    const query = params ? '?' + new URLSearchParams(params).toString() : '';
    return api.get(`/hr/departments${query}`);
  },
  getList: () => api.get('/hr/departments/list'),
  getTree: () => api.get('/hr/departments/tree'),
  getHierarchy: () => api.get('/hr/departments/hierarchy'),
  getAllowedLevels: (parentId?: number | string | null) => {
    const param = parentId !== undefined && parentId !== null ? `?parent_id=${parentId}` : '';
    return api.get(`/hr/departments/allowed-levels${param}`);
  },
  getOne: (id: number) => api.get(`/hr/departments/${id}`),
  create: (data: any) => api.post('/hr/departments', data),
  update: (id: number, data: any) => api.put(`/hr/departments/${id}`, data),
  delete: (id: number) => api.delete(`/hr/departments/${id}`),
  getCapacityRoles: (id: number) =>
    api.get<{
      member_role_keys: string[];
      manager_role_keys: string[];
      available: { role_key: string; label: string; scope: string | null }[];
    }>(`/hr/departments/${id}/capacity-roles`),
  getAvailableCapacityRoles: () =>
    api.get<{
      available: { role_key: string; label: string; scope: string | null }[];
    }>('/hr/departments/capacity-roles/available'),
  updateCapacityRoles: (
    id: number,
    payload: { member_role_keys: string[]; manager_role_keys: string[] }
  ) => api.put(`/hr/departments/${id}/capacity-roles`, payload),
};

// Employees API (الموظفين)
export const employeesApi = {
  getAll: (params?: Record<string, string | number>) => api.get(`/hr/employees${qs(params)}`),
  getList: (departmentId?: number) => {
    const query = departmentId ? `?department_id=${departmentId}` : '';
    return api.get(`/hr/employees/list${query}`);
  },
  getStats: () => api.get('/hr/employees/stats'),
  getOne: (id: number) => api.get(`/hr/employees/${id}`),
  create: (data: any) => api.post('/hr/employees', data),
  update: (id: number, data: any) => api.put(`/hr/employees/${id}`, data),
  delete: (id: number) => api.delete(`/hr/employees/${id}`),
};

// Employee Certificates API (شهادات الموظفين)
export const certificatesApi = {
  upload: (employeeId: number, formData: FormData) =>
    api.post(`/hr/employees/${employeeId}/certificates`, formData),
  delete: (certificateId: number) => api.delete(`/hr/certificates/${certificateId}`),
};

// Department Scoped Roles API (الأدوار السياقية للقسم)
export const departmentRolesApi = {
  getMembers: (deptId: number) => api.get(`/departments/${deptId}/roles`),
  assignRole: (
    deptId: number,
    data: { user_id: number; role: string; inherit_to_children?: boolean; expires_at?: string | null }
  ) => api.post(`/departments/${deptId}/roles`, data),
  removeMember: (deptId: number, userId: number) =>
    api.delete(`/departments/${deptId}/roles/${userId}`),
};
