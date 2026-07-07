import React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

const navigateMock = vi.fn();

const organizationsApiMock = {
  list: vi.fn(),
};
const rolesApiMock = {
  list: vi.fn(),
};
const scopedRolesApiMock = {
  auditLogs: vi.fn(),
};

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock('react-router-dom', async () => {
  const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom');
  return {
    ...actual,
    useNavigate: () => navigateMock,
  };
});

vi.mock('@entities/admin', () => ({
  organizationsApi: organizationsApiMock,
}));

vi.mock('@entities/role', () => ({
  rolesApi: rolesApiMock,
}));

vi.mock('@entities/scoped-role', () => ({
  scopedRolesApi: scopedRolesApiMock,
}));

describe('admin pages coverage', () => {
  beforeEach(() => {
    navigateMock.mockReset();
    organizationsApiMock.list.mockReset();
    rolesApiMock.list.mockReset();
    scopedRolesApiMock.auditLogs.mockReset();
    Element.prototype.scrollIntoView = vi.fn();
  });

  it('loads organizations, searches, paginates, and navigates from row and actions', async () => {
    const user = userEvent.setup();
    organizationsApiMock.list.mockResolvedValue({
      data: [
        { id: 1, name: 'Erada PMO', code: 'ERD', email: 'info@example.test', is_active: true },
        { id: 2, name: 'Inactive Org', code: 'OLD', email: null, is_active: false },
      ],
      meta: { current_page: 1, last_page: 2, total: 22 },
    });
    const { default: OrganizationsList } = await import('@pages/admin/organizations/OrganizationsList');

    render(<OrganizationsList />);

    expect(await screen.findByText('Erada PMO')).toBeInTheDocument();
    expect(screen.getByText('Inactive Org')).toBeInTheDocument();
    await user.type(screen.getByPlaceholderText('admin.organizations.searchPlaceholder'), 'Erada{enter}');
    await waitFor(() => expect(organizationsApiMock.list).toHaveBeenLastCalledWith({ search: 'Erada', page: 1, per_page: 20 }));

    await user.click(screen.getByText('common.next'));
    await waitFor(() => expect(organizationsApiMock.list).toHaveBeenLastCalledWith({ search: 'Erada', page: 2, per_page: 20 }));

    await user.click(screen.getByText('admin.organizations.add'));
    expect(navigateMock).toHaveBeenCalledWith('/admin/organizations/new');
    await user.click(screen.getAllByText('common.view')[0]);
    expect(navigateMock).toHaveBeenCalledWith('/admin/organizations/1');
  });

  it('shows organization empty and error states', async () => {
    organizationsApiMock.list.mockResolvedValueOnce({ data: [], meta: { current_page: 1, last_page: 1, total: 0 } });
    const { default: OrganizationsList } = await import('@pages/admin/organizations/OrganizationsList');
    const { rerender } = render(<OrganizationsList />);
    expect(await screen.findByText('admin.organizations.empty')).toBeInTheDocument();

    organizationsApiMock.list.mockRejectedValueOnce(new Error('organization failure'));
    rerender(<OrganizationsList />);
    await userEvent.click(screen.getByText('common.search'));
    expect(await screen.findByText('organization failure')).toBeInTheDocument();
  });

  it('loads roles and filters system and custom roles', async () => {
    const user = userEvent.setup();
    rolesApiMock.list.mockResolvedValue({
      data: [
        { id: 1, name: 'super_admin', display_name: 'Super Admin', is_system: true, permissions_count: 10, users_count: 1 },
        { id: 2, name: 'reviewer', display_name: 'Reviewer', is_system: false, permissions_count: 3, users_count: 4 },
      ],
    });
    const { default: RolesList } = await import('@pages/admin/roles/RolesList');

    render(<RolesList />);

    expect(await screen.findByText('Super Admin')).toBeInTheDocument();
    expect(screen.getByText('Reviewer')).toBeInTheDocument();
    expect(screen.getByText('admin.roles.systemRole')).toBeInTheDocument();
    expect(screen.getByText('admin.roles.customRole')).toBeInTheDocument();

    await user.type(screen.getByPlaceholderText('admin.roles.searchPlaceholder'), 'review');
    expect(screen.queryByText('Super Admin')).not.toBeInTheDocument();
    expect(screen.getByText('Reviewer')).toBeInTheDocument();

    await user.click(screen.getByText('admin.roles.add'));
    expect(navigateMock).toHaveBeenCalledWith('/admin/roles/new');
    await user.click(screen.getByText('common.view'));
    expect(navigateMock).toHaveBeenCalledWith('/admin/roles/2');
  });

  it('shows role error state', async () => {
    rolesApiMock.list.mockRejectedValueOnce(new Error('roles failure'));
    const { default: RolesList } = await import('@pages/admin/roles/RolesList');

    render(<RolesList />);

    expect(await screen.findByText('roles failure')).toBeInTheDocument();
  });

  it('loads scoped-role audit logs, filters client-side, applies server filters, clears filters, and paginates', async () => {
    const user = userEvent.setup();
    scopedRolesApiMock.auditLogs.mockResolvedValue({
      data: [
        {
          id: 1,
          user: { name: 'Actor One' },
          target_user: { name: 'Target User' },
          action: 'role_assigned',
          description: 'Assigned manager role',
          scope_type: 'project',
          role: 'manager',
          ip_address: '127.0.0.1',
          created_at: '2026-06-16T00:00:00Z',
        },
        {
          id: 2,
          user: { name: 'Other Actor' },
          target_user: null,
          action: 'access_denied',
          description: 'Denied access',
          scope_type: 'department',
          role: null,
          ip_address: null,
          created_at: '2026-06-15T00:00:00Z',
        },
      ],
      meta: { current_page: 1, last_page: 2, per_page: 25, total: 30 },
    });
    const { default: ScopedRoleAuditLogs } = await import('@pages/admin/scoped-roles/ScopedRoleAuditLogs');

    render(<ScopedRoleAuditLogs />);

    expect(await screen.findByText('Assigned manager role')).toBeInTheDocument();
    expect(screen.getByText('Denied access')).toBeInTheDocument();

    await user.type(screen.getByPlaceholderText('admin.scopedRolesAudit.filters.searchPlaceholder'), 'assigned');
    expect(screen.queryByText('Denied access')).not.toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'admin.scopedRolesAudit.filters.allActions' }));
    await user.click(screen.getByRole('option', { name: 'admin.scopedRolesAudit.actions.access_denied' }));
    await waitFor(() => expect(scopedRolesApiMock.auditLogs).toHaveBeenLastCalledWith(expect.objectContaining({ action: 'access_denied' })));

    await user.clear(screen.getByPlaceholderText('admin.scopedRolesAudit.filters.searchPlaceholder'));
    await user.type(screen.getByPlaceholderText('admin.scopedRolesAudit.filters.userId'), '44');
    await user.tab();
    await waitFor(() => expect(scopedRolesApiMock.auditLogs).toHaveBeenLastCalledWith(expect.objectContaining({ user_id: '44' })));

    const clearButton = screen.getByRole('button', { name: 'common.clear' });
    await user.click(clearButton);
    expect(screen.getByPlaceholderText('admin.scopedRolesAudit.filters.userId')).toHaveValue('');

    const nextButton = screen.getByRole('button', { name: /next|التالي|common.next/i });
    await user.click(nextButton);
    await waitFor(() => expect(scopedRolesApiMock.auditLogs).toHaveBeenCalledWith(expect.objectContaining({ page: 2 })));
  });

  it('shows scoped-role audit error state', async () => {
    scopedRolesApiMock.auditLogs.mockRejectedValueOnce(new Error('audit failure'));
    const { default: ScopedRoleAuditLogs } = await import('@pages/admin/scoped-roles/ScopedRoleAuditLogs');

    render(<ScopedRoleAuditLogs />);

    expect(await screen.findByText('audit failure')).toBeInTheDocument();
  });
});
