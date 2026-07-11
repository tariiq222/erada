import React from 'react';
import userEvent from '@testing-library/user-event';
import { render, screen, waitFor, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { User } from '@shared/types';
import i18n from '@shared/config/i18n';
import { api } from '@shared/api/client';
import { AdminRouter } from '@admin/app/AdminRouter';
import { adminApi } from '@admin/api/adminApi';

const authState = {
  user: {
    id: 1,
    name: 'Control Admin',
    email: 'admin@example.test',
    department_id: null,
    phone: null,
    extension: null,
    job_title: null,
    is_active: true,
    roles: ['super_admin'],
    organization_id: 17,
  } satisfies User & { organization_id: number },
  isLoading: false,
  isAuthenticated: true,
};

vi.mock('@shared/contexts/AuthContext', () => ({
  AuthProvider: ({ children }: { children: React.ReactNode }) => children,
  useAuth: () => ({ ...authState, logout: vi.fn(), refreshUser: vi.fn() }),
}));
vi.mock('@shared/contexts/LocaleContext', () => ({
  LocaleProvider: ({ children }: { children: React.ReactNode }) => children,
  useLocale: () => ({ locale: 'ar', direction: 'rtl', setLocale: vi.fn() }),
}));
vi.mock('@shared/contexts/ThemeContext', () => ({
  ThemeProvider: ({ children }: { children: React.ReactNode }) => children,
  useTheme: () => ({ resolvedTheme: 'light', toggleTheme: vi.fn() }),
}));
vi.mock('@shared/contexts/SystemSettingsContext', () => ({
  SystemSettingsProvider: ({ children }: { children: React.ReactNode }) => children,
  useSystemSettings: () => ({ settings: { name: 'Erada Platform', name_en: 'Erada' } }),
}));
vi.mock('@shared/ui/Toast', () => ({
  ToastProvider: ({ children }: { children: React.ReactNode }) => children,
}));
vi.mock('@shared/api/client', () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn(), blob: vi.fn() },
}));

const apiGet = vi.mocked(api.get);
const apiPost = vi.mocked(api.post);
const apiPut = vi.mocked(api.put);
const apiDelete = vi.mocked(api.delete);

const role = {
  id: 12,
  name: 'project_controller',
  display_name: 'Project Controller',
  permissions: ['projects.view'],
  permissions_count: 1,
  users_count: 0,
  is_system: false,
  scope_type: 'organization',
  label_ar: 'Project Controller',
  label_en: 'Project Controller',
  capabilities: ['projects.view'],
  reach: { projects: 'department' },
  is_admin_role: false,
};

function setPath(path: string) {
  window.history.replaceState({ usr: null, key: 'access-test', idx: 0 }, '', path);
}

describe('admin role and governance contracts', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    document.documentElement.dir = 'rtl';
  });

  it('uses canonical role, access, user-summary, department, and governance adapters', async () => {
    apiGet.mockResolvedValue({ data: [] });
    apiPost.mockResolvedValue({ data: role });
    apiPut.mockResolvedValue({ data: role });
    apiDelete.mockResolvedValue({ message: 'deleted' });

    await adminApi.roles.list();
    await adminApi.roles.abilities();
    await adminApi.roles.create({ name: 'project_controller', scope_type: 'organization' });
    await adminApi.roles.update(12, { reach: { projects: 'department' } });
    await adminApi.roles.delete(12);
    await adminApi.users.summary();
    await adminApi.access.summary(9);
    await adminApi.departments.summary(17);
    await adminApi.governance.list();
    await adminApi.governance.update({ resource_type: 'project', governing_unit_id: 3 });

    expect(apiGet).toHaveBeenNthCalledWith(1, '/admin/roles');
    expect(apiGet).toHaveBeenNthCalledWith(2, '/admin/roles/abilities');
    expect(apiPost).toHaveBeenCalledWith('/admin/roles', { name: 'project_controller', scope_type: 'organization' });
    expect(apiPut).toHaveBeenNthCalledWith(1, '/admin/roles/12', { reach: { projects: 'department' } });
    expect(apiDelete).toHaveBeenCalledWith('/admin/roles/12');
    expect(apiGet).toHaveBeenCalledWith('/admin/users?per_page=100');
    expect(apiGet).toHaveBeenCalledWith('/admin/scoped-roles/user/9/access-summary');
    expect(apiGet).toHaveBeenCalledWith('/admin/departments?organization_id=17&per_page=100&page=1');
    expect(apiGet).toHaveBeenCalledWith('/admin/governance-rules');
    expect(apiPut).toHaveBeenNthCalledWith(2, '/admin/governance-rules', {
      resource_type: 'project',
      governing_unit_id: 3,
    });
  });

  it('creates a role with exact capability and reach fields', async () => {
    apiGet
      .mockResolvedValueOnce({ data: { groups: [{ key: 'projects', label: 'Projects', store: 'engine', abilities: [{ id: 'projects.view', label: 'View projects' }] }] } })
      .mockResolvedValueOnce({ data: [], meta: { total: 0 } });
    apiPost.mockResolvedValue({ data: role });
    const user = userEvent.setup();
    setPath('/roles/new');

    render(<AdminRouter />);

    await user.click(await screen.findByRole('button', { name: i18n.t('common.create') }));
    await waitFor(() => expect(screen.getByLabelText(i18n.t('admin.roles.fields.name'))).toHaveAttribute('aria-invalid', 'true'));

    await user.type(screen.getByLabelText(i18n.t('admin.roles.fields.name')), 'project_controller');
    await user.type(screen.getByLabelText(i18n.t('admin.roles.fields.labelEn')), 'Project Controller');
    await user.click(screen.getByRole('checkbox', { name: 'View projects' }));
    expect(screen.queryByLabelText(i18n.t('admin.roles.fields.scope'))).not.toBeInTheDocument();
    expect(screen.getByRole('option', { name: i18n.t('admin.roles.reach.department') })).toBeInTheDocument();
    await user.selectOptions(screen.getByLabelText(`Projects ${i18n.t('admin.roles.reach.label')}`), 'department');
    await user.click(screen.getByRole('button', { name: i18n.t('common.create') }));

    await waitFor(() => expect(apiPost).toHaveBeenCalledWith('/admin/roles', {
      name: 'project_controller',
      scope_type: 'organization',
      label_ar: '',
      label_en: 'Project Controller',
      permissions_capabilities: ['projects.view'],
      reach: { projects: 'department' },
    }));
  });

  it('shows a user access summary from canonical user results', async () => {
    apiGet
      .mockResolvedValueOnce({
        data: [{ id: 9, name: 'Audit User', email: 'audit@example.test', department: { id: 4, name: 'Quality' } }],
        current_page: 1,
        last_page: 1,
        total: 1,
      })
      .mockResolvedValueOnce({
        data: {
          functional_roles: ['viewer'],
          scoped: [{
            role: 'risk_reviewer',
            label: 'Risk Reviewer',
            scope_type: 'department',
            scope_id: 4,
            scope_name: 'Quality',
            source: 'auto',
            reach: { risks: 'department' },
          }],
        },
      });
    const user = userEvent.setup();
    setPath('/access');

    render(<AdminRouter />);

    const row = await screen.findByRole('row', { name: /Audit User/ });
    await user.click(within(row).getByRole('button', { name: i18n.t('admin.access.members.viewAccess') }));

    expect(await screen.findByText('Risk Reviewer')).toBeInTheDocument();
    expect(screen.getByText(/risks/)).toHaveTextContent(i18n.t('admin.roles.reach.department'));
    expect(apiGet).toHaveBeenLastCalledWith('/admin/scoped-roles/user/9/access-summary');
  });

  it('saves governance with exact FormRequest fields and exposes errors', async () => {
    apiGet
      .mockResolvedValueOnce({ data: [{ resource_type: 'project', label: 'Projects', governing_unit_id: null, governing_unit_name: null, applies_to_children: true }] })
      .mockResolvedValueOnce({ data: [{ id: 3, name: 'Portfolio Office' }] });
    apiPut.mockRejectedValue({ message: 'Forbidden department' });
    const user = userEvent.setup();
    setPath('/access/governance');

    render(<AdminRouter />);

    const selection = await screen.findByLabelText('Projects');
    await user.selectOptions(selection, '3');

    expect(await screen.findByRole('alert')).toHaveTextContent('Forbidden department');
    expect(selection).toHaveValue('');
    expect(apiPut).toHaveBeenCalledWith('/admin/governance-rules', {
      resource_type: 'project',
      governing_unit_id: 3,
    });
  });

  it('confirms custom role deletion and keeps system roles immutable', async () => {
    apiGet.mockResolvedValue({ data: [role, { ...role, id: 1, name: 'viewer', display_name: 'Viewer', is_system: true }], meta: { total: 2 } });
    apiDelete.mockResolvedValue({ message: 'deleted' });
    vi.spyOn(window, 'confirm').mockReturnValue(true);
    const user = userEvent.setup();
    setPath('/roles');

    render(<AdminRouter />);

    const customRow = await screen.findByRole('row', { name: /Project Controller/ });
    await user.click(within(customRow).getByRole('button', { name: i18n.t('common.delete') }));
    expect(apiDelete).toHaveBeenCalledWith('/admin/roles/12');

    const systemRow = screen.getByRole('row', { name: /Viewer/ });
    expect(within(systemRow).queryByRole('button', { name: i18n.t('common.delete') })).not.toBeInTheDocument();
  });

  it('does not send scope_type when editing an organization role', async () => {
    apiGet
      .mockResolvedValueOnce({ data: { groups: [] } })
      .mockResolvedValueOnce({ data: role });
    apiPut.mockResolvedValue({ data: role });
    const user = userEvent.setup();
    setPath('/roles/12/edit');

    render(<AdminRouter />);

    const label = await screen.findByLabelText(i18n.t('admin.roles.fields.labelEn'));
    await user.clear(label);
    await user.type(label, 'Updated Controller');
    await user.click(screen.getByRole('button', { name: i18n.t('common.save_changes') }));

    expect(apiPut).toHaveBeenCalledWith('/admin/roles/12', expect.not.objectContaining({ scope_type: expect.anything() }));
  });

  it('preserves the governing-departments bookmark as a governance redirect', async () => {
    apiGet.mockImplementation((path) => {
      if (path === '/admin/roles/abilities') return Promise.resolve({ data: { groups: [] } });
      if (path === '/admin/roles/scope-options') return Promise.resolve({ scopes: [{ key: 'organization', label: 'Organization' }] });
      if (path === '/admin/governance-rules') return Promise.resolve({ data: [] });
      if (path.startsWith('/admin/departments?')) return Promise.resolve({ data: [], current_page: 1, last_page: 1, per_page: 100, total: 0 });
      return Promise.resolve({ data: role });
    });
    setPath('/roles/governing-departments');

    render(<AdminRouter />);

    await waitFor(() => expect(window.location.pathname).toBe('/access/governance'));
    expect(await screen.findByRole('heading', { name: i18n.t('admin.access.tabs.governance') })).toBeInTheDocument();
  });

  it('loads every department page for the actor organization', async () => {
    apiGet
      .mockResolvedValueOnce({ data: [{ id: 1, name: 'First Unit' }], current_page: 1, last_page: 2, per_page: 100, total: 2 })
      .mockResolvedValueOnce({ data: [{ id: 2, name: 'Second Unit' }], current_page: 2, last_page: 2, per_page: 100, total: 2 });

    const result = await adminApi.departments.summary(17);

    expect(apiGet).toHaveBeenNthCalledWith(1, '/admin/departments?organization_id=17&per_page=100&page=1');
    expect(apiGet).toHaveBeenNthCalledWith(2, '/admin/departments?organization_id=17&per_page=100&page=2');
    expect(result.data.map((unit) => unit.name)).toEqual(['First Unit', 'Second Unit']);
  });
});
