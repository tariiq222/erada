import React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

const navigateMock = vi.fn();

const organizationsApiMock = {
  list: vi.fn(),
};
const scopeTypesApiMock = {
  list: vi.fn(),
};
const rolesApiMock = {
  list: vi.fn(),
};
const authorizationAssignmentsApiMock = {
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
  scopeTypesApi: scopeTypesApiMock,
}));

vi.mock('@entities/role', () => ({
  rolesApi: rolesApiMock,
}));

vi.mock('@entities/authorization-assignment', async () => {
  const actual = await vi.importActual<typeof import('@entities/authorization-assignment')>('@entities/authorization-assignment');
  return {
    ...actual,
    authorizationAssignmentsApi: authorizationAssignmentsApiMock,
  };
});

describe('admin pages coverage', () => {
  beforeEach(() => {
    navigateMock.mockReset();
    organizationsApiMock.list.mockReset();
    scopeTypesApiMock.list.mockReset();
    rolesApiMock.list.mockReset();
    authorizationAssignmentsApiMock.auditLogs.mockReset();
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

  it('does not expose a scope-type create action without a real form', async () => {
    scopeTypesApiMock.list.mockResolvedValue({ data: [] });
    const { default: ScopeTypesList } = await import('@pages/admin/scope-types/ScopeTypesList');

    render(<ScopeTypesList />);

    expect(await screen.findByText('admin.scopeTypes.empty')).toBeInTheDocument();
    expect(screen.queryByText('admin.scopeTypes.add')).not.toBeInTheDocument();
  });

  it('loads roles and filters system and custom roles', async () => {
    const user = userEvent.setup();
    rolesApiMock.list.mockResolvedValue({
      data: [
        { id: 1, name: 'super_admin', label: 'Super Admin', scope_type: 'all', capabilities: ['core.manage'], reach: {}, is_system: true, is_admin_role: true, is_active: true, users_count: 1 },
        { id: 2, name: 'reviewer', label: 'Reviewer', scope_type: 'organization', capabilities: ['projects.view'], reach: {}, is_system: false, is_admin_role: false, is_active: true, users_count: 4 },
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

  it('loads authorization assignment audit logs, filters client-side, applies server filters, clears filters, and paginates', async () => {
    const user = userEvent.setup();
    authorizationAssignmentsApiMock.auditLogs.mockResolvedValue({
      data: [
        {
          id: 1,
          user: { name: 'Actor One' },
          target_user: { name: 'Target User' },
          action: 'canonical_assignment_assigned',
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
          action: 'canonical_assignment_revoked',
          description: 'Revoked manager role',
          scope_type: 'department',
          role: null,
          ip_address: null,
          created_at: '2026-06-15T00:00:00Z',
        },
      ],
      meta: { current_page: 1, last_page: 2, per_page: 25, total: 30 },
    });
    const { default: AuthorizationAssignmentAuditLogs } = await import('@pages/admin/authorization/AuthorizationAssignmentAuditLogs');

    render(<AuthorizationAssignmentAuditLogs />);

    expect(await screen.findByText('Assigned manager role')).toBeInTheDocument();
    expect(screen.getByText('Revoked manager role')).toBeInTheDocument();

    await user.type(screen.getByPlaceholderText('admin.authorizationAssignmentAudit.filters.searchPlaceholder'), 'assigned');
    expect(screen.queryByText('Revoked manager role')).not.toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'admin.authorizationAssignmentAudit.filters.allActions' }));
    await user.click(screen.getByRole('option', { name: 'admin.authorizationAssignmentAudit.actions.role_revoked' }));
    await waitFor(() => expect(authorizationAssignmentsApiMock.auditLogs).toHaveBeenLastCalledWith(expect.objectContaining({ action: 'canonical_assignment_revoked' })));

    await user.clear(screen.getByPlaceholderText('admin.authorizationAssignmentAudit.filters.searchPlaceholder'));
    await user.type(screen.getByPlaceholderText('admin.authorizationAssignmentAudit.filters.userId'), '44');
    await user.tab();
    await waitFor(() => expect(authorizationAssignmentsApiMock.auditLogs).toHaveBeenLastCalledWith(expect.objectContaining({ user_id: '44' })));

    const clearButton = screen.getByRole('button', { name: 'common.clear' });
    await user.click(clearButton);
    expect(screen.getByPlaceholderText('admin.authorizationAssignmentAudit.filters.userId')).toHaveValue('');

    const nextButton = screen.getByRole('button', { name: /next|التالي|common.next/i });
    await user.click(nextButton);
    await waitFor(() => expect(authorizationAssignmentsApiMock.auditLogs).toHaveBeenCalledWith(expect.objectContaining({ page: 2 })));
  });

  it('authorization audit action filter wires every canonical event value (covers the full backend write set)', async () => {
    // Verified residual (2026-07-12): the filter UI must POST one of the
    // exact strings written by AuthorizationAssignmentService::auditMutation,
    // ScopedDepartmentRoleSyncService::auditMutation, or
    // RoleController::writeAudit. Every option in the picker must round-trip
    // its display label → query param wire value, with no Spatie-era dead
    // options (permission_granted/permission_revoked/access_denied) and no
    // missing canonical events (canonical_assignment_synced,
    // role_created, role_disabled).
    const user = userEvent.setup();
    authorizationAssignmentsApiMock.auditLogs.mockResolvedValue({
      data: [
        {
          id: 11,
          user: { name: 'Actor One' },
          target_user: { name: 'Target User' },
          action: 'canonical_assignment_assigned',
          description: 'Assigned manager role',
          scope_type: 'project',
          role: 'manager',
          ip_address: '127.0.0.1',
          created_at: '2026-07-12T00:00:00Z',
        },
      ],
      meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
    });
    const { default: AuthorizationAssignmentAuditLogs } = await import('@pages/admin/authorization/AuthorizationAssignmentAuditLogs');
    const { AUTHORIZATION_ASSIGNMENT_AUDIT_EVENT_LIST } = await import('@entities/authorization-assignment');

    render(<AuthorizationAssignmentAuditLogs />);

    let currentButtonLabel: string | null = null;

    expect(await screen.findByText('Assigned manager role')).toBeInTheDocument();

    // Every canonical event is offered as a picker option with the same
    // wire value the backend writes; no Spatie-era strings leak through.
    const expectedWireValues = AUTHORIZATION_ASSIGNMENT_AUDIT_EVENT_LIST;
    expect(expectedWireValues).toEqual([
      'canonical_assignment_assigned',
      'canonical_assignment_revoked',
      'canonical_assignment_synced',
      'role_created',
      'role_updated',
      'role_disabled',
    ]);
    for (const event of expectedWireValues) {
      expect(event).not.toMatch(/^(role_assigned|role_revoked|permission_granted|permission_revoked|access_denied)$/);
    }

    // Click each option and assert the request carries the matching
    // canonical wire value (not the translated display label).
    const labelLookup: Record<string, string> = {
      canonical_assignment_assigned: 'admin.authorizationAssignmentAudit.actions.role_assigned',
      canonical_assignment_revoked: 'admin.authorizationAssignmentAudit.actions.role_revoked',
      // canonical_assignment_synced has no pre-existing translation; the
      // picker falls back to the raw event text. Same for role_created and
      // role_disabled.
      role_updated: 'admin.authorizationAssignmentAudit.actions.role_updated',
    };

    for (const event of expectedWireValues) {
      const optionLabel = labelLookup[event] || event;
      // The Select button's accessible name tracks the currently-selected
      // option's label (or the placeholder when nothing is picked). We walk
      // the cycle: at iteration N the button shows the label of iteration N-1
      // (or the placeholder on iteration 0). After the click the button
      // takes on this iteration's label, ready for the next round.
      const previouslyShownLabel =
        currentButtonLabel ?? 'admin.authorizationAssignmentAudit.filters.allActions';
      await user.click(screen.getByRole('button', { name: previouslyShownLabel }));
      await user.click(screen.getByRole('option', { name: optionLabel }));
      await waitFor(() =>
        expect(authorizationAssignmentsApiMock.auditLogs).toHaveBeenLastCalledWith(
          expect.objectContaining({ action: event }),
        ),
      );
      currentButtonLabel = optionLabel;
    }
  });

  it('shows authorization assignment audit error state', async () => {
    authorizationAssignmentsApiMock.auditLogs.mockRejectedValueOnce(new Error('audit failure'));
    const { default: AuthorizationAssignmentAuditLogs } = await import('@pages/admin/authorization/AuthorizationAssignmentAuditLogs');

    render(<AuthorizationAssignmentAuditLogs />);

    expect(await screen.findByText('audit failure')).toBeInTheDocument();
  });
});
