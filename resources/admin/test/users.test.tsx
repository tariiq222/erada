import React from 'react';
import userEvent from '@testing-library/user-event';
import { render, screen, waitFor, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { User } from '@shared/types';
import i18n from '@shared/config/i18n';
import { api } from '@shared/api/client';
import { AdminRouter } from '@admin/app/AdminRouter';
import { adminApi } from '@admin/api/adminApi';
import { operationalUrl } from '@admin/config/links';

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
vi.mock('@shared/ui/Toast', () => ({ ToastProvider: ({ children }: { children: React.ReactNode }) => children }));
vi.mock('@shared/api/client', () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn(), blob: vi.fn() },
}));

const apiGet = vi.mocked(api.get);
const apiPost = vi.mocked(api.post);
const apiPut = vi.mocked(api.put);
const apiDelete = vi.mocked(api.delete);

const userRecord = {
  id: 9,
  name: 'Audit User',
  email: 'audit@example.test',
  phone: '0500000000',
  extension: '123',
  job_title: 'Auditor',
  department_id: 4,
  department: { id: 4, name: 'Quality' },
  organization_id: 17,
  is_active: true,
  roles: ['viewer'],
  permissions: ['users.view'],
  created_at: '2026-07-01 10:00:00',
};

function setPath(path: string) {
  window.history.replaceState({ usr: null, key: 'users-test', idx: 0 }, '', path);
}

describe('admin user contracts', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    document.documentElement.dir = 'rtl';
    vi.unstubAllEnvs();
  });

  it('uses canonical user adapters through the shared client', async () => {
    apiGet.mockResolvedValue({ data: [] });
    apiPost.mockResolvedValue({ user: userRecord });
    apiPut.mockResolvedValue({ user: userRecord });
    apiDelete.mockResolvedValue({ message: 'deleted' });

    await adminApi.users.list({ search: 'audit', page: 2 });
    await adminApi.users.get(9);
    await adminApi.users.security(9);
    await adminApi.users.create({ name: 'Audit User', email: 'audit@example.test', password: 'Secret123!' });
    await adminApi.users.update(9, { name: 'Updated User' });
    await adminApi.users.unlock(9);
    await adminApi.users.delete(9);

    expect(apiGet).toHaveBeenNthCalledWith(1, '/users?search=audit&page=2');
    expect(apiGet).toHaveBeenNthCalledWith(2, '/users/9');
    expect(apiGet).toHaveBeenNthCalledWith(3, '/users/9/security');
    expect(apiPost).toHaveBeenNthCalledWith(1, '/users', expect.objectContaining({ email: 'audit@example.test' }));
    expect(apiPut).toHaveBeenCalledWith('/users/9', { name: 'Updated User' });
    expect(apiPost).toHaveBeenNthCalledWith(2, '/users/9/unlock', undefined);
    expect(apiDelete).toHaveBeenCalledWith('/users/9');
  });

  it('loads every organization and manager-candidate page through canonical filters', async () => {
    apiGet
      .mockResolvedValueOnce({ data: [{ id: 17, name: 'North' }], meta: { current_page: 1, last_page: 2, per_page: 100, total: 2 } })
      .mockResolvedValueOnce({ data: [{ id: 18, name: 'South' }], meta: { current_page: 2, last_page: 2, per_page: 100, total: 2 } })
      .mockResolvedValueOnce({ data: [{ id: 9, name: 'First Manager' }], current_page: 1, last_page: 2, per_page: 100, total: 2 })
      .mockResolvedValueOnce({ data: [{ id: 10, name: 'Second Manager' }], current_page: 2, last_page: 2, per_page: 100, total: 2 });

    const organizations = await adminApi.organizations.all();
    const managers = await adminApi.users.all(17);

    expect(organizations.data.map((row) => row.id)).toEqual([17, 18]);
    expect(managers.data.map((row) => row.id)).toEqual([9, 10]);
    expect(apiGet).toHaveBeenNthCalledWith(1, '/organizations?per_page=100&page=1');
    expect(apiGet).toHaveBeenNthCalledWith(2, '/organizations?per_page=100&page=2');
    expect(apiGet).toHaveBeenNthCalledWith(3, '/users?organization_id=17&per_page=100&page=1');
    expect(apiGet).toHaveBeenNthCalledWith(4, '/users?organization_id=17&per_page=100&page=2');
  });

  it('requires a trusted operational origin and rejects unsafe record paths', () => {
    vi.stubEnv('VITE_OPERATIONAL_URL', 'https://operations.example.test/app/');
    expect(operationalUrl('/projects/42')).toBe('https://operations.example.test/projects/42');
    expect(() => operationalUrl('https://evil.example/projects/42')).toThrow();
    expect(() => operationalUrl('//evil.example/projects/42')).toThrow();

    vi.stubEnv('VITE_OPERATIONAL_URL', 'javascript:alert(1)');
    expect(() => operationalUrl('/projects/42')).toThrow();
    vi.stubEnv('VITE_OPERATIONAL_URL', '');
    expect(() => operationalUrl('/projects/42')).toThrow('VITE_OPERATIONAL_URL');
  });

  it('renders the list and requires confirmation before deletion', async () => {
    apiGet.mockResolvedValue({ data: [userRecord], current_page: 1, last_page: 1, per_page: 15, total: 1 });
    apiDelete.mockResolvedValue({ message: 'deleted' });
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true);
    const actor = userEvent.setup();
    setPath('/users');

    render(<AdminRouter />);

    const row = await screen.findByRole('row', { name: /Audit User/ });
    expect(within(row).getByRole('link', { name: i18n.t('common.view') })).toHaveAttribute('href', '/users/9');
    await actor.click(within(row).getByRole('button', { name: i18n.t('common.delete') }));
    expect(confirm).toHaveBeenCalledWith(i18n.t('users.delete_confirm'));
    expect(apiDelete).toHaveBeenCalledWith('/users/9');
  });

  it('validates create fields and submits the exact FormRequest contract', async () => {
    apiGet
      .mockResolvedValueOnce({ data: [], meta: { current_page: 1, last_page: 1, per_page: 100, total: 0 } })
      .mockResolvedValueOnce({ data: [], meta: { total: 0 } })
      .mockResolvedValueOnce({ data: [{ id: 4, name: 'Quality' }] });
    apiPost.mockResolvedValue({ user: userRecord });
    const actor = userEvent.setup();
    setPath('/users/new');

    render(<AdminRouter />);

    await actor.click(await screen.findByRole('button', { name: i18n.t('common.create') }));
    expect(apiPost).not.toHaveBeenCalled();
    expect(screen.getByLabelText(i18n.t('common.name'))).toHaveAttribute('aria-invalid', 'true');

    await actor.type(screen.getByLabelText(i18n.t('common.name')), 'Audit User');
    await actor.type(screen.getByLabelText(i18n.t('common.email')), 'audit@example.test');
    await actor.type(screen.getByLabelText(i18n.t('users.password')), 'Secret123!');
    await actor.selectOptions(screen.getByLabelText(i18n.t('common.department')), '4');
    await actor.click(screen.getByRole('button', { name: i18n.t('common.create') }));

    await waitFor(() => expect(apiPost).toHaveBeenCalledWith('/users', {
      name: 'Audit User',
      email: 'audit@example.test',
      password: 'Secret123!',
      organization_id: 17,
      department_id: 4,
      phone: null,
      extension: null,
      job_title: null,
      is_active: true,
      roles: [],
    }));
  });

  it('renders user details, security status, and cross-organization failures', async () => {
    apiGet
      .mockResolvedValueOnce(userRecord)
      .mockResolvedValueOnce({ security: { is_locked: true, failed_attempts: 5, locked_until: '2026-07-12', last_failed_login: null, last_login: '2026-07-10', last_login_ip: '127.0.0.1' } });
    setPath('/users/9');
    render(<AdminRouter />);

    expect(await screen.findByText('Audit User')).toBeInTheDocument();
    expect(screen.getByText(i18n.t('users.security'))).toBeInTheDocument();
    expect(screen.getByText('5')).toBeInTheDocument();

    apiGet.mockReset();
    apiGet.mockRejectedValue({ message: 'Cross-organization access denied' });
    setPath('/users/99');
    render(<AdminRouter />);
    expect(await screen.findByRole('alert')).toHaveTextContent('Cross-organization access denied');
  });

  it('keeps organization immutable and preserves an existing super-admin role on edit', async () => {
    const superAdminRecord = { ...userRecord, roles: ['super_admin'], organization_id: 17 };
    apiGet
      .mockResolvedValueOnce({ data: [{ id: 17, name: 'North Health Cluster' }], meta: { current_page: 1, last_page: 1, per_page: 100, total: 1 } })
      .mockResolvedValueOnce({ data: [], meta: { total: 0 } })
      .mockResolvedValueOnce(superAdminRecord)
      .mockResolvedValueOnce({ data: [{ id: 4, name: 'Quality' }], current_page: 1, last_page: 1, per_page: 100, total: 1 });
    apiPut.mockResolvedValue({ user: superAdminRecord });
    const actor = userEvent.setup();
    setPath('/users/9/edit');

    render(<AdminRouter />);

    const organization = await screen.findByLabelText(i18n.t('admin.organizations.title'));
    expect(organization).toBeDisabled();
    expect(screen.getByText(i18n.t('admin.users.superAdminLocked'))).toBeInTheDocument();
    await actor.click(screen.getByRole('button', { name: i18n.t('common.save_changes') }));

    await waitFor(() => expect(apiPut).toHaveBeenCalled());
    const payload = apiPut.mock.calls[0]?.[1] as Record<string, unknown>;
    expect(payload).not.toHaveProperty('organization_id');
    expect(payload).not.toHaveProperty('roles');
  });

  it('filters the user list by the selected organization', async () => {
    apiGet.mockImplementation((path) => {
      if (path === '/organizations?per_page=100&page=1') return Promise.resolve({ data: [{ id: 17, name: 'North' }, { id: 18, name: 'South' }], meta: { current_page: 1, last_page: 1, per_page: 100, total: 2 } });
      if (path.includes('organization_id=18')) return Promise.resolve({ data: [{ ...userRecord, organization_id: 18 }], current_page: 1, last_page: 1, per_page: 20, total: 1 });
      return Promise.resolve({ data: [], current_page: 1, last_page: 1, per_page: 20, total: 0 });
    });
    const actor = userEvent.setup();
    setPath('/users');
    render(<AdminRouter />);

    const organization = await screen.findByLabelText(i18n.t('admin.organizations.title'));
    await actor.selectOptions(organization, '18');

    await waitFor(() => expect(apiGet).toHaveBeenCalledWith('/users?organization_id=18&page=1&per_page=20'));
  });

  it('keeps department choices from the latest organization request', async () => {
    let resolveNorth!: (value: unknown) => void;
    apiGet.mockImplementation((path) => {
      if (path === '/organizations?per_page=100&page=1') return Promise.resolve({ data: [{ id: 17, name: 'North' }, { id: 18, name: 'South' }], meta: { current_page: 1, last_page: 1, per_page: 100, total: 2 } });
      if (path === '/roles') return Promise.resolve({ data: [] });
      if (path.includes('organization_id=17')) return new Promise((resolve) => { resolveNorth = resolve; });
      if (path.includes('organization_id=18')) return Promise.resolve({ data: [{ id: 8, name: 'South Department' }], current_page: 1, last_page: 1, per_page: 100, total: 1 });
      return Promise.resolve({ data: [] });
    });
    const actor = userEvent.setup();
    setPath('/users/new');
    render(<AdminRouter />);

    const organization = await screen.findByLabelText(i18n.t('admin.organizations.title'));
    await actor.selectOptions(organization, '18');
    expect(screen.queryByRole('option', { name: 'North Department' })).not.toBeInTheDocument();
    expect(await screen.findByRole('option', { name: 'South Department' })).toBeInTheDocument();

    resolveNorth({ data: [{ id: 4, name: 'North Department' }], current_page: 1, last_page: 1, per_page: 100, total: 1 });
    await waitFor(() => expect(screen.queryByRole('option', { name: 'North Department' })).not.toBeInTheDocument());
    expect(screen.getByRole('option', { name: 'South Department' })).toBeInTheDocument();
  });

  it('keeps the latest user rows when an older request resolves last', async () => {
    let resolveNorth!: (value: unknown) => void;
    apiGet.mockImplementation((path) => {
      if (path === '/organizations?per_page=100&page=1') return Promise.resolve({ data: [{ id: 17, name: 'North' }, { id: 18, name: 'South' }], meta: { current_page: 1, last_page: 1, per_page: 100, total: 2 } });
      if (path.includes('organization_id=17')) return new Promise((resolve) => { resolveNorth = resolve; });
      if (path.includes('organization_id=18')) return Promise.resolve({ data: [{ ...userRecord, id: 18, name: 'South User', organization_id: 18 }], current_page: 1, last_page: 1, per_page: 20, total: 1 });
      return Promise.resolve({ data: [] });
    });
    const actor = userEvent.setup();
    setPath('/users');
    render(<AdminRouter />);

    await actor.selectOptions(await screen.findByLabelText(i18n.t('admin.organizations.title')), '18');
    const southRow = await screen.findByRole('row', { name: /South User/ });
    expect(within(southRow).getByRole('link', { name: i18n.t('common.view') })).toHaveAttribute('href', '/users/18');

    resolveNorth({ data: [{ ...userRecord, id: 17, name: 'North User' }], current_page: 1, last_page: 1, per_page: 20, total: 1 });
    await waitFor(() => expect(screen.queryByText('North User')).not.toBeInTheDocument());
    expect(screen.getByText('South User')).toBeInTheDocument();
  });

  it('does not let an older user-list failure replace a successful latest load', async () => {
    let rejectNorth!: (reason: unknown) => void;
    apiGet.mockImplementation((path) => {
      if (path === '/organizations?per_page=100&page=1') return Promise.resolve({ data: [{ id: 17, name: 'North' }, { id: 18, name: 'South' }], meta: { current_page: 1, last_page: 1, per_page: 100, total: 2 } });
      if (path.includes('organization_id=17')) return new Promise((_resolve, reject) => { rejectNorth = reject; });
      if (path.includes('organization_id=18')) return Promise.resolve({ data: [{ ...userRecord, id: 18, name: 'South User', organization_id: 18 }], current_page: 1, last_page: 1, per_page: 20, total: 1 });
      return Promise.resolve({ data: [] });
    });
    const actor = userEvent.setup();
    setPath('/users');
    render(<AdminRouter />);

    await actor.selectOptions(await screen.findByLabelText(i18n.t('admin.organizations.title')), '18');
    expect(await screen.findByText('South User')).toBeInTheDocument();
    rejectNorth({ message: 'stale north failure' });

    await waitFor(() => expect(screen.queryByText('stale north failure')).not.toBeInTheDocument());
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    expect(screen.getByText('South User')).toBeInTheDocument();
  });

  it('has mirrored translations for security controls', () => {
    for (const key of ['admin.users.failedAttempts', 'admin.users.unlock', 'admin.users.superAdminLocked']) {
      expect(i18n.getResource('ar', 'translation', key)).toBeTruthy();
      expect(i18n.getResource('en', 'translation', key)).toBeTruthy();
    }
  });
});
