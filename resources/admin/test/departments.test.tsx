import React from 'react';
import userEvent from '@testing-library/user-event';
import { render, screen, waitFor, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { User } from '@shared/types';
import i18n from '@shared/config/i18n';
import { api } from '@shared/api/client';
import { AdminRouter } from '@admin/app/AdminRouter';
import { adminApi } from '@admin/api/adminApi';

const authState = { user: { id: 1, name: 'Control Admin', email: 'admin@example.test', department_id: null, phone: null, extension: null, job_title: null, is_active: true, roles: ['super_admin'], organization_id: 17 } satisfies User & { organization_id: number }, isLoading: false, isAuthenticated: true };
vi.mock('@shared/contexts/AuthContext', () => ({ AuthProvider: ({ children }: { children: React.ReactNode }) => children, useAuth: () => ({ ...authState, logout: vi.fn(), refreshUser: vi.fn() }) }));
vi.mock('@shared/contexts/LocaleContext', () => ({ LocaleProvider: ({ children }: { children: React.ReactNode }) => children, useLocale: () => ({ locale: 'ar', direction: 'rtl', setLocale: vi.fn() }) }));
vi.mock('@shared/contexts/ThemeContext', () => ({ ThemeProvider: ({ children }: { children: React.ReactNode }) => children, useTheme: () => ({ resolvedTheme: 'light', toggleTheme: vi.fn() }) }));
vi.mock('@shared/contexts/SystemSettingsContext', () => ({ SystemSettingsProvider: ({ children }: { children: React.ReactNode }) => children, useSystemSettings: () => ({ settings: { name: 'Erada Platform', name_en: 'Erada' } }) }));
vi.mock('@shared/ui/Toast', () => ({ ToastProvider: ({ children }: { children: React.ReactNode }) => children }));
vi.mock('@shared/api/client', () => ({ api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn(), blob: vi.fn() } }));

const apiGet = vi.mocked(api.get);
const apiPost = vi.mocked(api.post);
const apiPut = vi.mocked(api.put);
const apiDelete = vi.mocked(api.delete);
const department = { id: 4, organization_id: 17, name: 'Quality', code: 'QA', description: 'Quality office', parent_id: null, parent: null, level: 1, level_name: 'Top Management', manager_id: null, manager: null, is_active: true, users_count: 0, children: [{ id: 5, name: 'Audits', code: 'AUD', parent_id: 4 }] };

function setPath(path: string) { window.history.replaceState({ usr: null, key: 'departments-test', idx: 0 }, '', path); }

describe('admin department contracts', () => {
  beforeEach(() => { vi.clearAllMocks(); document.documentElement.dir = 'rtl'; });

  it('uses canonical department adapters with explicit organization filters', async () => {
    apiGet.mockResolvedValue({ data: [] });
    apiPost.mockResolvedValue({ department });
    apiPut.mockResolvedValue({ department });
    apiDelete.mockResolvedValue({ message: 'deleted' });
    await adminApi.departments.list({ organization_id: 17, page: 2 });
    await adminApi.departments.get(4);
    await adminApi.departments.hierarchy();
    await adminApi.departments.create({ name: 'Quality', level: 1, organization_id: 17 });
    await adminApi.departments.update(4, { name: 'Quality', level: 1 });
    await adminApi.departments.delete(4);
    expect(apiGet).toHaveBeenNthCalledWith(1, '/admin/departments?organization_id=17&page=2');
    expect(apiGet).toHaveBeenNthCalledWith(2, '/admin/departments/4');
    expect(apiGet).toHaveBeenNthCalledWith(3, '/admin/departments/hierarchy');
    expect(apiPost).toHaveBeenCalledWith('/admin/departments', { name: 'Quality', level: 1, organization_id: 17 });
    expect(apiPut).toHaveBeenCalledWith('/admin/departments/4', { name: 'Quality', level: 1 });
    expect(apiDelete).toHaveBeenCalledWith('/admin/departments/4');
  });

  it('renders hierarchy-aware rows and confirms deletion', async () => {
    apiGet.mockResolvedValue({ data: [department], current_page: 1, last_page: 1, per_page: 15, total: 1 });
    apiDelete.mockResolvedValue({ message: 'deleted' });
    vi.spyOn(window, 'confirm').mockReturnValue(true);
    const actor = userEvent.setup();
    setPath('/departments');
    render(<AdminRouter />);
    const row = await screen.findByRole('row', { name: /Quality/ });
    expect(within(row).getByText('Top Management')).toBeInTheDocument();
    await actor.click(within(row).getByRole('button', { name: i18n.t('common.delete') }));
    expect(apiDelete).toHaveBeenCalledWith('/admin/departments/4');
  });

  it('validates create and submits hierarchy fields exactly', async () => {
    apiGet.mockResolvedValueOnce({ data: [], meta: { current_page: 1, last_page: 1, per_page: 100, total: 0 } }).mockResolvedValueOnce({ data: [] });
    apiPost.mockResolvedValue({ department });
    const actor = userEvent.setup();
    setPath('/departments/new');
    render(<AdminRouter />);
    await actor.click(await screen.findByRole('button', { name: i18n.t('common.create') }));
    expect(apiPost).not.toHaveBeenCalled();
    await actor.type(screen.getByLabelText(i18n.t('hr.department_name')), 'Quality');
    await actor.selectOptions(screen.getByLabelText(i18n.t('hr.department_level')), '1');
    await actor.click(screen.getByRole('button', { name: i18n.t('common.create') }));
    await waitFor(() => expect(apiPost).toHaveBeenCalledWith('/admin/departments', {
      name: 'Quality', code: null, description: null, parent_id: null, level: 1, manager_id: null, is_active: true, organization_id: 17,
    }));
  });

  it('reloads parent choices when the selected organization changes', async () => {
    apiGet.mockImplementation((path) => {
      if (path === '/admin/organizations?per_page=100&page=1') return Promise.resolve({ data: [{ id: 17, name: 'North' }, { id: 18, name: 'South' }], meta: { current_page: 1, last_page: 1, per_page: 100, total: 2 } });
      if (path.includes('/admin/departments?organization_id=17')) return Promise.resolve({ data: [{ id: 4, name: 'North Parent' }], current_page: 1, last_page: 1, per_page: 100, total: 1 });
      if (path.includes('/admin/departments?organization_id=18')) return Promise.resolve({ data: [{ id: 8, name: 'South Parent' }], current_page: 1, last_page: 1, per_page: 100, total: 1 });
      if (path.startsWith('/admin/users?')) return Promise.resolve({ data: [], current_page: 1, last_page: 1, per_page: 100, total: 0 });
      return Promise.resolve({ data: [] });
    });
    const actor = userEvent.setup();
    setPath('/departments/new');
    render(<AdminRouter />);

    expect(await screen.findByRole('option', { name: 'North Parent' })).toBeInTheDocument();
    await actor.selectOptions(screen.getByLabelText(i18n.t('admin.organizations.title')), '18');

    expect(await screen.findByRole('option', { name: 'South Parent' })).toBeInTheDocument();
    expect(screen.queryByRole('option', { name: 'North Parent' })).not.toBeInTheDocument();
    expect(apiGet).toHaveBeenCalledWith('/admin/departments?organization_id=18&per_page=100&page=1');
  });

  it('renders view/edit routes and exposes cross-organization failures', async () => {
    apiGet.mockResolvedValue(department);
    setPath('/departments/4');
    render(<AdminRouter />);
    expect(await screen.findByText('Quality')).toBeInTheDocument();
    expect(screen.getByText('Audits')).toBeInTheDocument();

    apiGet.mockReset();
    apiGet.mockRejectedValue({ message: 'غير مصرح بالوصول إلى هذا القسم' });
    setPath('/departments/99/edit');
    render(<AdminRouter />);
    expect(await screen.findByRole('alert')).toHaveTextContent('غير مصرح بالوصول إلى هذا القسم');
  });

  it('loads the selected organization from the query and filters the department list', async () => {
    apiGet.mockImplementation((path) => {
      if (path === '/admin/organizations?per_page=100&page=1') return Promise.resolve({ data: [{ id: 17, name: 'North' }, { id: 18, name: 'South' }], meta: { current_page: 1, last_page: 1, per_page: 100, total: 2 } });
      if (path.includes('organization_id=18')) return Promise.resolve({ data: [{ ...department, id: 8, organization_id: 18, name: 'South Quality' }], current_page: 1, last_page: 1, per_page: 20, total: 1 });
      return Promise.resolve({ data: [], current_page: 1, last_page: 1, per_page: 20, total: 0 });
    });
    setPath('/departments?organization_id=18');
    render(<AdminRouter />);

    expect(await screen.findByText('South Quality')).toBeInTheDocument();
    expect(screen.getByLabelText(i18n.t('admin.organizations.title'))).toHaveValue('18');
    expect(apiGet).toHaveBeenCalledWith('/admin/departments?organization_id=18&page=1&per_page=20');
  });

  it('scopes manager choices to the selected organization and preserves it after create', async () => {
    apiGet.mockImplementation((path) => {
      if (path === '/admin/organizations?per_page=100&page=1') return Promise.resolve({ data: [{ id: 17, name: 'North' }, { id: 18, name: 'South' }], meta: { current_page: 1, last_page: 1, per_page: 100, total: 2 } });
      if (path.includes('/admin/departments?organization_id=17')) return Promise.resolve({ data: [], current_page: 1, last_page: 1, per_page: 100, total: 0 });
      if (path.includes('/admin/users?organization_id=17')) return Promise.resolve({ data: [{ id: 9, name: 'North Manager' }], current_page: 1, last_page: 1, per_page: 100, total: 1 });
      return Promise.resolve({ data: [] });
    });
    apiPost.mockResolvedValue({ department });
    const actor = userEvent.setup();
    setPath('/departments/new?organization_id=17');
    render(<AdminRouter />);

    await actor.type(await screen.findByLabelText(i18n.t('hr.department_name')), 'Managed Department');
    await actor.selectOptions(screen.getByLabelText(i18n.t('hr.department_manager')), '9');
    await actor.click(screen.getByRole('button', { name: i18n.t('common.create') }));

    await waitFor(() => expect(apiPost).toHaveBeenCalledWith('/admin/departments', expect.objectContaining({ organization_id: 17, manager_id: 9 })));
    expect(window.location.pathname).toBe('/departments');
    expect(window.location.search).toBe('?organization_id=17');
  });

  it('ignores a stale parent response after switching organizations', async () => {
    let resolveNorth!: (value: unknown) => void;
    apiGet.mockImplementation((path) => {
      if (path === '/admin/organizations?per_page=100&page=1') return Promise.resolve({ data: [{ id: 17, name: 'North' }, { id: 18, name: 'South' }], meta: { current_page: 1, last_page: 1, per_page: 100, total: 2 } });
      if (path.includes('/admin/departments?organization_id=17')) return new Promise((resolve) => { resolveNorth = resolve; });
      if (path.includes('/admin/users?organization_id=17')) return Promise.resolve({ data: [], current_page: 1, last_page: 1, per_page: 100, total: 0 });
      if (path.includes('/admin/departments?organization_id=18')) return Promise.resolve({ data: [{ id: 8, name: 'South Parent' }], current_page: 1, last_page: 1, per_page: 100, total: 1 });
      if (path.includes('/admin/users?organization_id=18')) return Promise.resolve({ data: [], current_page: 1, last_page: 1, per_page: 100, total: 0 });
      return Promise.resolve({ data: [] });
    });
    const actor = userEvent.setup();
    setPath('/departments/new?organization_id=17');
    render(<AdminRouter />);

    await actor.selectOptions(await screen.findByLabelText(i18n.t('admin.organizations.title')), '18');
    expect(await screen.findByRole('option', { name: 'South Parent' })).toBeInTheDocument();
    resolveNorth({ data: [{ id: 4, name: 'North Parent' }], current_page: 1, last_page: 1, per_page: 100, total: 1 });

    await waitFor(() => expect(screen.queryByRole('option', { name: 'North Parent' })).not.toBeInTheDocument());
    expect(screen.getByRole('option', { name: 'South Parent' })).toBeInTheDocument();
  });
});
