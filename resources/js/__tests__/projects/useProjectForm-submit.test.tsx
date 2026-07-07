import { describe, it, expect, vi, beforeAll, beforeEach } from 'vitest';
import { renderHook, act, waitFor } from '@testing-library/react';
import React from 'react';

// Mock scrollTo before importing hook
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
    canAccess: () => true, user: { id: 1, name: 'Test User' } }),
}));

vi.mock('@shared/ui/Toast', () => ({
  useToast: () => ({ showToast: mockShowToast }),
}));

// Import after mocks
import { useProjectForm } from '@pages/projects/form/useProjectForm';

describe('useProjectForm submit sanitization', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('converts empty optional strings to null in submit payload', async () => {
    const { result } = renderHook(() => useProjectForm({}));

    // Wait for initial data loading
    await waitFor(() => expect(result.current.isLoading).toBe(false));

    // Fill required fields
    act(() => {
      result.current.handleChange('name', 'مشروع تجريبي');
      result.current.handleChange('priority', 'high');
    });

    mockCreate.mockResolvedValueOnce({ id: 1 });

    // Submit
    await act(async () => {
      const fakeEvent = { preventDefault: vi.fn() } as unknown as React.FormEvent;
      await result.current.handleSubmit(fakeEvent);
    });

    expect(mockCreate).toHaveBeenCalledTimes(1);
    const payload = mockCreate.mock.calls[0][0];

    // Empty optional fields should be null, not empty string
    expect(payload.description).toBeNull();
    expect(payload.start_date).toBeNull();
    expect(payload.end_date).toBeNull();
    expect(payload.budget).toBeNull();
    expect(payload.human_resources).toBeNull();
    expect(payload.technical_resources).toBeNull();
    expect(payload.financial_resources).toBeNull();
    expect(payload.program_id).toBeNull();
    // The legacy sponsor_id column was dropped — it is no longer in the payload.
    expect(payload.sponsor_id).toBeUndefined();
  });

  it('includes task name and title for backend compatibility', async () => {
    const { result } = renderHook(() => useProjectForm({}));

    await waitFor(() => expect(result.current.isLoading).toBe(false));

    // Add a task with name
    act(() => {
      result.current.handleChange('name', 'مشروع مع مهام');
      result.current.handleChange('priority', 'medium');
      result.current.handleTaskChange(0, 'name', 'مهمة نموذجية');
    });

    mockCreate.mockResolvedValueOnce({ id: 1 });

    await act(async () => {
      const fakeEvent = { preventDefault: vi.fn() } as unknown as React.FormEvent;
      await result.current.handleSubmit(fakeEvent);
    });

    const payload = mockCreate.mock.calls[0][0];
    expect(payload.tasks).toHaveLength(1);
    expect(payload.tasks[0].name).toBe('مهمة نموذجية');
    expect(payload.tasks[0].title).toBe('مهمة نموذجية');
  });

  it('filters out empty objectives and scope items', async () => {
    const { result } = renderHook(() => useProjectForm({}));

    await waitFor(() => expect(result.current.isLoading).toBe(false));

    act(() => {
      result.current.handleChange('name', 'مشروع اختباري');
      result.current.handleChange('priority', 'low');
      // objectives starts with [''], add a filled one
      result.current.handleArrayItemChange('objectives', 0, 'هدف 1');
      result.current.addArrayItem('objectives');
      result.current.handleArrayItemChange('objectives', 1, '');
    });

    mockCreate.mockResolvedValueOnce({ id: 1 });

    await act(async () => {
      const fakeEvent = { preventDefault: vi.fn() } as unknown as React.FormEvent;
      await result.current.handleSubmit(fakeEvent);
    });

    const payload = mockCreate.mock.calls[0][0];
    expect(payload.objectives).toEqual(['هدف 1']);
  });

  it('strips UI-only fields from team_members and stakeholders', async () => {
    const { result } = renderHook(() => useProjectForm({}));

    await waitFor(() => expect(result.current.isLoading).toBe(false));

    act(() => {
      result.current.handleChange('name', 'مشروع فريق');
      result.current.handleChange('priority', 'medium');
    });

    mockCreate.mockResolvedValueOnce({ id: 1 });

    await act(async () => {
      const fakeEvent = { preventDefault: vi.fn() } as unknown as React.FormEvent;
      await result.current.handleSubmit(fakeEvent);
    });

    const payload = mockCreate.mock.calls[0][0];
    // Default team member has no user_id, so it should be filtered out
    expect(payload.team_members).toEqual([]);
    // Default stakeholder has empty name, so it should be filtered out
    expect(payload.stakeholders).toEqual([]);
  });

  it('shows Arabic permission toast on 403 error', async () => {
    const { result } = renderHook(() => useProjectForm({}));

    await waitFor(() => expect(result.current.isLoading).toBe(false));

    act(() => {
      result.current.handleChange('name', 'مشروع مرفوض');
      result.current.handleChange('priority', 'high');
    });

    mockCreate.mockRejectedValueOnce({ status: 403, message: 'Forbidden' });

    await act(async () => {
      const fakeEvent = { preventDefault: vi.fn() } as unknown as React.FormEvent;
      await result.current.handleSubmit(fakeEvent);
    });

    expect(mockShowToast).toHaveBeenCalledWith(
      'error',
      'ليس لديك صلاحية تنفيذ هذا الإجراء'
    );
  });

  it('shows backend validation errors toast', async () => {
    const { result } = renderHook(() => useProjectForm({}));

    await waitFor(() => expect(result.current.isLoading).toBe(false));

    act(() => {
      result.current.handleChange('name', 'مشروع');
      result.current.handleChange('priority', 'high');
    });

    mockCreate.mockRejectedValueOnce({
      status: 422,
      errors: { name: ['اسم المشروع مستخدم مسبقاً'] },
    });

    await act(async () => {
      const fakeEvent = { preventDefault: vi.fn() } as unknown as React.FormEvent;
      await result.current.handleSubmit(fakeEvent);
    });

    expect(result.current.errors).toEqual({ name: ['اسم المشروع مستخدم مسبقاً'] });
    expect(mockShowToast).toHaveBeenCalledWith('error', 'يرجى تصحيح الأخطاء أدناه');
  });

  /**
   * RISK MAPPING VERIFICATION:
   * Frontend sends description/mitigation keys matching backend StoreProjectRequest validation.
   */
  it('maps frontend risk fields to description/mitigation keys', async () => {
    const { result } = renderHook(() => useProjectForm({}));

    await waitFor(() => expect(result.current.isLoading).toBe(false));

    act(() => {
      result.current.handleChange('name', 'مشروع مخاطر');
      result.current.handleChange('priority', 'medium');
      result.current.handleRiskChange(0, 'description', 'خطر التأخر');
      result.current.handleRiskChange(0, 'mitigation', 'خطة الطوارئ');
    });

    mockCreate.mockResolvedValueOnce({ id: 1 });

    await act(async () => {
      const fakeEvent = { preventDefault: vi.fn() } as unknown as React.FormEvent;
      await result.current.handleSubmit(fakeEvent);
    });

    const payload = mockCreate.mock.calls[0][0];
    expect(payload.risks).toHaveLength(1);
    // Frontend sends description and mitigation directly to backend
    expect(payload.risks[0].description).toBe('خطر التأخر');
    expect(payload.risks[0].mitigation).toBe('خطة الطوارئ');
    // Old keys should not be present
    expect(payload.risks[0].risk).toBeUndefined();
    expect(payload.risks[0].response).toBeUndefined();
  });
});
