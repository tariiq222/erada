/**
 * Settings & Upload APIs - الإعدادات ورفع الملفات
 */

import { api } from '@shared/api/client';

// أنواع معاملات الفلترة
interface DashboardDateFilter {
  start_date?: string;
  end_date?: string;
}

// دالة مساعدة لبناء URL مع query params
function buildUrl(endpoint: string, params?: Record<string, string | undefined>): string {
  if (!params) return endpoint;
  const searchParams = new URLSearchParams();
  Object.entries(params).forEach(([key, value]) => {
    if (value !== undefined) {
      searchParams.append(key, value);
    }
  });
  const queryString = searchParams.toString();
  return queryString ? `${endpoint}?${queryString}` : endpoint;
}

// Dashboard API
export const dashboardApi = {
  getStats: (params?: DashboardDateFilter) =>
    api.get(buildUrl('/dashboard/stats', params as Record<string, string | undefined>)),
  getAdvancedStats: () =>
    api.get('/dashboard/advanced-stats'),
  getRecentProjects: () =>
    api.get('/dashboard/recent-projects'),
  getOverdueTasks: () =>
    api.get('/dashboard/overdue-tasks'),
  getMyUpcomingTasks: () =>
    api.get('/dashboard/my-upcoming-tasks'),
  getProjectsByStatus: (params?: DashboardDateFilter) =>
    api.get(buildUrl('/dashboard/projects-by-status', params as Record<string, string | undefined>)),
};

// System Settings API (إعدادات النظام)
export const systemSettingsApi = {
  get: () => api.get('/settings/system'),
  update: (data: any) => api.put('/settings/system', data),
};

// Upload APIs (رفع الملفات)
export const uploadApi = {
  uploadImage: async (file: File, folder?: string) => {
    const formData = new FormData();
    formData.append('image', file);
    if (folder) formData.append('folder', folder);

    try {
      return await api.post('/upload/image', formData);
    } catch (error) {
      throw new Error((error as { message?: string }).message || 'فشل في رفع الصورة');
    }
  },

  // رفع شعار عام (يستخدم للمستشفى الوحيد)
  uploadLogo: async (file: File) => {
    const formData = new FormData();
    formData.append('logo', file);

    try {
      return await api.post('/upload/logo', formData);
    } catch (error) {
      throw new Error((error as { message?: string }).message || 'فشل في رفع الشعار');
    }
  },
};
