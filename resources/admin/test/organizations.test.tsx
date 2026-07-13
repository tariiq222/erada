import React from 'react';
import userEvent from '@testing-library/user-event';
import { render, screen, waitFor } from '@testing-library/react';
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
  } satisfies User,
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
  api: {
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
    blob: vi.fn(),
  },
}));

const apiGet = vi.mocked(api.get);
const apiPost = vi.mocked(api.post);
const apiPut = vi.mocked(api.put);
const apiDelete = vi.mocked(api.delete);

const organization = {
  id: 7,
  name: 'North Health Cluster',
  code: 'NHC',
  type: 'cluster' as const,
  parent_id: null,
  sort_order: 1,
  description: null,
  email: 'contact@north.test',
  phone: null,
  address: null,
  website: null,
  logo: null,
  is_active: true,
  is_root: true,
  can_have_children: true,
  allowed_child_types: ['organization'] as const,
  children_count: 0,
  parent: null,
};

const listResponse = {
  data: [organization],
  meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
};

function setPath(path: string) {
  window.history.replaceState({ usr: null, key: 'organizations-test', idx: 0 }, '', path);
}

describe('admin organization contracts', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    document.documentElement.dir = 'rtl';
  });

  it('uses canonical organization adapters through the shared client', async () => {
    apiGet.mockResolvedValue(listResponse);
    apiPost.mockResolvedValue({ data: organization });
    apiPut.mockResolvedValue({ data: organization });
    apiDelete.mockResolvedValue({ message: 'deleted' });

    await adminApi.organizations.list({ search: 'north', page: 2 });
    await adminApi.organizations.get(7);
    await adminApi.organizations.create({ name: 'North', code: 'NORTH' });
    await adminApi.organizations.update(7, { name: 'North Updated' });
    await adminApi.organizations.delete(7);

    expect(apiGet).toHaveBeenNthCalledWith(1, '/organizations?search=north&page=2');
    expect(apiGet).toHaveBeenNthCalledWith(2, '/organizations/7');
    expect(apiPost).toHaveBeenCalledWith('/organizations', { name: 'North', code: 'NORTH' });
    expect(apiPut).toHaveBeenCalledWith('/organizations/7', { name: 'North Updated' });
    expect(apiDelete).toHaveBeenCalledWith('/organizations/7');
  });

  it('renders the organization list and confirms deletion before mutating', async () => {
    apiGet.mockResolvedValue(listResponse);
    apiDelete.mockResolvedValue({ message: 'deleted' });
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true);
    const user = userEvent.setup();
    setPath('/organizations');

    render(<AdminRouter />);

    expect(await screen.findByText('North Health Cluster')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: i18n.t('common.view') })).toHaveAttribute('href', '/organizations/7');
    await user.click(screen.getByRole('button', { name: i18n.t('common.delete') }));

    expect(confirm).toHaveBeenCalledWith(i18n.t('admin.organizations.confirmDelete'));
    expect(apiDelete).toHaveBeenCalledWith('/organizations/7');
  });

  it('blocks an invalid create and submits exact FormRequest field names', async () => {
    apiGet.mockResolvedValue({ data: [], meta: { current_page: 1, last_page: 1, per_page: 100, total: 0 } });
    apiPost.mockResolvedValue({ data: organization });
    const user = userEvent.setup();
    setPath('/organizations/new');

    render(<AdminRouter />);

    await user.click(await screen.findByRole('button', { name: i18n.t('common.create') }));
    await waitFor(() => expect(screen.getByLabelText(i18n.t('admin.organizations.fields.name'))).toHaveAttribute('aria-invalid', 'true'));
    expect(apiPost).not.toHaveBeenCalled();

    await user.type(screen.getByLabelText(i18n.t('admin.organizations.fields.name')), 'New Organization');
    await user.type(screen.getByLabelText(i18n.t('admin.organizations.fields.code')), 'NEW-ORG');
    await user.click(screen.getByRole('button', { name: i18n.t('common.create') }));

    await waitFor(() => expect(apiPost).toHaveBeenCalledWith('/organizations', expect.objectContaining({
      name: 'New Organization',
      code: 'NEW-ORG',
      type: 'organization',
      parent_id: null,
      is_active: true,
      sort_order: 0,
    })));
  });

  it('loads and updates an organization with backend validation errors visible', async () => {
    apiGet.mockResolvedValueOnce({ data: organization }).mockResolvedValueOnce(listResponse);
    apiPut.mockRejectedValue({ message: 'Validation failed', errors: { code: ['Code already exists'] } });
    const user = userEvent.setup();
    setPath('/organizations/7/edit');

    render(<AdminRouter />);

    const name = await screen.findByDisplayValue('North Health Cluster');
    await user.clear(name);
    await user.type(name, 'North Cluster Updated');
    await user.click(screen.getByRole('button', { name: i18n.t('common.save_changes') }));

    expect(await screen.findByRole('alert')).toHaveTextContent('Code already exists');
    expect(apiPut).toHaveBeenCalledWith('/organizations/7', expect.objectContaining({
      name: 'North Cluster Updated',
      code: 'NHC',
    }));
  });

  it('renders an organization view without exposing edit controls', async () => {
    apiGet.mockResolvedValue({ data: { ...organization, users_count: 12, projects_count: 4 } });
    setPath('/organizations/7');

    render(<AdminRouter />);

    expect(await screen.findByText('North Health Cluster')).toBeInTheDocument();
    expect(screen.getAllByText(i18n.t('admin.organizationDetails.users')).length).toBeGreaterThan(0);
    expect(screen.getAllByText(i18n.t('admin.organizationDetails.projects')).length).toBeGreaterThan(0);
    expect(screen.getByText('12')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: i18n.t('common.save_changes') })).not.toBeInTheDocument();
  });
});
