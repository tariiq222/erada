/**
 * User entity — API (إدارة المستخدمين).
 */

import { api } from '@shared/api/client';
import type { UserRoleAssignmentsResponse, UserSecurityResponse } from '../model/user';

export const usersApi = {
  getAll: (params?: Record<string, string>) => {
    const query = params ? '?' + new URLSearchParams(params).toString() : '';
    return api.get(`/users${query}`);
  },
  getList: (departmentIds?: number[]) => {
    const query = departmentIds && departmentIds.length > 0
      ? `?department_ids=${departmentIds.join(',')}`
      : '';
    return api.get(`/users/list${query}`);
  },
  getStats: () => api.get('/users/stats'),
  getOne: (id: number) => api.get(`/users/${id}`),
  create: (data: any) => api.post('/users', data),
  update: (id: number, data: any) => api.put(`/users/${id}`, data),
  delete: (id: number) => api.delete(`/users/${id}`),

  // حالة الأمان (قفل الحساب، المحاولات الفاشلة، آخر دخول)
  getSecurity: (id: number) =>
    api.get<UserSecurityResponse>(`/users/${id}/security`),

  // فك قفل الحساب
  unlock: (id: number) => api.post(`/users/${id}/unlock`),

  // Canonical authorization-role assignments.
  roleAssignments: (id: number) =>
    api.get<UserRoleAssignmentsResponse>(`/authorization-role-assignments/user/${id}`),
};
