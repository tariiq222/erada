import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const usersApiMock = vi.hoisted(() => ({
  getSecurity: vi.fn(),
  roleAssignments: vi.fn(),
  unlock: vi.fn(),
}));

vi.mock('@entities/user', () => ({ usersApi: usersApiMock }));

vi.mock('@features/access-control/ui/RequirePermission', () => ({
  RequirePermission: ({ children }: { children: React.ReactNode }) => children,
}));

import { UserSecurityCard } from '@pages/users/components/UserSecurityCard';

describe('UserSecurityCard canonical role assignments', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    usersApiMock.getSecurity.mockResolvedValue({
      security: {
        is_locked: false,
        locked_until: null,
        failed_attempts: 0,
        last_failed_login: null,
        last_login: null,
        last_login_ip: null,
      },
    });
    usersApiMock.roleAssignments.mockResolvedValue({
      data: [
        {
          id: 41,
          role_id: 7,
          role: 'project_manager',
          label: 'مدير مشروع',
          scope_type: 'project',
          scope_id: 15,
          scope_name: 'مشروع التحول',
          organization_id: 2,
          inherit_to_children: false,
          expires_at: null,
          source: 'manual',
          granted_by: 3,
        },
        {
          id: 42,
          role_id: 8,
          role: 'department_manager',
          label: 'مدير قسم',
          scope_type: 'department',
          scope_id: 5,
          scope_name: 'إدارة التقنية',
          organization_id: 2,
          inherit_to_children: true,
          expires_at: null,
          source: 'auto',
          granted_by: null,
        },
      ],
    });
  });

  it('loads and renders the canonical flat assignment payload', async () => {
    render(<UserSecurityCard userId={9} />);

    await waitFor(() => expect(usersApiMock.roleAssignments).toHaveBeenCalledWith(9));
    expect(screen.getByText('مشروع التحول')).toBeInTheDocument();
    expect(screen.getByText('مدير مشروع')).toBeInTheDocument();
    expect(screen.getByText('إدارة التقنية')).toBeInTheDocument();
    expect(screen.getByText('مدير قسم')).toBeInTheDocument();
  });
});
