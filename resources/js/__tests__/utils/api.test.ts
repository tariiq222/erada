/* global global */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

// Mock global objects before imports
const mockFetch = vi.fn();
const mockLocalStorage: Record<string, string> = {};

// Setup global mocks
Object.defineProperty(global, 'fetch', { value: mockFetch, writable: true });
Object.defineProperty(global, 'localStorage', {
  value: {
    getItem: vi.fn((key: string) => mockLocalStorage[key] || null),
    setItem: vi.fn((key: string, value: string) => { mockLocalStorage[key] = value; }),
    removeItem: vi.fn((key: string) => { delete mockLocalStorage[key]; }),
    clear: vi.fn(() => { Object.keys(mockLocalStorage).forEach(k => delete mockLocalStorage[k]); }),
  },
  writable: true,
});

// Mock document.querySelector for CSRF token
const mockQuerySelector = vi.fn();
Object.defineProperty(global, 'document', {
  value: {
    querySelector: mockQuerySelector,
    cookie: 'XSRF-TOKEN=test-csrf-token',
  },
  writable: true,
});

// Mock window.location
const mockLocation = { href: '', pathname: '/dashboard', replace: vi.fn() };
Object.defineProperty(global, 'window', {
  value: { location: mockLocation },
  writable: true,
});

describe('API Module', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mockLocation.href = '';
    Object.keys(mockLocalStorage).forEach(k => delete mockLocalStorage[k]);
    mockQuerySelector.mockReturnValue({ content: 'csrf-token-value' });
  });

  afterEach(() => {
    vi.resetModules();
  });

  describe('ApiClient', () => {
    it('should set and get token', async () => {
      const { api } = await import('@shared/api/client');

      // Cookie-based auth: setToken with a truthy value only flips the
      // in-memory authenticated flag; there is no retrievable token.
      api.setToken('test-token');
      expect(api.isUserAuthenticated()).toBe(true);
    });

    it('should remove token when setting null', async () => {
      const { api } = await import('@shared/api/client');

      api.setToken('test-token');
      // setToken(null) calls clearAuth() internally
      api.setToken(null);
      expect(api.isUserAuthenticated()).toBe(false);
    });

    it('should clear auth correctly', async () => {
      const { api } = await import('@shared/api/client');

      api.setToken('test-token');
      api.clearAuth();

      expect(api.isUserAuthenticated()).toBe(false);
    });

    it('should make GET request correctly', async () => {
      const { api } = await import('@shared/api/client');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ data: 'test' }),
      });

      const result = await api.get('/test');

      expect(mockFetch).toHaveBeenCalledWith('/api/test', expect.objectContaining({
        method: 'GET',
        credentials: 'include',
        headers: expect.objectContaining({
          'Content-Type': 'application/json',
          'Accept': 'application/json',
        }),
      }));
      expect(result).toEqual({ data: 'test' });
    });

    it('should make POST request with data', async () => {
      const { api } = await import('@shared/api/client');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ success: true }),
      });

      const result = await api.post('/test', { key: 'value' });

      expect(mockFetch).toHaveBeenCalledWith('/api/test', expect.objectContaining({
        method: 'POST',
        body: JSON.stringify({ key: 'value' }),
        headers: expect.objectContaining({
          'X-CSRF-TOKEN': 'csrf-token-value',
        }),
      }));
      expect(result).toEqual({ success: true });
    });

    it('should make PUT request with data', async () => {
      const { api } = await import('@shared/api/client');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ updated: true }),
      });

      const result = await api.put('/test/1', { name: 'updated' });

      expect(mockFetch).toHaveBeenCalledWith('/api/test/1', expect.objectContaining({
        method: 'PUT',
        body: JSON.stringify({ name: 'updated' }),
      }));
      expect(result).toEqual({ updated: true });
    });

    it('should make PATCH request with data', async () => {
      const { api } = await import('@shared/api/client');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ patched: true }),
      });

      const result = await api.patch('/test/1', { status: 'active' });

      expect(mockFetch).toHaveBeenCalledWith('/api/test/1', expect.objectContaining({
        method: 'PATCH',
      }));
      expect(result).toEqual({ patched: true });
    });

    it('should make DELETE request', async () => {
      const { api } = await import('@shared/api/client');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ deleted: true }),
      });

      const result = await api.delete('/test/1');

      expect(mockFetch).toHaveBeenCalledWith('/api/test/1', expect.objectContaining({
        method: 'DELETE',
      }));
      expect(result).toEqual({ deleted: true });
    });

    it('should include credentials in requests (cookie-based auth)', async () => {
      const { api } = await import('@shared/api/client');

      api.setToken('bearer-token');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({}),
      });

      await api.get('/protected');

      expect(mockFetch).toHaveBeenCalledWith('/api/protected', expect.objectContaining({
        credentials: 'include',
      }));
    });

    it('should handle 401 Unauthorized by clearing auth and redirecting', async () => {
      const { api } = await import('@shared/api/client');

      mockFetch.mockResolvedValueOnce({
        ok: false,
        status: 401,
      });

      await expect(api.get('/protected')).rejects.toMatchObject({
        status: 401,
        message: 'غير مصرح',
      });
      // The redirect is done via setTimeout, so we need to wait for it
      await new Promise(resolve => setTimeout(resolve, 10));
      expect(mockLocation.replace).toHaveBeenCalledWith('/login');
    });

    it('should handle 429 Too Many Requests', async () => {
      const { api } = await import('@shared/api/client');

      mockFetch.mockResolvedValueOnce({
        ok: false,
        status: 429,
        json: () => Promise.resolve({ message: 'Rate limited', retry_after: 120 }),
      });

      await expect(api.get('/test')).rejects.toMatchObject({
        status: 429,
        message: 'Rate limited',
        retry_after: 120,
      });
    });

    it('should handle generic API errors', async () => {
      const { api } = await import('@shared/api/client');

      mockFetch.mockResolvedValueOnce({
        ok: false,
        status: 400,
        json: () => Promise.resolve({
          message: 'Validation failed',
          errors: { name: ['Name is required'] },
        }),
      });

      await expect(api.post('/test', {})).rejects.toMatchObject({
        status: 400,
        message: 'Validation failed',
        errors: { name: ['Name is required'] },
      });
    });
  });

  describe('authApi', () => {
    it('should call login endpoint', async () => {
      const { authApi } = await import('@shared/api/auth');

      // First call: refreshCsrfCookie() fetches /sanctum/csrf-cookie
      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
      });
      // Second call: actual login POST
      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ user: { id: 1 }, token: 'abc' }),
      });

      const result = await authApi.login('test@test.com', 'password');

      expect(mockFetch).toHaveBeenCalledWith('/api/login', expect.objectContaining({
        method: 'POST',
        body: JSON.stringify({ email: 'test@test.com', password: 'password' }),
      }));
      expect(result).toEqual({ user: { id: 1 }, token: 'abc' });
    });

    it('should call logout endpoint', async () => {
      const { authApi } = await import('@shared/api/auth');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ message: 'Logged out' }),
      });

      await authApi.logout();

      expect(mockFetch).toHaveBeenCalledWith('/api/logout', expect.objectContaining({
        method: 'POST',
      }));
    });

    it('should call getUser endpoint', async () => {
      const { authApi } = await import('@shared/api/auth');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ user: { id: 1, name: 'Test' } }),
      });

      const result = await authApi.getUser();

      expect(mockFetch).toHaveBeenCalledWith('/api/user', expect.objectContaining({
        method: 'GET',
      }));
      expect(result.user).toEqual({ id: 1, name: 'Test' });
    });
  });

  describe('projectsApi', () => {
    it('should call getAll with params', async () => {
      const { projectsApi } = await import('@entities/project');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ data: [] }),
      });

      await projectsApi.getAll({ status: 'active', page: '1' });

      expect(mockFetch).toHaveBeenCalledWith('/api/projects?status=active&page=1', expect.any(Object));
    });

    it('should call getAll without params', async () => {
      const { projectsApi } = await import('@entities/project');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ data: [] }),
      });

      await projectsApi.getAll();

      expect(mockFetch).toHaveBeenCalledWith('/api/projects', expect.any(Object));
    });

    it('should call getOne', async () => {
      const { projectsApi } = await import('@entities/project');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ id: 1, name: 'Project' }),
      });

      await projectsApi.getOne(1);

      expect(mockFetch).toHaveBeenCalledWith('/api/projects/1', expect.any(Object));
    });

    it('should call create', async () => {
      const { projectsApi } = await import('@entities/project');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 201,
        json: () => Promise.resolve({ id: 1, name: 'New Project' }),
      });

      await projectsApi.create({ name: 'New Project' });

      expect(mockFetch).toHaveBeenCalledWith('/api/projects', expect.objectContaining({
        method: 'POST',
        body: JSON.stringify({ name: 'New Project' }),
      }));
    });

    it('should call update', async () => {
      const { projectsApi } = await import('@entities/project');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ id: 1, name: 'Updated' }),
      });

      await projectsApi.update(1, { name: 'Updated' });

      expect(mockFetch).toHaveBeenCalledWith('/api/projects/1', expect.objectContaining({
        method: 'PUT',
      }));
    });

    it('should call delete', async () => {
      const { projectsApi } = await import('@entities/project');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ message: 'Deleted' }),
      });

      await projectsApi.delete(1);

      expect(mockFetch).toHaveBeenCalledWith('/api/projects/1', expect.objectContaining({
        method: 'DELETE',
      }));
    });

    it('should call addMember', async () => {
      const { projectsApi } = await import('@entities/project');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ message: 'Member added' }),
      });

      await projectsApi.addMember(1, { user_id: 5, role: 'developer' });

      expect(mockFetch).toHaveBeenCalledWith('/api/projects/1/members', expect.objectContaining({
        method: 'POST',
        body: JSON.stringify({ user_id: 5, role: 'developer' }),
      }));
    });

    it('should call removeMember', async () => {
      const { projectsApi } = await import('@entities/project');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ message: 'Member removed' }),
      });

      await projectsApi.removeMember(1, 5);

      expect(mockFetch).toHaveBeenCalledWith('/api/projects/1/members/5', expect.objectContaining({
        method: 'DELETE',
      }));
    });
  });

  describe('tasksApi', () => {
    it('should call getAll with params', async () => {
      const { tasksApi } = await import('@entities/task');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ data: [] }),
      });

      await tasksApi.getAll({ project_id: '1', status: 'pending' });

      // Repointed to the unified API: scoped to project tasks with subtasks hidden by default.
      expect(mockFetch).toHaveBeenCalledWith('/api/unified-tasks?project_id=1&status=pending&type=project&root_only=1', expect.any(Object));
    });

    it('should call updateStatus', async () => {
      const { tasksApi } = await import('@entities/task');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ status: 'completed' }),
      });

      await tasksApi.updateStatus(1, 'completed');

      expect(mockFetch).toHaveBeenCalledWith('/api/unified-tasks/1/status', expect.objectContaining({
        method: 'PATCH',
        body: JSON.stringify({ status: 'completed' }),
      }));
    });

    it('should call getActivityLog', async () => {
      const { tasksApi } = await import('@entities/task');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ data: [] }),
      });

      await tasksApi.getActivityLog(1, 10);

      // The unified endpoint ignores `limit` (server caps at 50), so no query string is sent.
      expect(mockFetch).toHaveBeenCalledWith('/api/unified-tasks/1/activity-log', expect.any(Object));
    });
  });

  describe('usersApi', () => {
    it('should call getAll', async () => {
      const { usersApi } = await import('@entities/user');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ data: [] }),
      });

      await usersApi.getAll({ role: 'admin' });

      expect(mockFetch).toHaveBeenCalledWith('/api/users?role=admin', expect.any(Object));
    });

    it('should call getList with department filter', async () => {
      const { usersApi } = await import('@entities/user');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve([]),
      });

      await usersApi.getList([1, 2, 3]);

      expect(mockFetch).toHaveBeenCalledWith('/api/users/list?department_ids=1,2,3', expect.any(Object));
    });

  });

  describe('departmentsApi', () => {
    it('should call getAll', async () => {
      const { departmentsApi } = await import('@entities/hr');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ data: [] }),
      });

      await departmentsApi.getAll();

      expect(mockFetch).toHaveBeenCalledWith('/api/hr/departments', expect.any(Object));
    });

    it('should call getTree', async () => {
      const { departmentsApi } = await import('@entities/hr');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve([]),
      });

      await departmentsApi.getTree();

      expect(mockFetch).toHaveBeenCalledWith('/api/hr/departments/tree', expect.any(Object));
    });

    it('should call getAllowedLevels with parent', async () => {
      const { departmentsApi } = await import('@entities/hr');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve([]),
      });

      await departmentsApi.getAllowedLevels(5);

      expect(mockFetch).toHaveBeenCalledWith('/api/hr/departments/allowed-levels?parent_id=5', expect.any(Object));
    });
  });

  describe('dashboardApi', () => {
    it('should call getStats', async () => {
      const { dashboardApi } = await import('@shared/api/settings');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ projects: 10, tasks: 50 }),
      });

      await dashboardApi.getStats();

      expect(mockFetch).toHaveBeenCalledWith('/api/dashboard/stats', expect.any(Object));
    });

    it('should call getRecentProjects', async () => {
      const { dashboardApi } = await import('@shared/api/settings');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve([]),
      });

      await dashboardApi.getRecentProjects();

      expect(mockFetch).toHaveBeenCalledWith('/api/dashboard/recent-projects', expect.any(Object));
    });

    it('should call getOverdueTasks', async () => {
      const { dashboardApi } = await import('@shared/api/settings');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve([]),
      });

      await dashboardApi.getOverdueTasks();

      expect(mockFetch).toHaveBeenCalledWith('/api/dashboard/overdue-tasks', expect.any(Object));
    });
  });

  describe('milestonesApi', () => {
    it('should call getAll with projectId', async () => {
      const { milestonesApi } = await import('@entities/task');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve([]),
      });

      await milestonesApi.getAll(1);

      expect(mockFetch).toHaveBeenCalledWith('/api/milestones?project_id=1', expect.any(Object));
    });
  });

  describe('incidentsApi', () => {
    it('should call getStats', async () => {
      const { incidentsApi } = await import('@entities/incident');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ total: 100, open: 20 }),
      });

      await incidentsApi.getStats();

      expect(mockFetch).toHaveBeenCalledWith('/api/ovr/incidents/stats', expect.any(Object));
    });

    it('should call updateStatus', async () => {
      const { incidentsApi } = await import('@entities/incident');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ status: 'closed' }),
      });

      await incidentsApi.updateStatus(1, 'closed', 'Resolved');

      expect(mockFetch).toHaveBeenCalledWith('/api/ovr/incidents/1/status', expect.objectContaining({
        method: 'PATCH',
        body: JSON.stringify({ status: 'closed', notes: 'Resolved' }),
      }));
    });
  });

  describe('profileApi', () => {
    it('should call update', async () => {
      const { profileApi } = await import('@shared/api/auth');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ message: 'Updated', user: {} }),
      });

      await profileApi.update({ name: 'New Name', email: 'new@email.com' });

      expect(mockFetch).toHaveBeenCalledWith('/api/profile', expect.objectContaining({
        method: 'PUT',
      }));
    });

    it('should call changePassword', async () => {
      const { profileApi } = await import('@shared/api/auth');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ message: 'Password changed' }),
      });

      await profileApi.changePassword({
        current_password: 'old',
        password: 'new',
        password_confirmation: 'new',
      });

      expect(mockFetch).toHaveBeenCalledWith('/api/profile/password', expect.objectContaining({
        method: 'PUT',
      }));
    });
  });

  describe('systemSettingsApi', () => {
    it('should call get', async () => {
      const { systemSettingsApi } = await import('@shared/api/settings');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ settings: {} }),
      });

      await systemSettingsApi.get();

      expect(mockFetch).toHaveBeenCalledWith('/api/settings/system', expect.any(Object));
    });

    it('should call update', async () => {
      const { systemSettingsApi } = await import('@shared/api/settings');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ message: 'Updated' }),
      });

      await systemSettingsApi.update({ theme: 'dark' });

      expect(mockFetch).toHaveBeenCalledWith('/api/settings/system', expect.objectContaining({
        method: 'PUT',
        body: JSON.stringify({ theme: 'dark' }),
      }));
    });
  });

  describe('incidentCategoriesApi', () => {
    it('should call getAll', async () => {
      const { incidentCategoriesApi } = await import('@entities/incident');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ data: [] }),
      });

      await incidentCategoriesApi.getAll();

      expect(mockFetch).toHaveBeenCalledWith('/api/ovr/categories', expect.any(Object));
    });

    it('should call getList', async () => {
      const { incidentCategoriesApi } = await import('@entities/incident');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve([]),
      });

      await incidentCategoriesApi.getList();

      expect(mockFetch).toHaveBeenCalledWith('/api/ovr/categories/list', expect.any(Object));
    });

    it('should call create', async () => {
      const { incidentCategoriesApi } = await import('@entities/incident');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 201,
        json: () => Promise.resolve({ id: 1 }),
      });

      await incidentCategoriesApi.create({ name: 'Test' });

      expect(mockFetch).toHaveBeenCalledWith('/api/ovr/categories', expect.objectContaining({
        method: 'POST',
      }));
    });
  });

  describe('incidentsApi Extended', () => {
    it('should call getRecent with limit', async () => {
      const { incidentsApi } = await import('@entities/incident');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve([]),
      });

      await incidentsApi.getRecent(5);

      expect(mockFetch).toHaveBeenCalledWith('/api/ovr/incidents/recent?limit=5', expect.any(Object));
    });

    it('should call addAction', async () => {
      const { incidentsApi } = await import('@entities/incident');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ message: 'Action added' }),
      });

      await incidentsApi.addAction(1, { action: 'test' });

      expect(mockFetch).toHaveBeenCalledWith('/api/ovr/incidents/1/actions', expect.objectContaining({
        method: 'POST',
      }));
    });

    it('should call addWitness', async () => {
      const { incidentsApi } = await import('@entities/incident');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ message: 'Witness added' }),
      });

      await incidentsApi.addWitness(1, { name: 'John' });

      expect(mockFetch).toHaveBeenCalledWith('/api/ovr/incidents/1/witnesses', expect.objectContaining({
        method: 'POST',
      }));
    });
  });

  describe('unifiedTasksApi', () => {
    it('should call getAll', async () => {
      const { unifiedTasksApi } = await import('@entities/task');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ data: [] }),
      });

      await unifiedTasksApi.getAll({ type: 'personal' });

      expect(mockFetch).toHaveBeenCalledWith('/api/unified-tasks?type=personal', expect.any(Object));
    });

    it('should call create', async () => {
      const { unifiedTasksApi } = await import('@entities/task');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 201,
        json: () => Promise.resolve({ id: 1 }),
      });

      await unifiedTasksApi.create({ title: 'New Task', type: 'personal' });

      expect(mockFetch).toHaveBeenCalledWith('/api/unified-tasks', expect.objectContaining({
        method: 'POST',
      }));
    });
  });

  describe('projectsApi Extended', () => {
    it('should call addStakeholder', async () => {
      const { projectsApi } = await import('@entities/project');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ message: 'Added' }),
      });

      await projectsApi.addStakeholder(1, { user_id: 5 });

      expect(mockFetch).toHaveBeenCalledWith('/api/projects/1/stakeholders', expect.objectContaining({
        method: 'POST',
      }));
    });

    it('no longer exposes project-scoped KPI methods (KPIs moved to Performance)', async () => {
      const { projectsApi } = await import('@entities/project');

      // KPIs are a single source of truth in the Performance module; projectsApi
      // must not re-introduce a /projects/:id/kpis surface.
      expect((projectsApi as Record<string, unknown>).addKPI).toBeUndefined();
      expect((projectsApi as Record<string, unknown>).updateKPI).toBeUndefined();
      expect((projectsApi as Record<string, unknown>).removeKPI).toBeUndefined();
    });

    it('should call addRisk', async () => {
      const { projectsApi } = await import('@entities/project');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ message: 'Added' }),
      });

      await projectsApi.addRisk(1, { risk: 'Risk 1' });

      expect(mockFetch).toHaveBeenCalledWith('/api/projects/1/risks', expect.objectContaining({
        method: 'POST',
      }));
    });
  });

  describe('tasksApi Extended', () => {
    it('should call getOne', async () => {
      const { tasksApi } = await import('@entities/task');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ id: 1 }),
      });

      await tasksApi.getOne(1);

      expect(mockFetch).toHaveBeenCalledWith('/api/unified-tasks/1', expect.any(Object));
    });

    it('should call create', async () => {
      const { tasksApi } = await import('@entities/task');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 201,
        json: () => Promise.resolve({ id: 1 }),
      });

      await tasksApi.create({ title: 'New Task' });

      // Repointed to the unified API and forced to the project task type.
      expect(mockFetch).toHaveBeenCalledWith('/api/unified-tasks', expect.objectContaining({
        method: 'POST',
        body: JSON.stringify({ title: 'New Task', type: 'project' }),
      }));
    });

    it('should call update', async () => {
      const { tasksApi } = await import('@entities/task');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ id: 1 }),
      });

      await tasksApi.update(1, { title: 'Updated' });

      expect(mockFetch).toHaveBeenCalledWith('/api/unified-tasks/1', expect.objectContaining({
        method: 'PUT',
      }));
    });

    it('should call delete', async () => {
      const { tasksApi } = await import('@entities/task');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ message: 'Deleted' }),
      });

      await tasksApi.delete(1);

      expect(mockFetch).toHaveBeenCalledWith('/api/unified-tasks/1', expect.objectContaining({
        method: 'DELETE',
      }));
    });
  });
});
