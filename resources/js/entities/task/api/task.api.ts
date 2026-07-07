/**
 * Tasks API - إدارة المهام
 */

import type { TaskStatus } from '@shared/types';
import { api } from '@shared/api/client';
import type { CreateTaskRequest, CreateUnifiedTaskRequest } from '@shared/api/types';

/**
 * Tasks API (project-scoped tasks).
 *
 * The legacy `/api/tasks/*` shim was removed. These methods now target the
 * unified `/api/unified-tasks/*` endpoints while preserving the public method
 * names, signatures, and the resolved value shapes the existing callers expect:
 * - `getAll`   -> paginated `{ data, links, meta }` (scoped to `type=project`)
 * - `getOne`   -> bare TaskResource object
 * - `create`   -> unwrapped bare TaskResource (server returns `{ message, task }`)
 * - `update`   -> unwrapped bare TaskResource (server returns `{ message, task }`)
 * - `delete`   -> `{ message }`
 * - `updateStatus` -> `{ message, task }` (left as-is; callers ignore the value)
 * - `getActivityLog` -> bare array of activity-log entries
 *
 * Subtask visibility: the old shim hid subtasks by default. The unified list
 * lists all rows, so we default `root_only=1` to keep the prior behavior. A
 * caller can opt subtasks back in by passing `hide_subtasks=false` or an
 * explicit `root_only` value.
 */

// Build the unified query for project-scoped task listing, translating the
// legacy param surface (`hide_subtasks`) onto the unified one (`root_only`).
const buildProjectTaskListQuery = (params?: Record<string, string>): string => {
  const search = new URLSearchParams();

  if (params) {
    for (const [key, value] of Object.entries(params)) {
      // Translated below; never forward the legacy key to the unified API.
      if (key === 'hide_subtasks' || key === 'root_only') continue;
      search.set(key, value);
    }
  }

  // Scope to project tasks (the unified list returns every task type by default).
  search.set('type', 'project');

  // Preserve the legacy default of hiding subtasks unless explicitly overridden.
  const explicitRootOnly = params?.root_only;
  const hideSubtasks = params?.hide_subtasks;
  if (explicitRootOnly !== undefined) {
    search.set('root_only', explicitRootOnly);
  } else if (hideSubtasks === 'false' || hideSubtasks === '0') {
    // Caller explicitly wants subtasks included: omit root_only.
  } else {
    search.set('root_only', '1');
  }

  const query = search.toString();
  return query ? `?${query}` : '';
};

// Unwrap the `{ message, task }` envelope returned by store/update into the
// bare task object, matching what the legacy callers consume.
const unwrapTask = <T>(response: unknown): T => {
  const envelope = response as { task?: T } | null;
  return (envelope && envelope.task !== undefined ? envelope.task : (response as T));
};

// Tasks API (المهام المرتبطة بمشاريع)
export const tasksApi = {
  getAll: (params?: Record<string, string>) =>
    api.get(`/unified-tasks${buildProjectTaskListQuery(params)}`),
  getOne: (id: number) => api.get(`/unified-tasks/${id}`),
  create: (data: CreateTaskRequest) =>
    api.post('/unified-tasks', { ...data, type: 'project' }).then(unwrapTask),
  update: (id: number, data: Partial<CreateTaskRequest>) =>
    api.put(`/unified-tasks/${id}`, data).then(unwrapTask),
  delete: (id: number) => api.delete<{ message: string }>(`/unified-tasks/${id}`),
  updateStatus: (id: number, status: TaskStatus | string, docs?: { status_comment?: string; lessons_learned?: string }) =>
    api.patch(`/unified-tasks/${id}/status`, { status, ...docs }),
  getActivityLog: (id: number, _limit?: number) =>
    // The unified endpoint ignores `limit` (server caps at 50); kept for signature parity.
    api.get(`/unified-tasks/${id}/activity-log`),
};

// Unified Tasks API (موديول المهام الموحد)
// يدعم جميع أنواع المهام: مشاريع، شخصية، إدارية، متكررة
export const unifiedTasksApi = {
  // مهامي (المكلف بها أو المالك لها)
  getMyTasks: (params?: Record<string, string>) => {
    const query = params ? '?' + new URLSearchParams(params).toString() : '';
    return api.get(`/unified-tasks/my${query}`);
  },
  // إحصائيات المهام
  getStats: () => api.get('/unified-tasks/stats'),
  // قائمة جميع المهام
  getAll: (params?: Record<string, string>) => {
    const query = params ? '?' + new URLSearchParams(params).toString() : '';
    return api.get(`/unified-tasks${query}`);
  },
  // عرض مهمة واحدة
  getOne: (id: number) => api.get(`/unified-tasks/${id}`),
  // إنشاء مهمة
  create: (data: CreateUnifiedTaskRequest) => api.post('/unified-tasks', data),
  // تحديث مهمة
  update: (id: number, data: Partial<CreateUnifiedTaskRequest>) => api.put(`/unified-tasks/${id}`, data),
  // حذف مهمة
  delete: (id: number) => api.delete<{ message: string }>(`/unified-tasks/${id}`),
  // تحديث حالة المهمة فقط
  updateStatus: (id: number, status: TaskStatus | string, docs?: { status_comment?: string; lessons_learned?: string }) =>
    api.patch(`/unified-tasks/${id}/status`, { status, ...docs }),
  // تعيين مهمة لموظف
  assign: (id: number, assignedTo: number) =>
    api.patch(`/unified-tasks/${id}/assign`, { assigned_to: assignedTo }),
  // سجل نشاطات المهمة
  getActivityLog: (id: number, limit?: number) => {
    const query = limit ? `?limit=${limit}` : '';
    return api.get(`/unified-tasks/${id}/activity-log${query}`);
  },
};

// Milestones API (المراحل)
export const milestonesApi = {
  getAll: (projectId: number) => api.get(`/milestones?project_id=${projectId}`),
  getOne: (id: number) => api.get(`/milestones/${id}`),
  create: (data: any) => api.post('/milestones', data),
  update: (id: number, data: any) => api.put(`/milestones/${id}`, data),
  delete: (id: number) => api.delete(`/milestones/${id}`),
};
