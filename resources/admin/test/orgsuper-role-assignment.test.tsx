import React from 'react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { render, screen, waitFor, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import i18n from '@shared/config/i18n';
import { api } from '@shared/api/client';
import { AccessPage } from '@admin/pages/access/AccessPage';

const authState: {
  user: {
    id: number;
    name: string;
    email: string;
    organization_id: number;
    is_super_admin: boolean;
    is_organization_super_admin: boolean;
    roles?: string[];
  };
} = {
  user: {
    id: 1,
    name: 'Organization Operator',
    email: 'operator@example.test',
    organization_id: 17,
    is_super_admin: false,
    is_organization_super_admin: true,
    roles: [],
  },
};

vi.mock('@shared/contexts/AuthContext', () => ({
  useAuth: () => ({ user: authState.user }),
}));

vi.mock('@shared/api/client', () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn(), blob: vi.fn() },
}));

const apiGet = vi.mocked(api.get);
const apiPost = vi.mocked(api.post);

const member = {
  id: 9,
  name: 'Audit User',
  email: 'audit@example.test',
  department: { id: 4, name: 'Quality' },
};

const operationalRole = {
  id: 12,
  name: 'project_controller',
  display_name: 'Project Controller',
  permissions: ['projects.view'],
  permissions_count: 1,
  users_count: 0,
  is_system: false,
  is_admin_role: false,
  is_active: true,
  scope_type: 'organization',
};

const protectedSystemRole = {
  ...operationalRole,
  id: 1,
  name: 'super_admin',
  display_name: 'System Admin',
  is_system: true,
  is_admin_role: true,
};

const protectedAdminRole = {
  ...operationalRole,
  id: 2,
  name: 'organization_super_admin',
  display_name: 'Organization Super Admin',
  is_admin_role: true,
};

const accessSummary = {
  data: {
    assignments: [{
      id: 21,
      role_id: 18,
      role: 'risk_reviewer',
      label: 'Risk Reviewer',
      scope_type: 'organization',
      scope_id: 17,
      scope_name: 'Organization',
      organization_id: 17,
      inherit_to_children: false,
      expires_at: null,
      source: 'manual',
      granted_by: 1,
    }],
  },
};

function mockSuccessfulReads(options?: { members?: typeof member[]; roles?: typeof operationalRole[] }) {
  const members = options?.members ?? [member];
  const roles = options?.roles ?? [operationalRole, protectedSystemRole, protectedAdminRole];

  apiGet.mockImplementation((path) => {
    if (path === '/users?per_page=100') return Promise.resolve({ data: members });
    if (path === '/roles') return Promise.resolve({ data: roles, meta: { total: roles.length } });
    if (path === '/authorization-role-assignments/user/9/access-summary') return Promise.resolve(accessSummary);
    return Promise.reject(new Error(`Unexpected GET ${path}`));
  });
}

function renderPage() {
  return render(
    <MemoryRouter>
      <AccessPage />
    </MemoryRouter>,
  );
}

describe('OrgSuper operational role assignment', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    document.documentElement.dir = 'rtl';
    authState.user = {
      id: 1,
      name: 'Organization Operator',
      email: 'operator@example.test',
      organization_id: 17,
      is_super_admin: false,
      is_organization_super_admin: true,
      roles: [],
    };
  });

  it('offers only operational roles and posts the narrow organization-scoped contract', async () => {
    mockSuccessfulReads();
    apiPost.mockResolvedValue({ message: 'assigned', data: { user_id: 9, assignments: [] } });
    const actor = userEvent.setup();
    renderPage();

    const memberRow = await screen.findByRole('row', { name: /Audit User/ });
    await actor.click(within(memberRow).getByRole('button', { name: i18n.t('admin.access.members.viewAccess') }));

    const roleSelect = await screen.findByLabelText(i18n.t('admin.access.assignment.role'));
    expect(within(roleSelect).getByRole('option', { name: 'Project Controller' })).toBeInTheDocument();
    expect(within(roleSelect).queryByRole('option', { name: 'System Admin' })).not.toBeInTheDocument();
    expect(within(roleSelect).queryByRole('option', { name: 'Organization Super Admin' })).not.toBeInTheDocument();

    await actor.selectOptions(roleSelect, '12');
    await actor.click(screen.getByRole('button', { name: i18n.t('admin.access.assignment.submit') }));

    await waitFor(() => expect(apiPost).toHaveBeenCalledWith('/org-super/role-assignments', {
      user_id: 9,
      replace_all: true,
      assignments: [{
        role_id: 12,
        scope_type: 'organization',
        scope_id: 17,
        inherit_to_children: false,
      }],
    }));
    expect(apiPost.mock.calls.some(([path]) => path === '/roles/assign')).toBe(false);
    expect(await screen.findByRole('alert')).toHaveTextContent(
      i18n.t('admin.access.assignment.success', { role: 'Project Controller', user: 'Audit User' }),
    );
  });

  it('uses authority flags and never exposes the OrgSuper mutation to a platform super admin', async () => {
    authState.user = {
      ...authState.user,
      is_super_admin: true,
      is_organization_super_admin: true,
      roles: ['organization_super_admin'],
    };
    mockSuccessfulReads();
    const actor = userEvent.setup();
    renderPage();

    const memberRow = await screen.findByRole('row', { name: /Audit User/ });
    await actor.click(within(memberRow).getByRole('button', { name: i18n.t('admin.access.members.viewAccess') }));

    expect(screen.queryByRole('heading', { name: i18n.t('admin.access.assignment.title') })).not.toBeInTheDocument();
    expect(apiGet).not.toHaveBeenCalledWith('/roles');
    expect(apiPost).not.toHaveBeenCalled();
  });

  it('renders localized empty member and operational-role states', async () => {
    mockSuccessfulReads({ members: [] });
    const { unmount } = renderPage();

    expect(await screen.findByText(i18n.t('admin.access.members.empty'))).toBeInTheDocument();

    unmount();
    vi.clearAllMocks();
    mockSuccessfulReads({ roles: [protectedSystemRole, protectedAdminRole] });
    const actor = userEvent.setup();
    renderPage();

    const memberRow = await screen.findByRole('row', { name: /Audit User/ });
    await actor.click(within(memberRow).getByRole('button', { name: i18n.t('admin.access.members.viewAccess') }));

    expect(await screen.findByText(i18n.t('admin.access.assignment.noRoles'))).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: i18n.t('admin.access.assignment.submit') })).not.toBeInTheDocument();
  });

  it('renders the localized load error without leaving a loading state behind', async () => {
    apiGet.mockRejectedValue({ message: 'Access catalog unavailable' });
    renderPage();

    expect(await screen.findByRole('alert')).toHaveTextContent('Access catalog unavailable');
    expect(screen.queryByText(i18n.t('common.loading'))).not.toBeInTheDocument();
  });
});
