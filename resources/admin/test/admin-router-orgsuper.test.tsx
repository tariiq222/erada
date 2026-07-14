import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import i18n from '@shared/config/i18n';
import type { User } from '@shared/types';
import { AdminRouter } from '@admin/app/AdminRouter';

type AuthState = {
  user: User | null;
  isLoading: boolean;
  isAuthenticated: boolean;
};

const authMocks = vi.hoisted(() => ({
  login: vi.fn(),
  logout: vi.fn(),
  refreshUser: vi.fn(),
  verifyTwoFactor: vi.fn(),
  setAuthenticated: vi.fn(),
  setToken: vi.fn(),
}));

let authState: AuthState;

vi.mock('@shared/contexts/AuthContext', () => ({
  AuthProvider: ({ children }: { children: React.ReactNode }) => children,
  useAuth: () => ({
    ...authState,
    login: authMocks.login,
    logout: authMocks.logout,
    refreshUser: authMocks.refreshUser,
  }),
}));

vi.mock('@shared/contexts/LocaleContext', () => ({
  LocaleProvider: ({ children }: { children: React.ReactNode }) => children,
  useLocale: () => ({ locale: 'en', direction: 'ltr', setLocale: vi.fn() }),
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

vi.mock('@shared/api/twoFactor', () => ({
  twoFactorApi: { verify: authMocks.verifyTwoFactor },
}));

vi.mock('@shared/api/client', () => ({
  api: {
    get: vi.fn((path: string) => {
      if (path === '/admin/overview') {
        return Promise.resolve({
          data: {
            organizations: { active: 1, total: 1 },
            users: { active: 1, total: 1, two_factor_coverage: { enabled: 1, active_users: 1, percent: 100 } },
            login_attempts: { last_24h: { successful: 1, failed: 0, total: 1 } },
            generated_at: '2026-07-12T00:00:00+03:00',
          },
        });
      }
      if (path === '/admin/security/alerts') {
        return Promise.resolve({
          data: {
            windows: { minutes: 60, cutoff: '2026-07-11T23:00:00+03:00', repeated_failure_threshold: 3 },
            failed_logins_repeated: [],
            access_denied_events: [],
            generated_at: '2026-07-12T00:00:00+03:00',
          },
        });
      }
      if (path === '/admin/audit/recent') {
        return Promise.resolve({
          data: {
            data: [],
            meta: { current_page: 1, last_page: 1, per_page: 25, total: 0, limit: 25, returned: 0 },
          },
        });
      }
      if (path === '/organizations') {
        return Promise.resolve({
          data: { data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } },
        });
      }
      if (path === '/admin/users' || path === '/admin/access') {
        return Promise.resolve({ data: { data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } } });
      }
      if (path === '/admin/activity-logs' || path === '/admin/scoped-roles/audit-logs') {
        return Promise.resolve({ data: { data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } } });
      }
      return Promise.reject(new Error(`Unexpected admin API path: ${path}`));
    }),
    setAuthenticated: authMocks.setAuthenticated,
    setToken: authMocks.setToken,
  },
}));

function setPath(path: string, state: unknown = null) {
  window.history.replaceState({ usr: state, key: 'orgsuper-router-test', idx: 0 }, '', path);
}

type OrgSuperFlags = {
  is_super_admin?: boolean;
  is_organization_super_admin?: boolean;
  is_org_admin?: boolean;
};

function asUser(
  roles: string[],
  flags: OrgSuperFlags = {},
): User {
  return {
    id: 1,
    name: 'Admin User',
    email: 'admin@example.test',
    department_id: null,
    phone: null,
    extension: null,
    job_title: null,
    is_active: true,
    roles,
    is_super_admin: flags.is_super_admin,
    is_org_admin: flags.is_org_admin,
    is_organization_super_admin: flags.is_organization_super_admin,
  } as User & { is_organization_super_admin?: boolean } as unknown as User;
}

const platformRoutes = [
  '/overview',
  '/security/alerts',
  '/audit/recent',
  '/organizations',
  '/roles',
  '/scope-types',
  '/incident-types',
  '/access/governance',
] as const;

const orgSuperRoutes = [
  '/users',
  '/users/new',
  '/departments',
  '/access',
  '/activity-logs',
  '/scoped-roles/audit-logs',
] as const;

describe('admin router — org-super reachability split', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    authState = { user: null, isLoading: false, isAuthenticated: false };
    document.documentElement.lang = 'en';
    document.documentElement.dir = 'ltr';
  });

  it('lets a super admin reach every platform route', async () => {
    authState = {
      user: asUser([], { is_super_admin: true, is_org_admin: false, is_organization_super_admin: false }),
      isLoading: false,
      isAuthenticated: true,
    };

    for (const route of platformRoutes) {
      setPath(route);
      const view = render(<AdminRouter />);
      expect(
        await screen.findByTestId('admin-shell-main'),
        `super admin should reach platform route ${route}`,
      ).toBeInTheDocument();
      expect(
        screen.queryByRole('heading', { name: i18n.t('ovr.api.access_denied') }),
        `super admin must not see Forbidden at platform route ${route}`,
      ).not.toBeInTheDocument();
      view.unmount();
    }
  });

  it('lets a super admin reach every org-super route', async () => {
    authState = {
      user: asUser([], { is_super_admin: true, is_org_admin: false, is_organization_super_admin: false }),
      isLoading: false,
      isAuthenticated: true,
    };

    for (const route of orgSuperRoutes) {
      setPath(route);
      const view = render(<AdminRouter />);
      expect(
        await screen.findByTestId('admin-shell-main'),
        `super admin should reach org-super route ${route}`,
      ).toBeInTheDocument();
      view.unmount();
    }
  });

  it('lets an organization super admin reach every org-super route', async () => {
    authState = {
      user: asUser(['organization_super_admin'], {
        is_super_admin: false,
        is_org_admin: false,
        is_organization_super_admin: true,
      }),
      isLoading: false,
      isAuthenticated: true,
    };

    for (const route of orgSuperRoutes) {
      setPath(route);
      const view = render(<AdminRouter />);
      expect(
        await screen.findByTestId('admin-shell-main'),
        `org-super should reach org-super route ${route}`,
      ).toBeInTheDocument();
      expect(
        screen.queryByRole('heading', { name: i18n.t('ovr.api.access_denied') }),
        `org-super must not see Forbidden at ${route}`,
      ).not.toBeInTheDocument();
      view.unmount();
    }
  });

  it.each(platformRoutes)('forbids an organization super admin from reaching %s', async (route) => {
    authState = {
      user: asUser(['organization_super_admin'], {
        is_super_admin: false,
        is_org_admin: false,
        is_organization_super_admin: true,
      }),
      isLoading: false,
      isAuthenticated: true,
    };
    setPath(route);

    render(<AdminRouter />);

    expect(
      await screen.findByRole('heading', { name: i18n.t('ovr.api.access_denied') }),
      `org-super must be Forbidden at platform route ${route}`,
    ).toBeInTheDocument();
  });

  it('forbids an org-admin (without org-super flag) from reaching /users', async () => {
    authState = {
      user: asUser(['organization_admin'], {
        is_super_admin: false,
        is_org_admin: true,
        is_organization_super_admin: false,
      }),
      isLoading: false,
      isAuthenticated: true,
    };
    setPath('/users');

    render(<AdminRouter />);

    expect(
      await screen.findByRole('heading', { name: i18n.t('ovr.api.access_denied') }),
    ).toBeInTheDocument();
  });

  it('redirects an org-super with an unauthenticated deep link to /login with a safe returnTo', async () => {
    authState = { user: null, isLoading: false, isAuthenticated: false };
    setPath('/users/42?tab=security');

    render(<AdminRouter />);

    expect(await screen.findByRole('heading', { name: i18n.t('auth.login') })).toBeInTheDocument();
    await waitFor(() => expect(window.location.pathname).toBe('/login'));
    expect(new URLSearchParams(window.location.search).get('returnTo')).toBe(
      '/users/42?tab=security',
    );
  });
});
