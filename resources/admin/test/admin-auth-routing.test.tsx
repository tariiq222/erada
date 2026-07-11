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
}));

type AuthState = {
  user: User | null;
  isLoading: boolean;
  isAuthenticated: boolean;
};

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

vi.mock('@shared/api/twoFactor', () => ({
  twoFactorApi: { verify: authMocks.verifyTwoFactor },
}));

vi.mock('@shared/api/client', () => ({
  api: {
    setAuthenticated: vi.fn(),
    setToken: vi.fn(),
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
    expect(authMocks.verifyTwoFactor).toHaveBeenCalledWith(1, '123456', 'pending-token');
  });
});
