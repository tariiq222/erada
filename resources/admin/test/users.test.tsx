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

    expect(apiGet).toHaveBeenNthCalledWith(1, '/admin/users?search=audit&page=2');
    expect(apiGet).toHaveBeenNthCalledWith(2, '/admin/users/9');
    expect(apiGet).toHaveBeenNthCalledWith(3, '/admin/users/9/security');
    expect(apiPost).toHaveBeenNthCalledWith(1, '/admin/users', expect.objectContaining({ email: 'audit@example.test' }));
    expect(apiPut).toHaveBeenCalledWith('/admin/users/9', { name: 'Updated User' });
    expect(apiPost).toHaveBeenNthCalledWith(2, '/admin/users/9/unlock', undefined);
    expect(apiDelete).toHaveBeenCalledWith('/admin/users/9');
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
    expect(apiDelete).toHaveBeenCalledWith('/admin/users/9');
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

    await waitFor(() => expect(apiPost).toHaveBeenCalledWith('/admin/users', {
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
});
