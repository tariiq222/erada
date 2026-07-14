import React from 'react';
import userEvent from '@testing-library/user-event';
import { render, screen, waitFor, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { User } from '@shared/types';
import i18n from '@shared/config/i18n';
import { api } from '@shared/api/client';
import { AdminRouter } from '@admin/app/AdminRouter';
import {
  canExportActivityLogs,
  canMutateTargetLifecycle,
  isProtectedAdminTarget,
} from '@admin/model/adminPredicates';

/**
 * Shared test fixture: the user payload that the admin SPA mirrors from
 * `/api/user`. The tests below mutate the `roles` / flags per case.
 */
function buildActor(overrides: Partial<User & { organization_id?: number }> = {}) {
  return {
    id: 7,
    name: 'Org Super',
    email: 'orgsuper@example.test',
    department_id: null,
    phone: null,
    extension: null,
    job_title: null,
    is_active: true,
    is_super_admin: false,
    is_org_admin: false,
    is_organization_super_admin: true,
    organization_id: 42,
    ...overrides,
  } as unknown as User;
}

const authState: { user: User | null } = { user: null };

vi.mock('@shared/contexts/AuthContext', () => ({
  AuthProvider: ({ children }: { children: React.ReactNode }) => children,
  useAuth: () => ({ ...authState, isLoading: false, isAuthenticated: authState.user !== null, logout: vi.fn(), refreshUser: vi.fn() }),
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

const ordinarySameOrgUser = {
  id: 9,
  name: 'Same Org',
  email: 'same@example.test',
  phone: '0500000000',
  extension: '123',
  job_title: 'Auditor',
  department_id: 4,
  department: { id: 4, name: 'Quality' },
  organization_id: 42,
  is_active: true,
  roles: ['viewer'],
  permissions: ['users.view'],
  created_at: '2026-07-01 10:00:00',
};

const crossOrgUser = {
  ...ordinarySameOrgUser,
  id: 11,
  name: 'Other Org',
  email: 'other@example.test',
  organization_id: 43,
};

const platformSuperTarget = {
  ...ordinarySameOrgUser,
  id: 12,
  name: 'Platform Super',
  email: 'platform@example.test',
  roles: ['super_admin'],
};

const orgSuperTarget = {
  ...ordinarySameOrgUser,
  id: 13,
  name: 'Org Super Target',
  email: 'orgsuper-target@example.test',
  roles: ['organization_super_admin'],
};

function setPath(path: string) {
  window.history.replaceState({ usr: null, key: 'orgsuper-users-test', idx: 0 }, '', path);
}

describe('OrgSuper users lifecycle slice', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    document.documentElement.dir = 'rtl';
    authState.user = buildActor();
  });

  it('exposes the predicate that hides OrgSuper lifecycle actions on protected targets', () => {
    expect(isProtectedAdminTarget(platformSuperTarget)).toBe(true);
    expect(isProtectedAdminTarget(orgSuperTarget)).toBe(true);
    expect(isProtectedAdminTarget(ordinarySameOrgUser)).toBe(false);
    expect(
      canMutateTargetLifecycle(
        { is_organization_super_admin: true, organization_id: 42 },
        ordinarySameOrgUser,
      ),
    ).toBe(true);
    expect(
      canMutateTargetLifecycle(
        { is_organization_super_admin: true, organization_id: 42 },
        platformSuperTarget,
      ),
    ).toBe(false);
    expect(
      canMutateTargetLifecycle(
        { is_organization_super_admin: true, organization_id: 42 },
        crossOrgUser,
      ),
    ).toBe(false);
  });

  it('hides the platform-audit export behind the super-admin gate', () => {
    expect(canExportActivityLogs({ is_super_admin: true })).toBe(true);
    expect(
      canExportActivityLogs({ is_super_admin: false, is_organization_super_admin: true }),
    ).toBe(false);
  });

  it('hides the organization selector and locks the list to the OrgSuper actor org', async () => {
    apiGet.mockResolvedValue({ data: [ordinarySameOrgUser], current_page: 1, last_page: 1, per_page: 15, total: 1 });
    setPath('/users');

    render(<AdminRouter />);

    await screen.findByText('Same Org');
    // OrgSuper never fetches the cross-tenant organization catalog.
    const organizationPaths = apiGet.mock.calls
      .map(([path]) => path)
      .filter((path): path is string => typeof path === 'string')
      .filter((path) => path.startsWith('/organizations'));
    expect(organizationPaths).toEqual([]);
    // The list is pinned to actor org 42 — `organization_id=42` must appear
    // in the users call, and the actor's organization selector is hidden.
    expect(apiGet).toHaveBeenCalledWith('/users?organization_id=42&page=1&per_page=20');
    expect(screen.queryByLabelText(i18n.t('admin.organizations.title'))).not.toBeInTheDocument();
  });

  it('shows activate / deactivate / delete for ordinary same-org users and hides them on protected admins', async () => {
    apiGet.mockImplementation((path) => {
      if (path.includes('/users?organization_id=42')) {
        return Promise.resolve({
          data: [ordinarySameOrgUser, platformSuperTarget, orgSuperTarget],
          current_page: 1,
          last_page: 1,
          per_page: 15,
          total: 3,
        });
      }
      return Promise.resolve({ data: [], current_page: 1, last_page: 1, per_page: 15, total: 0 });
    });
    setPath('/users');

    render(<AdminRouter />);

    const ordinaryRow = await screen.findByRole('row', { name: /Same Org/ });
    expect(within(ordinaryRow).getByRole('button', { name: i18n.t('common.delete') })).toBeInTheDocument();
    expect(
      within(ordinaryRow).getByRole('button', { name: i18n.t('users.deactivate') }),
    ).toBeInTheDocument();

    const platformRow = screen.getByRole('row', { name: /Platform Super/ });
    expect(
      within(platformRow).queryByRole('button', { name: i18n.t('common.delete') }),
    ).not.toBeInTheDocument();
    expect(
      within(platformRow).queryByRole('button', { name: i18n.t('users.deactivate') }),
    ).not.toBeInTheDocument();
    expect(
      within(platformRow).queryByRole('button', { name: i18n.t('users.activate') }),
    ).not.toBeInTheDocument();

    const orgSuperRow = screen.getByRole('row', { name: /Org Super Target/ });
    expect(
      within(orgSuperRow).queryByRole('button', { name: i18n.t('common.delete') }),
    ).not.toBeInTheDocument();
    expect(
      within(orgSuperRow).queryByRole('button', { name: i18n.t('users.activate') }),
    ).not.toBeInTheDocument();
  });

  it('shows a cross-org row but offers no lifecycle controls on it', async () => {
    apiGet.mockImplementation((path) => {
      if (path.includes('/users?organization_id=42')) {
        return Promise.resolve({
          data: [crossOrgUser],
          current_page: 1,
          last_page: 1,
          per_page: 15,
          total: 1,
        });
      }
      return Promise.resolve({ data: [], current_page: 1, last_page: 1, per_page: 15, total: 0 });
    });
    setPath('/users');

    render(<AdminRouter />);

    const row = await screen.findByRole('row', { name: /Other Org/ });
    expect(
      within(row).queryByRole('button', { name: i18n.t('common.delete') }),
    ).not.toBeInTheDocument();
    expect(
      within(row).queryByRole('button', { name: i18n.t('users.activate') }),
    ).not.toBeInTheDocument();
    expect(
      within(row).queryByRole('button', { name: i18n.t('users.deactivate') }),
    ).not.toBeInTheDocument();
  });

  it('toggles is_active through adminApi.users.update when OrgSuper activates a same-org user', async () => {
    const inactiveSameOrg = { ...ordinarySameOrgUser, is_active: false };
    apiGet.mockResolvedValue({
      data: [inactiveSameOrg],
      current_page: 1,
      last_page: 1,
      per_page: 15,
      total: 1,
    });
    apiPut.mockResolvedValue({ user: { ...inactiveSameOrg, is_active: true } });
    setPath('/users');

    render(<AdminRouter />);

    const row = await screen.findByRole('row', { name: /Same Org/ });
    await userEvent.setup().click(
      within(row).getByRole('button', { name: i18n.t('users.activate') }),
    );

    await waitFor(() =>
      expect(apiPut).toHaveBeenCalledWith('/users/9', { is_active: true }),
    );
  });

  it('shows a targeted error when the activate mutation fails and never mutates the row', async () => {
    apiGet.mockResolvedValue({
      data: [{ ...ordinarySameOrgUser, is_active: false }],
      current_page: 1,
      last_page: 1,
      per_page: 15,
      total: 1,
    });
    apiPut.mockRejectedValue({ message: 'Forbidden target' });
    setPath('/users');

    render(<AdminRouter />);

    const row = await screen.findByRole('row', { name: /Same Org/ });
    await userEvent.setup().click(
      within(row).getByRole('button', { name: i18n.t('users.activate') }),
    );

    expect(await screen.findByRole('alert')).toHaveTextContent('Forbidden target');
  });

  it('locks the create form to the OrgSuper actor organization and omits the selector', async () => {
    apiGet
      .mockResolvedValueOnce({ data: [], meta: { total: 0 } })
      .mockResolvedValueOnce({ data: [{ id: 4, name: 'Quality' }], current_page: 1, last_page: 1, per_page: 100, total: 1 });
    setPath('/users/new');

    render(<AdminRouter />);

    await screen.findByLabelText(i18n.t('common.name'));
    expect(
      screen.queryByLabelText(i18n.t('admin.organizations.title')),
    ).not.toBeInTheDocument();
    // The departments list is filtered by the actor's organization.
    expect(apiGet).toHaveBeenCalledWith('/admin/departments?organization_id=42&per_page=100&page=1');
  });

  it('locks the edit form organization to the OrgSuper actor even when the target row carries a different org', async () => {
    authState.user = buildActor({ organization_id: 42 });
    const target = { ...ordinarySameOrgUser, organization_id: 42, roles: ['viewer'] };
    apiGet
      .mockResolvedValueOnce({ data: [], meta: { total: 0 } })
      .mockResolvedValueOnce(target)
      .mockResolvedValueOnce({ data: [{ id: 4, name: 'Quality' }], current_page: 1, last_page: 1, per_page: 100, total: 1 });
    setPath('/users/9/edit');

    render(<AdminRouter />);

    await screen.findByDisplayValue('Same Org');
    expect(
      screen.queryByLabelText(i18n.t('admin.organizations.title')),
    ).not.toBeInTheDocument();
  });

  it('preserves the super-admin path with the organization selector and full lifecycle controls', async () => {
    authState.user = buildActor({ is_super_admin: true, is_organization_super_admin: false });
    apiGet.mockImplementation((path) => {
      if (path === '/organizations?per_page=100&page=1') {
        return Promise.resolve({
          data: [{ id: 42, name: 'North' }, { id: 43, name: 'South' }],
          meta: { current_page: 1, last_page: 1, per_page: 100, total: 2 },
        });
      }
      if (path.includes('/users?organization_id=42') || path.includes('organization_id=')) {
        return Promise.resolve({
          data: [ordinarySameOrgUser],
          current_page: 1,
          last_page: 1,
          per_page: 15,
          total: 1,
        });
      }
      return Promise.resolve({ data: [], current_page: 1, last_page: 1, per_page: 15, total: 0 });
    });
    setPath('/users');

    render(<AdminRouter />);

    expect(await screen.findByLabelText(i18n.t('admin.organizations.title'))).toBeInTheDocument();
    const row = await screen.findByRole('row', { name: /Same Org/ });
    expect(within(row).getByRole('button', { name: i18n.t('common.delete') })).toBeInTheDocument();
  });

  it('exposes mirrored translations for the OrgSuper lifecycle strings', () => {
    for (const key of [
      'admin.users.protectedTarget',
      'admin.users.protectedTargetDetail',
      'admin.users.unlockLockedActor',
      'users.activate_success',
      'users.deactivate_success',
    ]) {
      expect(i18n.getResource('ar', 'translation', key)).toBeTruthy();
      expect(i18n.getResource('en', 'translation', key)).toBeTruthy();
    }
  });

  it('exposes activate/deactivate/unlock/delete on the details page for ordinary same-org users', async () => {
    apiGet
      .mockResolvedValueOnce(ordinarySameOrgUser)
      .mockResolvedValueOnce({
        security: { is_locked: true, failed_attempts: 5, locked_until: '2026-07-12', last_failed_login: null, last_login: '2026-07-10', last_login_ip: '127.0.0.1' },
      });
    setPath('/users/9');

    render(<AdminRouter />);

    expect(await screen.findByText('Same Org')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: i18n.t('users.deactivate') })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: i18n.t('admin.users.unlock') })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: i18n.t('common.delete') })).toBeInTheDocument();
  });

  it('hides lifecycle actions on a protected-admin target on the details page', async () => {
    apiGet
      .mockResolvedValueOnce(platformSuperTarget)
      .mockResolvedValueOnce({
        security: { is_locked: false, failed_attempts: 0, locked_until: null, last_failed_login: null, last_login: null, last_login_ip: null },
      });
    setPath('/users/12');

    render(<AdminRouter />);

    expect(await screen.findByText('Platform Super')).toBeInTheDocument();
    expect(
      await screen.findByText(i18n.t('admin.users.protectedTargetDetail')),
    ).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: i18n.t('users.deactivate') })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: i18n.t('common.delete') })).not.toBeInTheDocument();
  });

  it('shows a targeted error when an OrgSuper unlock attempt is rejected', async () => {
    apiGet
      .mockResolvedValueOnce(ordinarySameOrgUser)
      .mockResolvedValueOnce({
        security: { is_locked: true, failed_attempts: 5, locked_until: '2026-07-12', last_failed_login: null, last_login: '2026-07-10', last_login_ip: '127.0.0.1' },
      });
    apiPost.mockRejectedValueOnce({ message: 'Account locked at the cluster level' });
    setPath('/users/9');

    render(<AdminRouter />);

    const unlockButton = await screen.findByRole('button', { name: i18n.t('admin.users.unlock') });
    await userEvent.setup().click(unlockButton);

    expect(await screen.findByRole('alert')).toHaveTextContent('Account locked at the cluster level');
  });

  it('explains when an OrgSuper cannot unlock a locked row', async () => {
    authState.user = buildActor({ organization_id: 42 });
    const crossOrgLocked = { ...crossOrgUser, is_active: false };
    apiGet
      .mockResolvedValueOnce(crossOrgLocked)
      .mockResolvedValueOnce({
        security: { is_locked: true, failed_attempts: 5, locked_until: '2026-07-12', last_failed_login: null, last_login: '2026-07-10', last_login_ip: '127.0.0.1' },
      });
    setPath('/users/11');

    render(<AdminRouter />);

    expect(await screen.findByText('Other Org')).toBeInTheDocument();
    expect(screen.getByText(i18n.t('admin.users.unlockLockedActor'))).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: i18n.t('admin.users.unlock') })).not.toBeInTheDocument();
  });
});
