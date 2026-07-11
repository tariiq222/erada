import React from 'react';
import userEvent from '@testing-library/user-event';
import { render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import i18n from '@shared/config/i18n';
import type { User } from '@shared/types';
import { AdminRouter } from '@admin/app/AdminRouter';

const authMocks = vi.hoisted(() => ({
  login: vi.fn(),
  logout: vi.fn(),
  refreshUser: vi.fn(),
  verifyTwoFactor: vi.fn(),
  setAuthenticated: vi.fn(),
  setToken: vi.fn(),
}));

type AuthState = {
  user: User | null;
  isLoading: boolean;
  isAuthenticated: boolean;
};

let authState: AuthState;
let localeDirection: 'rtl' | 'ltr';

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
  useLocale: () => ({ locale: localeDirection === 'rtl' ? 'ar' : 'en', direction: localeDirection, setLocale: vi.fn() }),
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
      if (path === '/admin/organizations/42') {
        return Promise.resolve({
          data: {
            id: 42,
            name: 'Admin Route Organization',
            code: 'ADMIN-42',
            type: 'organization',
            is_active: true,
            children_count: 0,
            users_count: 0,
            projects_count: 0,
          },
        });
      }
      return Promise.reject(new Error(`Unexpected admin API path: ${path}`));
    }),
    setAuthenticated: authMocks.setAuthenticated,
    setToken: authMocks.setToken,
  },
}));

function setPath(path: string, state: unknown = null) {
  window.history.replaceState({ usr: state, key: 'admin-test', idx: 0 }, '', path);
}

function asUser(roles: string[]): User {
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
  };
}

describe('admin authentication routing', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    authState = { user: null, isLoading: false, isAuthenticated: false };
    localeDirection = 'rtl';
    document.documentElement.lang = 'ar';
    document.documentElement.dir = 'rtl';
  });

  it('redirects an anonymous deep link to login with its safe return target', async () => {
    setPath('/organizations/42?tab=security');

    render(<AdminRouter />);

    expect(await screen.findByRole('heading', { name: i18n.t('auth.login') })).toBeInTheDocument();
    await waitFor(() => expect(window.location.pathname).toBe('/login'));
    expect(new URLSearchParams(window.location.search).get('returnTo')).toBe(
      '/organizations/42?tab=security',
    );
  });

  it('renders Forbidden for an authenticated user without the literal super_admin role', async () => {
    authState = {
      user: asUser(['organization_admin']),
      isLoading: false,
      isAuthenticated: true,
    };
    setPath('/overview');

    render(<AdminRouter />);

    expect(await screen.findByRole('heading', { name: i18n.t('ovr.api.access_denied') })).toBeInTheDocument();
  });

  it('renders a protected admin page for the literal super_admin role', async () => {
    authState = {
      user: asUser(['super_admin']),
      isLoading: false,
      isAuthenticated: true,
    };
    setPath('/overview');

    render(<AdminRouter />);

    expect(await screen.findByTestId('admin-protected-page')).toBeInTheDocument();
  });

  it('returns a successful login to an allowlisted admin route', async () => {
    const user = userEvent.setup();
    setPath('/login?returnTo=%2Forganizations%2F42');
    authMocks.login.mockImplementation(async () => {
      authState = {
        user: asUser(['super_admin']),
        isLoading: false,
        isAuthenticated: true,
      };
      return { success: true };
    });

    render(<AdminRouter />);

    await user.type(screen.getByLabelText(new RegExp(`^${i18n.t('auth.email_label')}`)), 'admin@example.test');
    await user.type(screen.getByLabelText(new RegExp(`^${i18n.t('auth.password_label')}`)), 'secret-password');
    await user.click(screen.getByRole('button', { name: i18n.t('auth.login_button') }));

    await waitFor(() => expect(window.location.pathname).toBe('/organizations/42'));
    expect(window.location.pathname).not.toBe('/dashboard');
  });

  it('moves a two-factor login challenge to verification with the safe return state', async () => {
    const user = userEvent.setup();
    setPath('/login?returnTo=%2Fusers%2F9');
    authMocks.login.mockResolvedValue({
      success: false,
      requiresTwoFactor: true,
      pendingToken: 'opaque-pending-token',
      userId: 9,
      userName: 'Admin User',
    });

    render(<AdminRouter />);

    await user.type(screen.getByLabelText(new RegExp(`^${i18n.t('auth.email_label')}`)), 'admin@example.test');
    await user.type(screen.getByLabelText(new RegExp(`^${i18n.t('auth.password_label')}`)), 'secret-password');
    await user.click(screen.getByRole('button', { name: i18n.t('auth.login_button') }));

    await waitFor(() => expect(window.location.pathname).toBe('/verify-2fa'));
    expect(window.history.state.usr).toEqual({
      pendingToken: 'opaque-pending-token',
      userId: 9,
      userName: 'Admin User',
      returnTo: '/users/9',
    });
  });

  it('uses the active locale direction on forbidden and not-found pages', async () => {
    localeDirection = 'ltr';
    authState = {
      user: asUser(['organization_admin']),
      isLoading: false,
      isAuthenticated: true,
    };
    setPath('/overview');

    const forbiddenRender = render(<AdminRouter />);
    expect((await screen.findByRole('heading', { name: i18n.t('ovr.api.access_denied') })).closest('main')).toHaveAttribute('dir', 'ltr');
    forbiddenRender.unmount();

    authState = {
      user: asUser(['super_admin']),
      isLoading: false,
      isAuthenticated: true,
    };
    setPath('/missing-admin-page');
    render(<AdminRouter />);

    expect((await screen.findByRole('heading', { name: i18n.t('ovr.not_found') })).closest('section')).toHaveAttribute('dir', 'ltr');
  });

  it.each(['https://evil.example/steal', '//evil.example/steal', '/dashboard']) (
    'falls back to overview for an unsafe login return target: %s',
    async (returnTo) => {
      const user = userEvent.setup();
      setPath(`/login?returnTo=${encodeURIComponent(returnTo)}`);
      authMocks.login.mockImplementation(async () => {
        authState = {
          user: asUser(['super_admin']),
          isLoading: false,
          isAuthenticated: true,
        };
        return { success: true };
      });

      render(<AdminRouter />);

      await user.type(screen.getByLabelText(new RegExp(`^${i18n.t('auth.email_label')}`)), 'admin@example.test');
      await user.type(screen.getByLabelText(new RegExp(`^${i18n.t('auth.password_label')}`)), 'secret-password');
      await user.click(screen.getByRole('button', { name: i18n.t('auth.login_button') }));

      await waitFor(() => expect(window.location.pathname).toBe('/overview'));
    },
  );

  it('returns successful two-factor verification to the saved admin route', async () => {
    const user = userEvent.setup();
    setPath('/verify-2fa', {
      pendingToken: 'pending-token',
      userId: 1,
      userName: 'Admin User',
      returnTo: '/security/alerts',
    });
    authMocks.verifyTwoFactor.mockResolvedValue({ user: { id: 1 }, token: 'cookie-compat' });
    authMocks.refreshUser.mockImplementation(async () => {
      authState = {
        user: asUser(['super_admin']),
        isLoading: false,
        isAuthenticated: true,
      };
    });

    render(<AdminRouter />);

    const digits = await screen.findAllByLabelText(new RegExp(`^${i18n.t('auth.enter_verification_code')} \\d$`));
    for (const [index, digit] of ['1', '2', '3', '4', '5', '6'].entries()) {
      await user.type(digits[index], digit);
    }
    await user.click(screen.getByRole('button', { name: i18n.t('auth.verify') }));

    await waitFor(() => expect(window.location.pathname).toBe('/security/alerts'));
    expect(await screen.findByText(i18n.t('admin.security_alerts.empty.title'))).toBeInTheDocument();
    expect(authMocks.verifyTwoFactor).toHaveBeenCalledWith(1, '123456', 'pending-token');
    expect(authMocks.setAuthenticated).toHaveBeenCalledWith(true);
    expect(authMocks.setToken).not.toHaveBeenCalled();
  });
});
