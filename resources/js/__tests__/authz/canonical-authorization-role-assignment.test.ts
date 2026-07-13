import { beforeEach, describe, expect, it, vi } from 'vitest';

const { post, put, deleteRequest } = vi.hoisted(() => ({
  post: vi.fn(),
  put: vi.fn(),
  deleteRequest: vi.fn(),
}));

vi.mock('@shared/api/client', () => ({
  api: {
    get: vi.fn(),
    post,
    put,
    delete: deleteRequest,
  },
}));

import { departmentRolesApi } from '@entities/hr';
import { projectsApi } from '@entities/project';

describe('canonical authorization role assignment API contract', () => {
  beforeEach(() => {
    vi.resetAllMocks();
  });

  it('assigns a canonical role to a project member using role_id', async () => {
    const response = { data: { id: 91, role_id: 12, scope_type: 'project', scope_id: 7 } };
    post.mockResolvedValueOnce(response);

    await expect(
      projectsApi.assignRoleAssignment(7, { user_id: 5, role_id: 12, expires_at: null }),
    ).resolves.toBe(response);

    expect(post).toHaveBeenCalledWith('/projects/7/roles', {
      user_id: 5,
      role_id: 12,
      expires_at: null,
    });
  });

  it('updates a project member from the canonical server response', async () => {
    const response = { data: { id: 91, role_id: 13, scope_type: 'project', scope_id: 7 } };
    put.mockResolvedValueOnce(response);

    await expect(projectsApi.updateMemberRole(7, 5, 13)).resolves.toBe(response);

    expect(put).toHaveBeenCalledWith('/projects/7/roles/5', { role_id: 13 });
  });

  it('assigns a canonical role to a department member using role_id', async () => {
    const response = { data: { id: 92, role_id: 14, scope_type: 'department', scope_id: 8 } };
    post.mockResolvedValueOnce(response);

    await expect(
      departmentRolesApi.assignRoleAssignment(8, {
        user_id: 6,
        role_id: 14,
        inherit_to_children: true,
        expires_at: null,
      }),
    ).resolves.toBe(response);

    expect(post).toHaveBeenCalledWith('/departments/8/roles', {
      user_id: 6,
      role_id: 14,
      inherit_to_children: true,
      expires_at: null,
    });
  });

  it.each([403, 409, 422])('propagates HTTP %s without fabricating assignment state', async (status) => {
    const error = Object.assign(new Error('assignment rejected'), { status });
    post.mockRejectedValueOnce(error);

    await expect(
      departmentRolesApi.assignRoleAssignment(8, {
        user_id: 6,
        role_id: 14,
        inherit_to_children: false,
      }),
    ).rejects.toBe(error);
  });

  it('revokes the exact canonical project and department assignments', async () => {
    deleteRequest.mockResolvedValue({ message: 'ok' });

    await projectsApi.removeMember(7, 5, 12);
    await departmentRolesApi.removeMember(8, 6, 14);

    expect(deleteRequest).toHaveBeenNthCalledWith(1, '/projects/7/roles/5?role_id=12');
    expect(deleteRequest).toHaveBeenNthCalledWith(2, '/departments/8/roles/6?role_id=14');
  });
});
