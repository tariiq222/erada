import type { ApiError } from '@shared/types/api';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const mockApi = {
  post: vi.fn(),
};

vi.mock('@shared/api/client', () => ({ api: mockApi }));

describe('canonical authorization role assignment API', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('posts one explicit canonical assignment and returns the canonical assignment envelope', async () => {
    const assignmentWrite = {
      role_id: 17,
      scope_type: 'department' as const,
      scope_id: 31,
      inherit_to_children: true,
      expires_at: '2026-08-01T12:00:00Z',
    };
    const payload = { user_id: 9, replace_all: true as const, assignments: [assignmentWrite] };
    const assignment = {
      id: 44,
      ...assignmentWrite,
      role_name: 'department_manager',
      organization_id: 3,
      source: 'manual',
    };
    mockApi.post.mockResolvedValueOnce({ data: { user_id: 9, assignments: [assignment] } });

    const { rolesApi } = await import('@entities/role/api/role.api');
    const response = await rolesApi.assignToUser(payload);

    expect(mockApi.post).toHaveBeenCalledOnce();
    expect(mockApi.post).toHaveBeenCalledWith('/roles/assign', payload);
    expect(response.data.assignments).toEqual([assignment]);
  });

  it.each([403, 409, 422])('propagates a %i failure without manufacturing assignment state', async status => {
    const error: ApiError = {
      status,
      message: `assignment failed (${status})`,
      errors: status === 422 ? { scope_id: ['Invalid scope.'] } : undefined,
    };
    mockApi.post.mockRejectedValueOnce(error);

    const { rolesApi } = await import('@entities/role/api/role.api');
    const request = rolesApi.assignToUser({
      user_id: 9,
      replace_all: true,
      assignments: [{
        role_id: 17,
        scope_type: 'organization',
        scope_id: 3,
        inherit_to_children: false,
        expires_at: null,
      }],
    });

    await expect(request).rejects.toBe(error);
    expect(mockApi.post).toHaveBeenCalledOnce();
  });
});
