import { api } from '@shared/api/client';
import type {
  MeetingCategory,
  MeetingCreatePayload,
  MeetingSettings,
  MeetingSettingsPayload,
  Recommendation,
  RecommendationCreatePayload,
  DeferPayload,
  RejectPayload,
  Notification,
  UnreadCount,
  NotificationListParams,
  AgendaItem,
  AgendaItemsResponse,
} from './types';

const qs = (params?: Record<string, string | number | boolean | undefined>): string => {
  if (!params) return '';
  const sp = new URLSearchParams();
  Object.entries(params).forEach(([k, v]) => {
    if (v !== undefined && v !== null && v !== '') sp.append(k, String(v));
  });
  const s = sp.toString();
  return s ? `?${s}` : '';
};

export const meetingsApi = {
  getAll: (params?: Record<string, string>) => api.get(`/meetings${qs(params)}`),
  getOne: (id: number) => api.get(`/meetings/${id}`),
  create: (data: MeetingCreatePayload) => api.post('/meetings', data),
  update: (id: number, data: Partial<MeetingCreatePayload>) => api.put(`/meetings/${id}`, data),
  delete: (id: number) => api.delete(`/meetings/${id}`),
  start: (id: number) => api.post(`/meetings/${id}/start`),
  complete: (id: number) => api.post(`/meetings/${id}/complete`),
  cancel: (id: number) => api.post(`/meetings/${id}/cancel`),
  updateMinutes: (id: number, minutes: string) => api.post(`/meetings/${id}/minutes`, { minutes }),
  attendees: (id: number) => api.get(`/meetings/${id}/attendees`),
  attachAttendees: (id: number, payload: { user_id?: number; user_ids?: number[]; role?: string }) =>
    api.post(`/meetings/${id}/attendees`, payload),
  updateAttendee: (id: number, userId: number, payload: { role?: string; attended?: boolean }) =>
    api.put(`/meetings/${id}/attendees/${userId}`, payload),
  detachAttendee: (id: number, userId: number) => api.delete(`/meetings/${id}/attendees/${userId}`),
  requestAgenda: (id: number) => api.post<{ message: string; agenda_requested_at: string | null }>(`/meetings/${id}/request-agenda`),
};

export interface MeetingCategoryPayload {
  name: string;
  is_active?: boolean;
  sort_order?: number;
}

export const meetingCategoriesApi = {
  getAll: (activeOnly = false) =>
    api.get<{ data: MeetingCategory[] }>(`/meeting-categories${activeOnly ? '?active_only=1' : ''}`),
  create: (data: MeetingCategoryPayload) =>
    api.post<{ message: string; category: MeetingCategory }>('/meeting-categories', data),
  update: (id: number, data: MeetingCategoryPayload) =>
    api.put<{ message: string; category: MeetingCategory }>(`/meeting-categories/${id}`, data),
  delete: (id: number) => api.delete(`/meeting-categories/${id}`),
};

export const meetingSettingsApi = {
  get: () => api.get<{ data: MeetingSettings }>('/meeting-settings'),
  update: (data: MeetingSettingsPayload) =>
    api.put<{ message: string; data: MeetingSettings }>('/meeting-settings', data),
};

export const agendaItemsApi = {
  list: (meetingId: number) => api.get<AgendaItemsResponse>(`/meetings/${meetingId}/agenda-items`),
  create: (meetingId: number, payload: { title: string; description?: string | null }) =>
    api.post<{ message: string; item: AgendaItem }>(`/meetings/${meetingId}/agenda-items`, payload),
  // Mutating endpoints nested under the parent meeting for proper route-model
  // binding and org-floor enforcement (see AgendaItemController + authz fix).
  update: (meetingId: number, itemId: number, payload: { title: string; description?: string | null }) =>
    api.put<{ message: string; item: AgendaItem }>(`/meetings/${meetingId}/agenda-items/${itemId}`, payload),
  remove: (meetingId: number, itemId: number) =>
    api.delete(`/meetings/${meetingId}/agenda-items/${itemId}`),
  approve: (meetingId: number, itemId: number) =>
    api.post<{ message: string; item: AgendaItem }>(`/meetings/${meetingId}/agenda-items/${itemId}/approve`),
  reject: (meetingId: number, itemId: number, reviewNote?: string) =>
    api.post<{ message: string; item: AgendaItem }>(
      `/meetings/${meetingId}/agenda-items/${itemId}/reject`,
      { review_note: reviewNote },
    ),
  reorder: (meetingId: number, items: number[]) =>
    api.post<{ message: string }>(`/meetings/${meetingId}/agenda-items/reorder`, { items }),
};

// Unified Recommendation API (Direction B).
// Both `ruling` and `action_item` kinds share these endpoints. The
// server returns the unwrapped recommendation payload on the happy path;
// callers cast to `Recommendation` when needed.
export const recommendationsApi = {
  getAll: (params?: Record<string, string>) =>
    api.get<{ data: Recommendation[] } | Recommendation[]>(`/recommendations${qs(params)}`),
  getOne: (id: number) => api.get<Recommendation>(`/recommendations/${id}`),
  create: (data: RecommendationCreatePayload) =>
    api.post<{ message?: string; data?: Recommendation } | Recommendation>(
      '/recommendations',
      data,
    ),
  update: (id: number, data: Partial<RecommendationCreatePayload>) =>
    api.put<{ message?: string; data?: Recommendation } | Recommendation>(
      `/recommendations/${id}`,
      data,
    ),
  delete: (id: number) => api.delete<{ message: string }>(`/recommendations/${id}`),
  // ruling-only
  approve: (id: number) => api.post<Recommendation>(`/recommendations/${id}/approve`),
  // shared (ruling + action_item)
  reject: (id: number, payload?: RejectPayload) =>
    api.post<Recommendation>(`/recommendations/${id}/reject`, payload ?? {}),
  defer: (id: number, payload?: DeferPayload) =>
    api.post<Recommendation>(`/recommendations/${id}/defer`, payload ?? {}),
  // action_item-only
  accept: (id: number) => api.post<Recommendation>(`/recommendations/${id}/accept`),
  complete: (id: number) => api.post<Recommendation>(`/recommendations/${id}/complete`),
};

export const notificationsApi = {
  getAll: (params?: NotificationListParams) =>
    api.get<{ data: Notification[]; meta: { current_page: number; last_page: number; total: number } }>(
      `/notifications${qs(params as Record<string, string | number | boolean | undefined>)}`,
    ),
  unreadCount: () => api.get<UnreadCount>('/notifications/unread-count'),
  markRead: (id: string) => api.post<{ message: string }>(`/notifications/${id}/read`),
  markAllRead: () => api.post<{ message: string }>('/notifications/read-all'),
};

export type {
  Meeting,
  Recommendation,
  AppNotification,
  Notification,
  UnreadCount,
  NotificationListParams,
} from './types';
export type { RecommendationCreatePayload, DeferPayload, RejectPayload } from './types';