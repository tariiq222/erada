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
const mockLocation = { href: '' };
Object.defineProperty(global, 'window', {
  value: { location: mockLocation },
  writable: true,
});

// Mock FormData
class MockFormData {
  private data: Map<string, any> = new Map();
  append(key: string, value: any) {
    this.data.set(key, value);
  }
  get(key: string) {
    return this.data.get(key);
  }
  has(key: string) {
    return this.data.has(key);
  }
}
global.FormData = MockFormData as any;

// Mock File
class MockFile {
  name: string;
  type: string;
  size: number;
  constructor(bits: any[], name: string, options?: { type?: string }) {
    this.name = name;
    this.type = options?.type || '';
    this.size = 1024;
  }
}
global.File = MockFile as any;

describe('API Extended Tests', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mockLocation.href = '';
    Object.keys(mockLocalStorage).forEach(k => delete mockLocalStorage[k]);
    mockQuerySelector.mockReturnValue({ content: 'csrf-token-value' });
  });

  afterEach(() => {
    vi.resetModules();
  });

  describe('commentsApi', () => {
    it('should call getAll', async () => {
      const { commentsApi } = await import('@entities/comment');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve([]),
      });

      await commentsApi.getAll('task', 1);

      expect(mockFetch).toHaveBeenCalledWith('/api/comments?commentable_type=task&commentable_id=1', expect.any(Object));
    });

    it('should call create without attachments', async () => {
      const { commentsApi } = await import('@entities/comment');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 201,
        json: () => Promise.resolve({ id: 1, content: 'test' }),
      });

      await commentsApi.create({
        commentable_type: 'task',
        commentable_id: 1,
        content: 'Test comment',
        mentioned_users: [1, 2],
      });

      expect(mockFetch).toHaveBeenCalledWith('/api/comments', expect.objectContaining({
        method: 'POST',
        body: JSON.stringify({
          commentable_type: 'task',
          commentable_id: 1,
          content: 'Test comment',
          mentioned_users: [1, 2],
        }),
      }));
    });

    it('should call create with attachments using FormData', async () => {
      const { commentsApi } = await import('@entities/comment'); const { api } = await import('@shared/api/client');

      api.setToken('test-token');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 201,
        json: () => Promise.resolve({ id: 1 }),
      });

      const file = new MockFile(['content'], 'test.pdf', { type: 'application/pdf' });

      await commentsApi.create({
        commentable_type: 'task',
        commentable_id: 1,
        content: 'Test comment',
        attachments: [file as any],
      });

      expect(mockFetch).toHaveBeenCalledWith('/api/comments', expect.objectContaining({
        method: 'POST',
      }));
    });

    it('should handle error when creating comment with attachments', async () => {
      const { commentsApi } = await import('@entities/comment'); const { api } = await import('@shared/api/client');

      api.setToken('test-token');

      mockFetch.mockResolvedValueOnce({
        ok: false,
        status: 400,
        json: () => Promise.resolve({ message: 'Invalid', errors: { content: ['Required'] } }),
      });

      const file = new MockFile(['content'], 'test.pdf');

      await expect(commentsApi.create({
        commentable_type: 'task',
        commentable_id: 1,
        content: '',
        attachments: [file as any],
      })).rejects.toMatchObject({
        message: 'Invalid',
      });
    });

    it('should call update', async () => {
      const { commentsApi } = await import('@entities/comment');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ id: 1, content: 'updated' }),
      });

      await commentsApi.update(1, 'updated content');

      expect(mockFetch).toHaveBeenCalledWith('/api/comments/1', expect.objectContaining({
        method: 'PUT',
        body: JSON.stringify({ content: 'updated content' }),
      }));
    });

    it('should call delete', async () => {
      const { commentsApi } = await import('@entities/comment');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ message: 'Deleted' }),
      });

      await commentsApi.delete(1);

      expect(mockFetch).toHaveBeenCalledWith('/api/comments/1', expect.objectContaining({
        method: 'DELETE',
      }));
    });

    it('should call addAttachments', async () => {
      const { commentsApi } = await import('@entities/comment'); const { api } = await import('@shared/api/client');

      api.setToken('test-token');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ message: 'Added' }),
      });

      const file = new MockFile(['content'], 'test.pdf');

      await commentsApi.addAttachments(1, [file as any]);

      expect(mockFetch).toHaveBeenCalledWith('/api/comments/1/attachments', expect.objectContaining({
        method: 'POST',
      }));
    });

    it('should handle error in addAttachments', async () => {
      const { commentsApi } = await import('@entities/comment'); const { api } = await import('@shared/api/client');

      api.setToken('test-token');

      mockFetch.mockResolvedValueOnce({
        ok: false,
        status: 413,
        json: () => Promise.resolve({ message: 'File too large' }),
      });

      const file = new MockFile(['content'], 'large.pdf');

      await expect(commentsApi.addAttachments(1, [file as any])).rejects.toMatchObject({
        message: 'File too large',
      });
    });

    it('should call deleteAttachment', async () => {
      const { commentsApi } = await import('@entities/comment');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ message: 'Deleted' }),
      });

      await commentsApi.deleteAttachment(1, 5);

      expect(mockFetch).toHaveBeenCalledWith('/api/comments/1/attachments/5', expect.objectContaining({
        method: 'DELETE',
      }));
    });
  });

  describe('uploadApi', () => {
    it('should call uploadImage', async () => {
      const { api } = await import('@shared/api/client'); const { uploadApi } = await import('@shared/api/settings');

      api.setToken('test-token');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ url: '/images/test.jpg' }),
      });

      const file = new MockFile(['content'], 'test.jpg', { type: 'image/jpeg' });

      const result = await uploadApi.uploadImage(file as any, 'avatars');

      expect(mockFetch).toHaveBeenCalledWith('/api/upload/image', expect.objectContaining({
        method: 'POST',
        credentials: 'include',
      }));
      expect(result).toEqual({ url: '/images/test.jpg' });
    });

    it('should handle uploadImage error', async () => {
      const { api } = await import('@shared/api/client'); const { uploadApi } = await import('@shared/api/settings');

      api.setToken('test-token');

      mockFetch.mockResolvedValueOnce({
        ok: false,
        status: 400,
        json: () => Promise.resolve({ message: 'Invalid image format' }),
      });

      const file = new MockFile(['content'], 'test.txt');

      await expect(uploadApi.uploadImage(file as any)).rejects.toThrow('Invalid image format');
    });

    it('should call uploadLogo', async () => {
      const { api } = await import('@shared/api/client'); const { uploadApi } = await import('@shared/api/settings');

      api.setToken('test-token');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ url: '/logos/logo.png' }),
      });

      const file = new MockFile(['content'], 'logo.png', { type: 'image/png' });

      const result = await uploadApi.uploadLogo(file as any);

      expect(mockFetch).toHaveBeenCalledWith('/api/upload/logo', expect.objectContaining({
        method: 'POST',
      }));
      expect(result).toEqual({ url: '/logos/logo.png' });
    });

    it('should handle uploadLogo error', async () => {
      const { api } = await import('@shared/api/client'); const { uploadApi } = await import('@shared/api/settings');

      api.setToken('test-token');

      mockFetch.mockResolvedValueOnce({
        ok: false,
        status: 400,
        json: () => Promise.resolve({ message: 'Logo must be PNG or JPG' }),
      });

      const file = new MockFile(['content'], 'logo.gif');

      await expect(uploadApi.uploadLogo(file as any)).rejects.toThrow('Logo must be PNG or JPG');
    });
  });

  describe('projectsApi.createExpense', () => {
    it('should create expense without attachment', async () => {
      const { projectsApi } = await import('@entities/project');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 201,
        json: () => Promise.resolve({ id: 1 }),
      });

      await projectsApi.createExpense(1, {
        title: 'Test Expense',
        amount: 1000,
        category: 'supplies',
        expense_date: '2025-01-15',
      });

      expect(mockFetch).toHaveBeenCalledWith('/api/projects/1/expenses', expect.objectContaining({
        method: 'POST',
        body: JSON.stringify({
          title: 'Test Expense',
          amount: 1000,
          category: 'supplies',
          expense_date: '2025-01-15',
        }),
      }));
    });

    it('should create expense with attachment using FormData', async () => {
      const { projectsApi } = await import('@entities/project'); const { api } = await import('@shared/api/client');

      api.setToken('test-token');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 201,
        json: () => Promise.resolve({ id: 1 }),
      });

      const file = new MockFile(['content'], 'receipt.pdf', { type: 'application/pdf' });

      await projectsApi.createExpense(1, {
        title: 'Test Expense',
        amount: 1000,
        category: 'supplies',
        expense_date: '2025-01-15',
        description: 'Test description',
        task_id: 5,
        reference_number: 'REF-001',
        attachment: file as any,
      });

      expect(mockFetch).toHaveBeenCalledWith('/api/projects/1/expenses', expect.objectContaining({
        method: 'POST',
      }));
    });

    it('should handle error when creating expense with attachment', async () => {
      const { projectsApi } = await import('@entities/project'); const { api } = await import('@shared/api/client');

      api.setToken('test-token');

      mockFetch.mockResolvedValueOnce({
        ok: false,
        status: 400,
        json: () => Promise.resolve({ message: 'Invalid', errors: { amount: ['Must be positive'] } }),
      });

      const file = new MockFile(['content'], 'receipt.pdf');

      await expect(projectsApi.createExpense(1, {
        title: 'Test',
        amount: -100,
        category: 'supplies',
        expense_date: '2025-01-15',
        attachment: file as any,
      })).rejects.toMatchObject({
        message: 'Invalid',
      });
    });

    it('should call getExpenses', async () => {
      const { projectsApi } = await import('@entities/project');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ data: [] }),
      });

      await projectsApi.getExpenses(1, { category: 'supplies' });

      expect(mockFetch).toHaveBeenCalledWith('/api/projects/1/expenses?category=supplies', expect.any(Object));
    });

    it('should call getExpensesSummary', async () => {
      const { projectsApi } = await import('@entities/project');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ total: 5000 }),
      });

      await projectsApi.getExpensesSummary(1);

      expect(mockFetch).toHaveBeenCalledWith('/api/projects/1/expenses/summary', expect.any(Object));
    });

    it('should call updateExpense', async () => {
      const { projectsApi } = await import('@entities/project');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ id: 1 }),
      });

      await projectsApi.updateExpense(1, 5, { amount: 2000 });

      expect(mockFetch).toHaveBeenCalledWith('/api/projects/1/expenses/5', expect.objectContaining({
        method: 'PUT',
      }));
    });

    it('should call deleteExpense', async () => {
      const { projectsApi } = await import('@entities/project');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ message: 'Deleted' }),
      });

      await projectsApi.deleteExpense(1, 5);

      expect(mockFetch).toHaveBeenCalledWith('/api/projects/1/expenses/5', expect.objectContaining({
        method: 'DELETE',
      }));
    });
  });

  describe('projectsApi extended', () => {
    it('should call getStats', async () => {
      const { projectsApi } = await import('@entities/project');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ tasks_count: 10 }),
      });

      await projectsApi.getStats(1);

      expect(mockFetch).toHaveBeenCalledWith('/api/projects/1/stats', expect.any(Object));
    });

    it('should call getActivityLog with params', async () => {
      const { projectsApi } = await import('@entities/project');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ data: [] }),
      });

      await projectsApi.getActivityLog(1, { page: '2' });

      expect(mockFetch).toHaveBeenCalledWith('/api/projects/1/activity-log?page=2', expect.any(Object));
    });

    it('should call getActivityLog without params', async () => {
      const { projectsApi } = await import('@entities/project');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ data: [] }),
      });

      await projectsApi.getActivityLog(1);

      expect(mockFetch).toHaveBeenCalledWith('/api/projects/1/activity-log', expect.any(Object));
    });

    it('should call removeStakeholder', async () => {
      const { projectsApi } = await import('@entities/project');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ message: 'Removed' }),
      });

      await projectsApi.removeStakeholder(1, 5);

      expect(mockFetch).toHaveBeenCalledWith('/api/projects/1/stakeholders/5', expect.objectContaining({
        method: 'DELETE',
      }));
    });

    // Project-scoped KPI methods (addKPI/updateKPI/removeKPI) were removed when KPIs
    // moved to the Performance module as the single source of truth. Their absence is
    // asserted in api.test.ts ("no longer exposes project-scoped KPI methods").

    it('should call removeRisk', async () => {
      const { projectsApi } = await import('@entities/project');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ message: 'Removed' }),
      });

      await projectsApi.removeRisk(1, 5);

      expect(mockFetch).toHaveBeenCalledWith('/api/projects/1/risks/5', expect.objectContaining({
        method: 'DELETE',
      }));
    });

    it('should call updateRisk', async () => {
      const { projectsApi } = await import('@entities/project');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ id: 5 }),
      });

      await projectsApi.updateRisk(1, 5, { status: 'mitigated' });

      expect(mockFetch).toHaveBeenCalledWith('/api/projects/1/risks/5', expect.objectContaining({
        method: 'PUT',
      }));
    });
  });

  describe('unifiedTasksApi extended', () => {
    it('should call getMyTasks', async () => {
      const { unifiedTasksApi } = await import('@entities/task');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ data: [] }),
      });

      await unifiedTasksApi.getMyTasks({ status: 'in_progress' });

      expect(mockFetch).toHaveBeenCalledWith('/api/unified-tasks/my?status=in_progress', expect.any(Object));
    });

    it('should call getStats', async () => {
      const { unifiedTasksApi } = await import('@entities/task');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ total: 50 }),
      });

      await unifiedTasksApi.getStats();

      expect(mockFetch).toHaveBeenCalledWith('/api/unified-tasks/stats', expect.any(Object));
    });

    it('should call getOne', async () => {
      const { unifiedTasksApi } = await import('@entities/task');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ id: 1 }),
      });

      await unifiedTasksApi.getOne(1);

      expect(mockFetch).toHaveBeenCalledWith('/api/unified-tasks/1', expect.any(Object));
    });

    it('should call update', async () => {
      const { unifiedTasksApi } = await import('@entities/task');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ id: 1 }),
      });

      await unifiedTasksApi.update(1, { title: 'Updated' });

      expect(mockFetch).toHaveBeenCalledWith('/api/unified-tasks/1', expect.objectContaining({
        method: 'PUT',
      }));
    });

    it('should call delete', async () => {
      const { unifiedTasksApi } = await import('@entities/task');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ message: 'Deleted' }),
      });

      await unifiedTasksApi.delete(1);

      expect(mockFetch).toHaveBeenCalledWith('/api/unified-tasks/1', expect.objectContaining({
        method: 'DELETE',
      }));
    });

    it('should call updateStatus', async () => {
      const { unifiedTasksApi } = await import('@entities/task');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ status: 'completed' }),
      });

      await unifiedTasksApi.updateStatus(1, 'completed');

      expect(mockFetch).toHaveBeenCalledWith('/api/unified-tasks/1/status', expect.objectContaining({
        method: 'PATCH',
        body: JSON.stringify({ status: 'completed' }),
      }));
    });

    it('should call assign', async () => {
      const { unifiedTasksApi } = await import('@entities/task');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ assigned_to: 5 }),
      });

      await unifiedTasksApi.assign(1, 5);

      expect(mockFetch).toHaveBeenCalledWith('/api/unified-tasks/1/assign', expect.objectContaining({
        method: 'PATCH',
        body: JSON.stringify({ assigned_to: 5 }),
      }));
    });

    it('should call getActivityLog with limit', async () => {
      const { unifiedTasksApi } = await import('@entities/task');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve([]),
      });

      await unifiedTasksApi.getActivityLog(1, 20);

      expect(mockFetch).toHaveBeenCalledWith('/api/unified-tasks/1/activity-log?limit=20', expect.any(Object));
    });

    it('should call getActivityLog without limit', async () => {
      const { unifiedTasksApi } = await import('@entities/task');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve([]),
      });

      await unifiedTasksApi.getActivityLog(1);

      expect(mockFetch).toHaveBeenCalledWith('/api/unified-tasks/1/activity-log', expect.any(Object));
    });
  });

  describe('employeesApi', () => {
    it('should call getAll', async () => {
      const { employeesApi } = await import('@entities/hr');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ data: [] }),
      });

      await employeesApi.getAll({ department_id: '1' });

      expect(mockFetch).toHaveBeenCalledWith('/api/hr/employees?department_id=1', expect.any(Object));
    });

    it('should call getList with department', async () => {
      const { employeesApi } = await import('@entities/hr');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve([]),
      });

      await employeesApi.getList(5);

      expect(mockFetch).toHaveBeenCalledWith('/api/hr/employees/list?department_id=5', expect.any(Object));
    });

    it('should call getList without department', async () => {
      const { employeesApi } = await import('@entities/hr');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve([]),
      });

      await employeesApi.getList();

      expect(mockFetch).toHaveBeenCalledWith('/api/hr/employees/list', expect.any(Object));
    });

    it('should call getStats', async () => {
      const { employeesApi } = await import('@entities/hr');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ total: 100 }),
      });

      await employeesApi.getStats();

      expect(mockFetch).toHaveBeenCalledWith('/api/hr/employees/stats', expect.any(Object));
    });

    it('should call getOne', async () => {
      const { employeesApi } = await import('@entities/hr');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ id: 1 }),
      });

      await employeesApi.getOne(1);

      expect(mockFetch).toHaveBeenCalledWith('/api/hr/employees/1', expect.any(Object));
    });

    it('should call create', async () => {
      const { employeesApi } = await import('@entities/hr');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 201,
        json: () => Promise.resolve({ id: 1 }),
      });

      await employeesApi.create({ name: 'New Employee' });

      expect(mockFetch).toHaveBeenCalledWith('/api/hr/employees', expect.objectContaining({
        method: 'POST',
      }));
    });

    it('should call update', async () => {
      const { employeesApi } = await import('@entities/hr');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ id: 1 }),
      });

      await employeesApi.update(1, { name: 'Updated' });

      expect(mockFetch).toHaveBeenCalledWith('/api/hr/employees/1', expect.objectContaining({
        method: 'PUT',
      }));
    });

    it('should call delete', async () => {
      const { employeesApi } = await import('@entities/hr');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ message: 'Deleted' }),
      });

      await employeesApi.delete(1);

      expect(mockFetch).toHaveBeenCalledWith('/api/hr/employees/1', expect.objectContaining({
        method: 'DELETE',
      }));
    });
  });

  describe('milestonesApi extended', () => {
    it('should call getOne', async () => {
      const { milestonesApi } = await import('@entities/task');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ id: 1 }),
      });

      await milestonesApi.getOne(1);

      expect(mockFetch).toHaveBeenCalledWith('/api/milestones/1', expect.any(Object));
    });

    it('should call create', async () => {
      const { milestonesApi } = await import('@entities/task');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 201,
        json: () => Promise.resolve({ id: 1 }),
      });

      await milestonesApi.create({ project_id: 1, name: 'Phase 1' });

      expect(mockFetch).toHaveBeenCalledWith('/api/milestones', expect.objectContaining({
        method: 'POST',
      }));
    });

    it('should call update', async () => {
      const { milestonesApi } = await import('@entities/task');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ id: 1 }),
      });

      await milestonesApi.update(1, { name: 'Updated Phase' });

      expect(mockFetch).toHaveBeenCalledWith('/api/milestones/1', expect.objectContaining({
        method: 'PUT',
      }));
    });

    it('should call delete', async () => {
      const { milestonesApi } = await import('@entities/task');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ message: 'Deleted' }),
      });

      await milestonesApi.delete(1);

      expect(mockFetch).toHaveBeenCalledWith('/api/milestones/1', expect.objectContaining({
        method: 'DELETE',
      }));
    });
  });

  describe('usersApi extended', () => {
    it('should call getList without department', async () => {
      const { usersApi } = await import('@entities/user');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve([]),
      });

      await usersApi.getList();

      expect(mockFetch).toHaveBeenCalledWith('/api/users/list', expect.any(Object));
    });

    it('should call getOne', async () => {
      const { usersApi } = await import('@entities/user');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ id: 1 }),
      });

      await usersApi.getOne(1);

      expect(mockFetch).toHaveBeenCalledWith('/api/users/1', expect.any(Object));
    });

    it('should call create', async () => {
      const { usersApi } = await import('@entities/user');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 201,
        json: () => Promise.resolve({ id: 1 }),
      });

      await usersApi.create({ name: 'Test', email: 'test@test.com' });

      expect(mockFetch).toHaveBeenCalledWith('/api/users', expect.objectContaining({
        method: 'POST',
      }));
    });

    it('should call update', async () => {
      const { usersApi } = await import('@entities/user');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ id: 1 }),
      });

      await usersApi.update(1, { name: 'Updated' });

      expect(mockFetch).toHaveBeenCalledWith('/api/users/1', expect.objectContaining({
        method: 'PUT',
      }));
    });

    it('should call delete', async () => {
      const { usersApi } = await import('@entities/user');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ message: 'Deleted' }),
      });

      await usersApi.delete(1);

      expect(mockFetch).toHaveBeenCalledWith('/api/users/1', expect.objectContaining({
        method: 'DELETE',
      }));
    });

    it('should call getAll without params', async () => {
      const { usersApi } = await import('@entities/user');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ data: [] }),
      });

      await usersApi.getAll();

      expect(mockFetch).toHaveBeenCalledWith('/api/users', expect.any(Object));
    });
  });

  describe('departmentsApi extended', () => {
    it('should call getAll with params', async () => {
      const { departmentsApi } = await import('@entities/hr');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ data: [] }),
      });

      await departmentsApi.getAll({ level: '1' });

      expect(mockFetch).toHaveBeenCalledWith('/api/hr/departments?level=1', expect.any(Object));
    });

    it('should call getList', async () => {
      const { departmentsApi } = await import('@entities/hr');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve([]),
      });

      await departmentsApi.getList();

      expect(mockFetch).toHaveBeenCalledWith('/api/hr/departments/list', expect.any(Object));
    });

    it('should call getHierarchy', async () => {
      const { departmentsApi } = await import('@entities/hr');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve([]),
      });

      await departmentsApi.getHierarchy();

      expect(mockFetch).toHaveBeenCalledWith('/api/hr/departments/hierarchy', expect.any(Object));
    });

    it('should call getAllowedLevels without parent', async () => {
      const { departmentsApi } = await import('@entities/hr');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve([]),
      });

      await departmentsApi.getAllowedLevels();

      expect(mockFetch).toHaveBeenCalledWith('/api/hr/departments/allowed-levels', expect.any(Object));
    });

    it('should call getOne', async () => {
      const { departmentsApi } = await import('@entities/hr');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ id: 1 }),
      });

      await departmentsApi.getOne(1);

      expect(mockFetch).toHaveBeenCalledWith('/api/hr/departments/1', expect.any(Object));
    });

    it('should call create', async () => {
      const { departmentsApi } = await import('@entities/hr');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 201,
        json: () => Promise.resolve({ id: 1 }),
      });

      await departmentsApi.create({ name: 'IT' });

      expect(mockFetch).toHaveBeenCalledWith('/api/hr/departments', expect.objectContaining({
        method: 'POST',
      }));
    });

    it('should call update', async () => {
      const { departmentsApi } = await import('@entities/hr');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ id: 1 }),
      });

      await departmentsApi.update(1, { name: 'Updated' });

      expect(mockFetch).toHaveBeenCalledWith('/api/hr/departments/1', expect.objectContaining({
        method: 'PUT',
      }));
    });

    it('should call delete', async () => {
      const { departmentsApi } = await import('@entities/hr');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ message: 'Deleted' }),
      });

      await departmentsApi.delete(1);

      expect(mockFetch).toHaveBeenCalledWith('/api/hr/departments/1', expect.objectContaining({
        method: 'DELETE',
      }));
    });
  });

  describe('incidentCategoriesApi extended', () => {
    it('should call getAll with params', async () => {
      const { incidentCategoriesApi } = await import('@entities/incident');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ data: [] }),
      });

      await incidentCategoriesApi.getAll({ active: 'true' });

      expect(mockFetch).toHaveBeenCalledWith('/api/ovr/categories?active=true', expect.any(Object));
    });

    it('should call getStats', async () => {
      const { incidentCategoriesApi } = await import('@entities/incident');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ total: 10 }),
      });

      await incidentCategoriesApi.getStats();

      expect(mockFetch).toHaveBeenCalledWith('/api/ovr/categories/stats', expect.any(Object));
    });

    it('should call getOne', async () => {
      const { incidentCategoriesApi } = await import('@entities/incident');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ id: 1 }),
      });

      await incidentCategoriesApi.getOne(1);

      expect(mockFetch).toHaveBeenCalledWith('/api/ovr/categories/1', expect.any(Object));
    });

    it('should call update', async () => {
      const { incidentCategoriesApi } = await import('@entities/incident');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ id: 1 }),
      });

      await incidentCategoriesApi.update(1, { name: 'Updated' });

      expect(mockFetch).toHaveBeenCalledWith('/api/ovr/categories/1', expect.objectContaining({
        method: 'PUT',
      }));
    });

    it('should call delete', async () => {
      const { incidentCategoriesApi } = await import('@entities/incident');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ message: 'Deleted' }),
      });

      await incidentCategoriesApi.delete(1);

      expect(mockFetch).toHaveBeenCalledWith('/api/ovr/categories/1', expect.objectContaining({
        method: 'DELETE',
      }));
    });
  });

  describe('incidentsApi extended', () => {
    it('should call getAll with params', async () => {
      const { incidentsApi } = await import('@entities/incident');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ data: [] }),
      });

      await incidentsApi.getAll({ status: 'open' });

      expect(mockFetch).toHaveBeenCalledWith('/api/ovr/incidents?status=open', expect.any(Object));
    });

    it('should call getRecent without limit', async () => {
      const { incidentsApi } = await import('@entities/incident');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve([]),
      });

      await incidentsApi.getRecent();

      expect(mockFetch).toHaveBeenCalledWith('/api/ovr/incidents/recent', expect.any(Object));
    });

    it('should call getStats with params', async () => {
      const { incidentsApi } = await import('@entities/incident');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ total: 100 }),
      });

      await incidentsApi.getStats({ year: '2025' });

      expect(mockFetch).toHaveBeenCalledWith('/api/ovr/incidents/stats?year=2025', expect.any(Object));
    });

    it('should call getOne', async () => {
      const { incidentsApi } = await import('@entities/incident');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ id: 1 }),
      });

      await incidentsApi.getOne(1);

      expect(mockFetch).toHaveBeenCalledWith('/api/ovr/incidents/1', expect.any(Object));
    });

    it('should call create', async () => {
      const { incidentsApi } = await import('@entities/incident');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 201,
        json: () => Promise.resolve({ id: 1 }),
      });

      await incidentsApi.create({ title: 'New Incident' });

      expect(mockFetch).toHaveBeenCalledWith('/api/ovr/incidents', expect.objectContaining({
        method: 'POST',
      }));
    });

    it('should call update', async () => {
      const { incidentsApi } = await import('@entities/incident');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ id: 1 }),
      });

      await incidentsApi.update(1, { title: 'Updated' });

      expect(mockFetch).toHaveBeenCalledWith('/api/ovr/incidents/1', expect.objectContaining({
        method: 'PUT',
      }));
    });

    it('should call delete', async () => {
      const { incidentsApi } = await import('@entities/incident');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ message: 'Deleted' }),
      });

      await incidentsApi.delete(1);

      expect(mockFetch).toHaveBeenCalledWith('/api/ovr/incidents/1', expect.objectContaining({
        method: 'DELETE',
      }));
    });
  });

  describe('dashboardApi extended', () => {
    it('should call getMyUpcomingTasks', async () => {
      const { dashboardApi } = await import('@shared/api/settings');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve([]),
      });

      await dashboardApi.getMyUpcomingTasks();

      expect(mockFetch).toHaveBeenCalledWith('/api/dashboard/my-upcoming-tasks', expect.any(Object));
    });

    it('should call getProjectsByStatus', async () => {
      const { dashboardApi } = await import('@shared/api/settings');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({}),
      });

      await dashboardApi.getProjectsByStatus();

      expect(mockFetch).toHaveBeenCalledWith('/api/dashboard/projects-by-status', expect.any(Object));
    });
  });

  describe('tasksApi extended', () => {
    it('should call getAll without params', async () => {
      const { tasksApi } = await import('@entities/task');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ data: [] }),
      });

      await tasksApi.getAll();

      // Repointed to the unified API: always scoped to project tasks with subtasks hidden by default.
      expect(mockFetch).toHaveBeenCalledWith('/api/unified-tasks?type=project&root_only=1', expect.any(Object));
    });

    it('should call getActivityLog without limit', async () => {
      const { tasksApi } = await import('@entities/task');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve([]),
      });

      await tasksApi.getActivityLog(1);

      expect(mockFetch).toHaveBeenCalledWith('/api/unified-tasks/1/activity-log', expect.any(Object));
    });
  });

  describe('unifiedTasksApi', () => {
    it('should call getAll without params', async () => {
      const { unifiedTasksApi } = await import('@entities/task');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ data: [] }),
      });

      await unifiedTasksApi.getAll();

      expect(mockFetch).toHaveBeenCalledWith('/api/unified-tasks', expect.any(Object));
    });

    it('should call getMyTasks without params', async () => {
      const { unifiedTasksApi } = await import('@entities/task');

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ data: [] }),
      });

      await unifiedTasksApi.getMyTasks();

      expect(mockFetch).toHaveBeenCalledWith('/api/unified-tasks/my', expect.any(Object));
    });
  });

  describe('429 Rate Limit with default retry', () => {
    it('should use default retry_after value', async () => {
      const { api } = await import('@shared/api/client');

      mockFetch.mockResolvedValueOnce({
        ok: false,
        status: 429,
        json: () => Promise.resolve({ message: 'Rate limited' }),
      });

      await expect(api.get('/test')).rejects.toMatchObject({
        status: 429,
        retry_after: 60,
      });
    });
  });

  describe('Generic error with default message', () => {
    it('should use default error message', async () => {
      const { api } = await import('@shared/api/client');

      mockFetch.mockResolvedValueOnce({
        ok: false,
        status: 500,
        json: () => Promise.resolve({}),
      });

      await expect(api.get('/test')).rejects.toMatchObject({
        status: 500,
        message: 'حدث خطأ',
      });
    });
  });
});
