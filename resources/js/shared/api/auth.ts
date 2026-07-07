/**
 * Auth & Account APIs - المصادقة والحسابات
 */

import type { User } from '@shared/types';
import { api } from '@shared/api/client';
import type { LoginResponse, UserResponse } from './types';

// Auth API
export const authApi = {
  login: async (email: string, password: string) => {
    // تجديد CSRF cookie قبل تسجيل الدخول لضمان صلاحية الـ token
    await api.refreshCsrfCookie();
    return api.post<LoginResponse>('/login', { email, password });
  },
  logout: () => api.post<{ message: string }>('/logout'),
  getUser: () => api.get<UserResponse>('/user'),
};

// Profile API
export const profileApi = {
  update: (data: { name: string; email: string; phone?: string; extension?: string; job_title?: string }) =>
    api.put<{ message: string; user: User }>('/profile', data),
  changePassword: (data: { current_password: string; password: string; password_confirmation: string }) =>
    api.put<{ message: string }>('/profile/password', data),
};
