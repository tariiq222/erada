/**
 * TDD contract for the assignable-manager UI state inside `useProjectForm`.
 *
 * Background: the project create form gained an "أنا مدير هذا المشروع"
 * checkbox. When checked (default) the creator becomes the manager (current
 * behavior). When unchecked, a required user picker assigns another user as
 * the project manager and the creator gets no manager role.
 *
 * The hook owns the state, the lazy fetch of assignable managers, the
 * type-driven re-fetch, the required validation, and the payload shaping.
 *
 * Follows the same mock layout as `useProjectForm-submit.test.tsx`.
 */
import { describe, it, expect, vi, beforeAll, beforeEach } from 'vitest';
import { renderHook, act, waitFor } from '@testing-library/react';
import React from 'react';

beforeAll(() => {
  window.scrollTo = vi.fn();
});

const mockNavigate = vi.fn();
const mockShowToast = vi.fn();
const mockCreate = vi.fn();
const mockUpdate = vi.fn();
const mockGetHierarchy = vi.fn().mockResolvedValue({ all: [] });
const mockGetListPrograms = vi.fn().mockResolvedValue([]);
const mockGetListUsers = vi.fn().mockResolvedValue([]);
const mockGetCreatableDepartments = vi.fn().mockResolvedValue({ all: [] });
const mockGetAssignableManagers = vi.fn().mockResolvedValue([]);

vi.mock('react-router-dom', () => ({
  useNavigate: () => mockNavigate,
  useParams: () => ({}),
}));

vi.mock('@entities/hr', () => ({
  departmentsApi: {
    getHierarchy: (...args: any[]) => mockGetHierarchy(...args),
  },
}));
vi.mock('@entities/project', () => ({
  projectsApi: {
    getOne: vi.fn().mockResolvedValue({}),
    create: (...args: any[]) => mockCreate(...args),
    update: (...args: any[]) => mockUpdate(...args),
    getCreatableDepartments: (...args: any[]) => mockGetCreatableDepartments(...args),
    getAssignableManagers: (...args: any[]) => mockGetAssignableManagers(...args),
  },
}));
vi.mock('@entities/strategy', () => ({
  programsApi: {
    getList: (...args: any[]) => mockGetListPrograms(...args),
  },
}));
vi.mock('@entities/user', () => ({
  usersApi: {
    getList: (...args: any[]) => mockGetListUsers(...args),
  },
}));

vi.mock('@shared/contexts/AuthContext', () => ({
  useAuth: () => ({
    canAccess: () => true,
    user: { id: 42, name: 'Test Creator' },
  }),
}));

vi.mock('@shared/ui/Toast', () => ({
  useToast: () => ({ showToast: mockShowToast }),
}));

import { useProjectForm } from '@pages/projects/form/useProjectForm';

describe('useProjectForm — assignable manager state', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mockGetHierarchy.mockResolvedValue({ all: [] });
    mockGetListPrograms.mockResolvedValue([]);
    mockGetListUsers.mockResolvedValue([]);
    mockGetCreatableDepartments.mockResolvedValue({ all: [] });
    mockGetAssignableManagers.mockResolvedValue([]);
  });

  it('defaults isSelfManager to true and assignedManagerId to empty', async () => {
    const { result } = renderHook(() => useProjectForm({}));
    await waitFor(() => expect(result.current.isLoading).toBe(false));

    expect(result.current.isSelfManager).toBe(true);
    expect(result.current.assignedManagerId).toBe('');
    expect(result.current.assignableManagers).toEqual([]);
    expect(typeof result.current.setIsSelfManager).toBe('function');
    expect(typeof result.current.setAssignedManagerId).toBe('function');
    // Should NOT have fetched the assignable list on mount (lazy).
    expect(mockGetAssignableManagers).not.toHaveBeenCalled();
  });

  it('does NOT send manager_user_id in the payload when the checkbox stays checked (self-manager)', async () => {
    const { result } = renderHook(() => useProjectForm({}));
    await waitFor(() => expect(result.current.isLoading).toBe(false));

    act(() => {
      result.current.handleChange('name', 'مشروع');
      result.current.handleChange('priority', 'high');
    });

    mockCreate.mockResolvedValueOnce({ id: 1 });
    await act(async () => {
      await result.current.handleSubmit({
        preventDefault: vi.fn(),
      } as unknown as React.FormEvent);
    });

    expect(mockCreate).toHaveBeenCalledTimes(1);
    const payload = mockCreate.mock.calls[0][0];
    expect(payload.manager_user_id).toBeUndefined();
    // The legacy manager_id column was dropped — it is no longer sent. When the
    // creator is the self-manager, no manager field ships at all (the backend
    // defaults the creator as manager).
    expect(payload.manager_id).toBeUndefined();
  });

  it('lazily fetches the assignable manager list when isSelfManager flips to false', async () => {
    mockGetAssignableManagers.mockResolvedValueOnce([
      { id: 7, name: 'Lina', email: 'lina@example.test', job_title: 'PM', department_id: 2 },
      { id: 8, name: 'Khalid', email: 'k@example.test', job_title: null, department_id: null },
    ]);

    const { result } = renderHook(() => useProjectForm({}));
    await waitFor(() => expect(result.current.isLoading).toBe(false));

    act(() => {
      result.current.setIsSelfManager(false);
    });

    await waitFor(() => expect(mockGetAssignableManagers).toHaveBeenCalled());
    expect(mockGetAssignableManagers).toHaveBeenCalledWith('development');
    await waitFor(() => expect(result.current.assignableManagers).toHaveLength(2));
    expect(result.current.assignableManagers[0]).toMatchObject({ id: 7, name: 'Lina' });
  });

  it('re-fetches the assignable manager list when formData.type changes while unchecked', async () => {
    mockGetAssignableManagers
      .mockResolvedValueOnce([{ id: 7, name: 'Lina', email: 'l@example.test', job_title: null, department_id: null }])
      .mockResolvedValueOnce([{ id: 9, name: 'Salem', email: 's@example.test', job_title: null, department_id: null }]);

    const { result } = renderHook(() => useProjectForm({}));
    await waitFor(() => expect(result.current.isLoading).toBe(false));

    act(() => {
      result.current.setIsSelfManager(false);
    });
    await waitFor(() => expect(mockGetAssignableManagers).toHaveBeenCalledTimes(1));
    expect(mockGetAssignableManagers).toHaveBeenLastCalledWith('development');

    act(() => {
      result.current.handleChange('type', 'improvement');
    });
    await waitFor(() => expect(mockGetAssignableManagers).toHaveBeenCalledTimes(2));
    expect(mockGetAssignableManagers).toHaveBeenLastCalledWith('improvement');
    await waitFor(() => expect(result.current.assignableManagers).toHaveLength(1));
    expect(result.current.assignableManagers[0]).toMatchObject({ id: 9, name: 'Salem' });
  });

  it('blocks submit when isSelfManager=false and no manager is selected', async () => {
    mockGetAssignableManagers.mockResolvedValueOnce([
      { id: 7, name: 'Lina', email: 'l@example.test', job_title: null, department_id: null },
    ]);

    const { result } = renderHook(() => useProjectForm({}));
    await waitFor(() => expect(result.current.isLoading).toBe(false));

    act(() => {
      result.current.setIsSelfManager(false);
      result.current.handleChange('name', 'مشروع بدون مدير');
      result.current.handleChange('priority', 'medium');
    });

    await act(async () => {
      await result.current.handleSubmit({
        preventDefault: vi.fn(),
      } as unknown as React.FormEvent);
    });

    // Should not have called the API
    expect(mockCreate).not.toHaveBeenCalled();
    // Should have a validation error keyed by the manager field
    expect(result.current.errors.manager_user_id).toBeDefined();
    expect(Array.isArray(result.current.errors.manager_user_id)).toBe(true);
    expect(result.current.errors.manager_user_id[0]).toBeTruthy();
    expect(mockShowToast).toHaveBeenCalledWith('error', 'يرجى تصحيح الأخطاء أدناه');
  });

  it('sends manager_user_id = selected user id when unchecked and a manager is picked', async () => {
    mockGetAssignableManagers.mockResolvedValueOnce([
      { id: 7, name: 'Lina', email: 'l@example.test', job_title: 'PM', department_id: 2 },
    ]);

    const { result } = renderHook(() => useProjectForm({}));
    await waitFor(() => expect(result.current.isLoading).toBe(false));

    act(() => {
      result.current.setIsSelfManager(false);
    });
    await waitFor(() => expect(result.current.assignableManagers).toHaveLength(1));

    act(() => {
      result.current.setAssignedManagerId('7');
      result.current.handleChange('name', 'مشروع مع مدير معين');
      result.current.handleChange('priority', 'high');
    });

    mockCreate.mockResolvedValueOnce({ id: 1 });
    await act(async () => {
      await result.current.handleSubmit({
        preventDefault: vi.fn(),
      } as unknown as React.FormEvent);
    });

    expect(mockCreate).toHaveBeenCalledTimes(1);
    const payload = mockCreate.mock.calls[0][0];
    expect(payload.manager_user_id).toBe(7);
    // The legacy manager_id column was dropped — only manager_user_id carries
    // the assigned manager now.
    expect(payload.manager_id).toBeUndefined();
  });
});