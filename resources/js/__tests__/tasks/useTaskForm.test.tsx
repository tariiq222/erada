import { describe, it, expect, vi, beforeEach } from 'vitest';
import { renderHook, act, waitFor } from '@testing-library/react';

// Mock react-router-dom
const mockNavigate = vi.fn();
vi.mock('react-router-dom', () => ({
  useNavigate: () => mockNavigate,
}));

// Mock Toast
const mockShowToast = vi.fn();
vi.mock('@shared/ui/Toast', () => ({
  useToast: () => ({
    showToast: mockShowToast,
  }),
}));

// Mock APIs
vi.mock('@entities/hr', () => ({
  departmentsApi: {
    getList: vi.fn().mockResolvedValue([
      { id: 1, name: 'قسم التقنية' },
      { id: 2, name: 'قسم الموارد البشرية' },
    ]),
  },
}));
vi.mock('@entities/project', () => ({
  projectsApi: {
    getAll: vi.fn().mockResolvedValue({
      data: [
        {
          id: 1,
          name: 'مشروع 1',
          start_date: '2025-01-01',
          end_date: '2025-12-31',
          milestones: [
            { id: 1, name: 'المرحلة 1', start_date: '2025-01-01', due_date: '2025-03-31' },
          ],
        },
      ],
    }),
    getOne: vi.fn().mockResolvedValue({
      id: 1,
      name: 'مشروع 1',
      start_date: '2025-01-01',
      end_date: '2025-12-31',
      milestones: [
        { id: 1, name: 'المرحلة 1', start_date: '2025-01-01', due_date: '2025-03-31' },
      ],
    }),
  },
}));
vi.mock('@entities/task', () => ({
  tasksApi: {
    getOne: vi.fn().mockResolvedValue({
      data: {
        id: 1,
        title: 'مهمة اختبارية',
        description: 'وصف المهمة',
        status: 'in_progress',
        priority: 'high',
        project_id: 1,
        milestone_id: null,
        parent_id: null,
        assigned_to: 1,
        start_date: '2025-01-01',
        due_date: '2025-01-31',
        type: 'project',
      },
    }),
    getAll: vi.fn().mockResolvedValue({ data: [] }),
    create: vi.fn().mockResolvedValue({ id: 1 }),
    update: vi.fn().mockResolvedValue({}),
  },
  milestonesApi: {
    create: vi.fn().mockResolvedValue({
      milestone: { id: 2, name: 'مرحلة جديدة' },
    }),
  },
  unifiedTasksApi: {
    getOne: vi.fn().mockResolvedValue({ data: {} }),
    create: vi.fn().mockResolvedValue({ id: 1 }),
    update: vi.fn().mockResolvedValue({}),
  },
}));
vi.mock('@entities/user', () => ({
  usersApi: {
    getList: vi.fn().mockResolvedValue([
      { id: 1, name: 'أحمد', email: 'ahmed@example.com' },
      { id: 2, name: 'محمد', email: 'mohamed@example.com' },
    ]),
  },
}));

import { useTaskForm } from '@pages/tasks/form/useTaskForm';

describe('useTaskForm Hook', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('initializes with default values', async () => {
    const { result } = renderHook(() =>
      useTaskForm({ id: undefined, preselectedProjectId: null, preselectedMilestoneId: null })
    );

    await waitFor(() => {
      expect(result.current.formData.title).toBe('');
      expect(result.current.formData.status).toBe('todo');
      expect(result.current.formData.priority).toBe('medium');
      expect(result.current.formData.type).toBe('project');
    });
  });

  it('sets isEditMode to false when no id provided', async () => {
    const { result } = renderHook(() =>
      useTaskForm({ id: undefined, preselectedProjectId: null, preselectedMilestoneId: null })
    );

    await waitFor(() => {
      expect(result.current.isEditMode).toBe(false);
    });
  });

  it('sets isEditMode to true when id provided', async () => {
    const { result } = renderHook(() =>
      useTaskForm({ id: '1', preselectedProjectId: null, preselectedMilestoneId: null })
    );

    await waitFor(() => {
      expect(result.current.isEditMode).toBe(true);
    });
  });

  it('sets isProjectContext when preselectedProjectId provided', async () => {
    const { result } = renderHook(() =>
      useTaskForm({ id: undefined, preselectedProjectId: '1', preselectedMilestoneId: null })
    );

    await waitFor(() => {
      expect(result.current.isProjectContext).toBe(true);
    });
  });

  it('preselects project and milestone when provided', async () => {
    const { result } = renderHook(() =>
      useTaskForm({ id: undefined, preselectedProjectId: '1', preselectedMilestoneId: '2' })
    );

    await waitFor(() => {
      expect(result.current.formData.project_id).toBe('1');
      expect(result.current.formData.milestone_id).toBe('2');
    });
  });

  it('loads users from API', async () => {
    const { result } = renderHook(() =>
      useTaskForm({ id: undefined, preselectedProjectId: null, preselectedMilestoneId: null })
    );

    await waitFor(() => {
      expect(result.current.users).toHaveLength(2);
      expect(result.current.users[0].name).toBe('أحمد');
    });
  });

  it('loads departments from API', async () => {
    const { result } = renderHook(() =>
      useTaskForm({ id: undefined, preselectedProjectId: null, preselectedMilestoneId: null })
    );

    await waitFor(() => {
      expect(result.current.departments).toHaveLength(2);
      expect(result.current.departments[0].name).toBe('قسم التقنية');
    });
  });

  it('loads projects from API', async () => {
    const { result } = renderHook(() =>
      useTaskForm({ id: undefined, preselectedProjectId: null, preselectedMilestoneId: null })
    );

    await waitFor(() => {
      expect(result.current.projects).toHaveLength(1);
      expect(result.current.projects[0].name).toBe('مشروع 1');
    });
  });
});

describe('useTaskForm handleChange', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('updates form data when handleChange is called', async () => {
    const { result } = renderHook(() =>
      useTaskForm({ id: undefined, preselectedProjectId: null, preselectedMilestoneId: null })
    );

    await waitFor(() => {
      expect(result.current.formData.title).toBe('');
    });

    act(() => {
      result.current.handleChange('title', 'عنوان جديد');
    });

    expect(result.current.formData.title).toBe('عنوان جديد');
  });

  it('clears related fields when project_id changes', async () => {
    const { result } = renderHook(() =>
      useTaskForm({ id: undefined, preselectedProjectId: '1', preselectedMilestoneId: '1' })
    );

    await waitFor(() => {
      expect(result.current.formData.project_id).toBe('1');
      expect(result.current.formData.milestone_id).toBe('1');
    });

    await act(async () => {
      result.current.handleChange('project_id', '2');
      await Promise.resolve();
    });

    expect(result.current.formData.project_id).toBe('2');
    expect(result.current.formData.milestone_id).toBe('');
    expect(result.current.formData.parent_id).toBe('');
  });

  it('clears errors when field changes', async () => {
    const { result } = renderHook(() =>
      useTaskForm({ id: undefined, preselectedProjectId: null, preselectedMilestoneId: null })
    );

    await waitFor(() => {
      expect(result.current.errors).toEqual({});
    });

    // Simulate setting an error
    act(() => {
      result.current.handleChange('title', 'عنوان');
    });

    // Errors should be cleared for that field
    expect(result.current.errors.title).toBeUndefined();
  });

  it('updates type and clears related fields', async () => {
    const { result } = renderHook(() =>
      useTaskForm({ id: undefined, preselectedProjectId: '1', preselectedMilestoneId: null })
    );

    await waitFor(() => {
      expect(result.current.formData.type).toBe('project');
    });

    act(() => {
      result.current.handleChange('type', 'personal');
    });

    expect(result.current.formData.type).toBe('personal');
    expect(result.current.formData.project_id).toBe('');
  });
});

describe('useTaskForm Milestone Modal', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('opens milestone modal with empty data', async () => {
    const { result } = renderHook(() =>
      useTaskForm({ id: undefined, preselectedProjectId: '1', preselectedMilestoneId: null })
    );

    await waitFor(() => {
      expect(result.current.showMilestoneModal).toBe(false);
    });

    act(() => {
      result.current.openMilestoneModal();
    });

    expect(result.current.showMilestoneModal).toBe(true);
    expect(result.current.milestoneFormData.name).toBe('');
    expect(result.current.milestoneFormData.description).toBe('');
  });

  it('updates milestone form data', async () => {
    const { result } = renderHook(() =>
      useTaskForm({ id: undefined, preselectedProjectId: '1', preselectedMilestoneId: null })
    );

    await act(async () => {
      result.current.openMilestoneModal();
      await Promise.resolve();
    });

    await act(async () => {
      result.current.handleMilestoneChange('name', 'مرحلة اختبارية');
      await Promise.resolve();
    });

    expect(result.current.milestoneFormData.name).toBe('مرحلة اختبارية');
  });

  it('closes milestone modal when setShowMilestoneModal is called', async () => {
    const { result } = renderHook(() =>
      useTaskForm({ id: undefined, preselectedProjectId: '1', preselectedMilestoneId: null })
    );

    await act(async () => {
      result.current.openMilestoneModal();
      await Promise.resolve();
    });

    expect(result.current.showMilestoneModal).toBe(true);

    await act(async () => {
      result.current.setShowMilestoneModal(false);
      await Promise.resolve();
    });

    expect(result.current.showMilestoneModal).toBe(false);
  });
});

describe('useTaskForm User Modal', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('opens user modal with empty search', async () => {
    const { result } = renderHook(() =>
      useTaskForm({ id: undefined, preselectedProjectId: null, preselectedMilestoneId: null })
    );

    await waitFor(() => {
      expect(result.current.showUserModal).toBe(false);
    });

    act(() => {
      result.current.openUserModal();
    });

    expect(result.current.showUserModal).toBe(true);
    expect(result.current.userSearchQuery).toBe('');
  });

  it('updates user search query', async () => {
    const { result } = renderHook(() =>
      useTaskForm({ id: undefined, preselectedProjectId: null, preselectedMilestoneId: null })
    );

    await act(async () => {
      result.current.openUserModal();
      await Promise.resolve();
    });

    await act(async () => {
      result.current.setUserSearchQuery('أحمد');
      await Promise.resolve();
    });

    expect(result.current.userSearchQuery).toBe('أحمد');
  });

  it('sets assigned_to when user is selected', async () => {
    const { result } = renderHook(() =>
      useTaskForm({ id: undefined, preselectedProjectId: null, preselectedMilestoneId: null })
    );

    await waitFor(() => {
      expect(result.current.users).toHaveLength(2);
    });

    act(() => {
      result.current.openUserModal();
    });

    act(() => {
      result.current.handleSelectUser(1);
    });

    expect(result.current.formData.assigned_to).toBe('1');
    expect(result.current.showUserModal).toBe(false);
  });
});

describe('useTaskForm selectedUser', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('returns selected user when assigned_to is set', async () => {
    const { result } = renderHook(() =>
      useTaskForm({ id: undefined, preselectedProjectId: null, preselectedMilestoneId: null })
    );

    await waitFor(() => {
      expect(result.current.users).toHaveLength(2);
    });

    act(() => {
      result.current.handleChange('assigned_to', '1');
    });

    expect(result.current.selectedUser?.name).toBe('أحمد');
  });

  it('returns undefined when assigned_to is not set', async () => {
    const { result } = renderHook(() =>
      useTaskForm({ id: undefined, preselectedProjectId: null, preselectedMilestoneId: null })
    );

    await waitFor(() => {
      expect(result.current.users).toHaveLength(2);
    });

    expect(result.current.selectedUser).toBeUndefined();
  });
});

describe('useTaskForm Form States', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('isSaving starts as false', async () => {
    const { result } = renderHook(() =>
      useTaskForm({ id: undefined, preselectedProjectId: null, preselectedMilestoneId: null })
    );

    await waitFor(() => {
      expect(result.current.isSaving).toBe(false);
    });
  });

  it('isLoading is true when editing', async () => {
    const { result } = renderHook(() =>
      useTaskForm({ id: '1', preselectedProjectId: null, preselectedMilestoneId: null })
    );

    // Initially loading
    expect(result.current.isLoading).toBe(true);

    // After data loads
    await waitFor(() => {
      expect(result.current.isLoading).toBe(false);
    });
  });

  it('errors object starts empty', async () => {
    const { result } = renderHook(() =>
      useTaskForm({ id: undefined, preselectedProjectId: null, preselectedMilestoneId: null })
    );

    await waitFor(() => {
      expect(result.current.errors).toEqual({});
    });
  });
});

describe('useTaskForm Priority and Status', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('can change priority', async () => {
    const { result } = renderHook(() =>
      useTaskForm({ id: undefined, preselectedProjectId: null, preselectedMilestoneId: null })
    );

    await waitFor(() => {
      expect(result.current.formData.priority).toBe('medium');
    });

    act(() => {
      result.current.handleChange('priority', 'high');
    });

    expect(result.current.formData.priority).toBe('high');
  });

  it('can change status', async () => {
    const { result } = renderHook(() =>
      useTaskForm({ id: undefined, preselectedProjectId: null, preselectedMilestoneId: null })
    );

    await waitFor(() => {
      expect(result.current.formData.status).toBe('todo');
    });

    act(() => {
      result.current.handleChange('status', 'in_progress');
    });

    expect(result.current.formData.status).toBe('in_progress');
  });
});

describe('useTaskForm Dates', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('can set start_date', async () => {
    const { result } = renderHook(() =>
      useTaskForm({ id: undefined, preselectedProjectId: null, preselectedMilestoneId: null })
    );

    // Wait for initial loading to complete
    await waitFor(() => {
      expect(result.current.isLoading).toBe(false);
    }, { timeout: 3000 });

    act(() => {
      result.current.handleChange('start_date', '2025-01-15');
    });

    expect(result.current.formData.start_date).toBe('2025-01-15');
  });

  it('can set due_date', async () => {
    const { result } = renderHook(() =>
      useTaskForm({ id: undefined, preselectedProjectId: null, preselectedMilestoneId: null })
    );

    await waitFor(() => {
      expect(result.current.formData.due_date).toBe('');
    });

    act(() => {
      result.current.handleChange('due_date', '2025-01-31');
    });

    expect(result.current.formData.due_date).toBe('2025-01-31');
  });
});

describe('useTaskForm handleSubmit', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('calls tasksApi.create for new project task', async () => {
    const { projectsApi } = await import('@entities/project'); const { tasksApi } = await import('@entities/task');
    const { result } = renderHook(() =>
      useTaskForm({ id: undefined, preselectedProjectId: '1', preselectedMilestoneId: null })
    );

    // Wait for loading to complete and projects to be loaded
    await waitFor(() => {
      expect(result.current.isLoading).toBe(false);
      expect(projectsApi.getAll).toHaveBeenCalled();
    }, { timeout: 3000 });

    // Wait for project details to be fetched (since preselectedProjectId = '1')
    await waitFor(() => {
      expect(projectsApi.getOne).toHaveBeenCalledWith(1);
    }, { timeout: 3000 });

    // Set required fields - use date within project range (2025-01-01 to 2025-12-31)
    act(() => {
      result.current.handleChange('title', 'مهمة جديدة');
      result.current.handleChange('start_date', '2025-06-15');
    });

    await act(async () => {
      await result.current.handleSubmit({ preventDefault: vi.fn() } as any);
    });

    await waitFor(() => {
      expect(tasksApi.create).toHaveBeenCalled();
    }, { timeout: 3000 });
  });

  it('calls tasksApi.update for existing project task', async () => {
    const { tasksApi } = await import('@entities/task');
    const { result } = renderHook(() =>
      useTaskForm({ id: '1', preselectedProjectId: '1', preselectedMilestoneId: null })
    );

    await waitFor(() => {
      expect(result.current.formData.title).toBe('مهمة اختبارية');
    });

    await act(async () => {
      await result.current.handleSubmit({ preventDefault: vi.fn() } as any);
    });

    await waitFor(() => {
      expect(tasksApi.update).toHaveBeenCalledWith(1, expect.any(Object));
    });
  });

  it('shows success toast after creation', async () => {
    const { result } = renderHook(() =>
      useTaskForm({ id: undefined, preselectedProjectId: '1', preselectedMilestoneId: null })
    );

    act(() => {
      result.current.handleChange('title', 'مهمة جديدة');
    });

    await act(async () => {
      await result.current.handleSubmit({ preventDefault: vi.fn() } as any);
    });

    await waitFor(() => {
      expect(mockShowToast).toHaveBeenCalledWith('success', 'تم إنشاء المهمة بنجاح');
    });
  });

  it('navigates to project page after submit', async () => {
    const { result } = renderHook(() =>
      useTaskForm({ id: undefined, preselectedProjectId: '1', preselectedMilestoneId: null })
    );

    act(() => {
      result.current.handleChange('title', 'مهمة');
    });

    await act(async () => {
      await result.current.handleSubmit({ preventDefault: vi.fn() } as any);
    });

    await waitFor(() => {
      expect(mockNavigate).toHaveBeenCalledWith('/projects/1');
    });
  });

  it('navigates to tasks page when no project context', async () => {
    const { result } = renderHook(() =>
      useTaskForm({ id: undefined, preselectedProjectId: null, preselectedMilestoneId: null })
    );

    act(() => {
      result.current.handleChange('title', 'مهمة');
      result.current.handleChange('type', 'personal');
    });

    await act(async () => {
      await result.current.handleSubmit({ preventDefault: vi.fn() } as any);
    });

    await waitFor(() => {
      expect(mockNavigate).toHaveBeenCalledWith('/tasks');
    });
  });
});

describe('useTaskForm handleSaveMilestone', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('shows error when no project selected', async () => {
    const { result } = renderHook(() =>
      useTaskForm({ id: undefined, preselectedProjectId: null, preselectedMilestoneId: null })
    );

    act(() => {
      result.current.openMilestoneModal();
    });

    await act(async () => {
      await result.current.handleSaveMilestone();
    });

    expect(mockShowToast).toHaveBeenCalledWith('error', 'يجب اختيار المشروع أولاً');
  });

  it('sets error when duration not provided', async () => {
    const { result } = renderHook(() =>
      useTaskForm({ id: undefined, preselectedProjectId: '1', preselectedMilestoneId: null })
    );

    act(() => {
      result.current.handleChange('project_id', '1');
      result.current.openMilestoneModal();
      result.current.handleMilestoneChange('name', 'مرحلة');
    });

    await act(async () => {
      await result.current.handleSaveMilestone();
    });

    expect(result.current.milestoneErrors.duration_value).toBeDefined();
  });

  it('calls milestonesApi.create with correct data', async () => {
    const { milestonesApi } = await import('@entities/task');
    const { result } = renderHook(() =>
      useTaskForm({ id: undefined, preselectedProjectId: '1', preselectedMilestoneId: null })
    );

    act(() => {
      result.current.handleChange('project_id', '1');
      result.current.openMilestoneModal();
      result.current.handleMilestoneChange('name', 'مرحلة جديدة');
      result.current.handleMilestoneChange('duration_value', '7');
      result.current.handleMilestoneChange('duration_unit', 'day');
    });

    await act(async () => {
      await result.current.handleSaveMilestone();
    });

    await waitFor(() => {
      expect(milestonesApi.create).toHaveBeenCalledWith({
        project_id: 1,
        name: 'مرحلة جديدة',
        description: null,
        duration_value: 7,
        duration_unit: 'day',
      });
    });
  });

  it('shows success toast after milestone creation', async () => {
    const { result } = renderHook(() =>
      useTaskForm({ id: undefined, preselectedProjectId: '1', preselectedMilestoneId: null })
    );

    act(() => {
      result.current.handleChange('project_id', '1');
      result.current.openMilestoneModal();
      result.current.handleMilestoneChange('name', 'مرحلة');
      result.current.handleMilestoneChange('duration_value', '5');
    });

    await act(async () => {
      await result.current.handleSaveMilestone();
    });

    await waitFor(() => {
      expect(mockShowToast).toHaveBeenCalledWith('success', 'تم إنشاء المرحلة بنجاح');
    });
  });

  it('closes modal after successful creation', async () => {
    const { result } = renderHook(() =>
      useTaskForm({ id: undefined, preselectedProjectId: '1', preselectedMilestoneId: null })
    );

    act(() => {
      result.current.handleChange('project_id', '1');
      result.current.openMilestoneModal();
      result.current.handleMilestoneChange('name', 'مرحلة');
      result.current.handleMilestoneChange('duration_value', '5');
    });

    await act(async () => {
      await result.current.handleSaveMilestone();
    });

    await waitFor(() => {
      expect(result.current.showMilestoneModal).toBe(false);
    });
  });
});

describe('useTaskForm dateConstraints', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('returns null when no project selected', async () => {
    const { result } = renderHook(() =>
      useTaskForm({ id: undefined, preselectedProjectId: null, preselectedMilestoneId: null })
    );

    await waitFor(() => {
      expect(result.current.dateConstraints).toBeNull();
    });
  });

  it('returns project constraints when project selected', async () => {
    const { result } = renderHook(() =>
      useTaskForm({ id: undefined, preselectedProjectId: '1', preselectedMilestoneId: null })
    );

    await waitFor(() => {
      expect(result.current.dateConstraints).not.toBeNull();
    });

    await waitFor(() => {
      expect(result.current.dateConstraints?.constraintType).toBe('project');
    });
  });
});

describe('useTaskForm Type Changes', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('clears project fields when changing to personal type', async () => {
    const { result } = renderHook(() =>
      useTaskForm({ id: undefined, preselectedProjectId: null, preselectedMilestoneId: null })
    );

    await act(async () => {
      result.current.handleChange('project_id', '1');
      result.current.handleChange('milestone_id', '1');
      await Promise.resolve();
    });

    await act(async () => {
      result.current.handleChange('type', 'personal');
      await Promise.resolve();
    });

    expect(result.current.formData.project_id).toBe('');
    expect(result.current.formData.milestone_id).toBe('');
  });

  it('clears department field when changing to project type', async () => {
    const { result } = renderHook(() =>
      useTaskForm({ id: undefined, preselectedProjectId: null, preselectedMilestoneId: null })
    );

    await act(async () => {
      result.current.handleChange('type', 'department');
      result.current.handleChange('department_id', '1');
      await Promise.resolve();
    });

    await act(async () => {
      result.current.handleChange('type', 'project');
      await Promise.resolve();
    });

    expect(result.current.formData.department_id).toBe('');
  });

  it('clears recurrence when changing from recurring type', async () => {
    const { result } = renderHook(() =>
      useTaskForm({ id: undefined, preselectedProjectId: null, preselectedMilestoneId: null })
    );

    await act(async () => {
      result.current.handleChange('type', 'recurring');
      result.current.handleChange('recurrence_rule', 'FREQ=DAILY');
      await Promise.resolve();
    });

    await act(async () => {
      result.current.handleChange('type', 'project');
      await Promise.resolve();
    });

    expect(result.current.formData.recurrence_rule).toBe('');
  });
});
