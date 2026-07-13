import React from 'react';
import { readFileSync } from 'node:fs';
import { cwd } from 'node:process';
import { resolve } from 'node:path';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';

let capturedRootElement: React.ReactElement | null = null;

vi.mock('react-i18next', () => ({
  initReactI18next: { type: '3rdParty', init: vi.fn() },
  useTranslation: () => ({ t: (key: string) => key, i18n: { language: 'ar' } }),
  Trans: ({ children }: { children: React.ReactNode }) => children,
}));
vi.mock('@shared/config/i18n', () => ({}));

vi.mock('react-dom/client', () => ({
  createRoot: vi.fn(() => ({
    render: vi.fn((element: React.ReactElement) => {
      capturedRootElement = element;
    }),
  })),
}));

vi.mock('react-router-dom', async () => {
  const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom');
  return {
    ...actual,
    BrowserRouter: ({ children }: { children: React.ReactNode }) => <>{children}</>,
  };
});

vi.mock('@shared/contexts/AuthContext', () => ({
  AuthProvider: ({ children }: { children: React.ReactNode }) => <>{children}</>,
  useAuth: () => ({
    user: { id: 1, name: 'Super Admin', roles: ['super_admin'], permissions: [] },
    isLoading: false,
    isAuthenticated: true,
    canAccess: () => true,
    hasPermission: () => true,
    hasAnyPermission: () => true,
    hasRole: () => true,
    hasAnyRole: () => true,
    isAdmin: () => true,
    isSuperAdmin: () => true,
    logout: vi.fn(),
    login: vi.fn(),
    refreshUser: vi.fn(),
  }),
}));
vi.mock('@shared/contexts/SystemSettingsContext', () => ({
  SystemSettingsProvider: ({ children }: { children: React.ReactNode }) => <>{children}</>,
  useSystemSettings: () => ({ settings: { name: 'Erada', name_en: 'Erada' } }),
}));
vi.mock('@shared/contexts/LocaleContext', () => ({
  LocaleProvider: ({ children }: { children: React.ReactNode }) => <>{children}</>,
  useLocale: () => ({
    direction: 'rtl',
    locale: 'ar',
    setLocale: vi.fn(),
  }),
}));
vi.mock('@shared/contexts/ThemeContext', () => ({
  ThemeProvider: ({ children }: { children: React.ReactNode }) => <>{children}</>,
  useTheme: () => ({ resolvedTheme: 'light', setTheme: vi.fn() }),
}));
vi.mock('@shared/contexts/OrganizationContext', () => ({
  OrganizationProvider: ({ children }: { children: React.ReactNode }) => <>{children}</>,
  useOrganization: () => ({
    currentOrganization: null,
    organizations: [],
    switchOrganization: vi.fn(),
  }),
}));
vi.mock('@shared/ui/Toast', () => ({
  ToastProvider: ({ children }: { children: React.ReactNode }) => <>{children}</>,
  useToast: () => ({ success: vi.fn(), error: vi.fn(), warning: vi.fn(), info: vi.fn() }),
}));
vi.mock('@shared/ui/ErrorBoundary', () => ({
  default: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));
vi.mock('@shared/lib/sentry', () => ({ initSentry: () => {} }));

vi.mock('@widgets/app-shell', () => ({
  AppLayout: () => (
    <div data-testid="app-layout-shell">
      <span data-testid="nasaq-sidebar-marker">NASAQ</span>
    </div>
  ),
}));

vi.mock('@widgets/admin-shell', () => ({
  AdminLayout: () => (
    <div data-testid="admin-control-plane-shell">
      <span data-testid="admin-shell-nav-marker">AdminNav</span>
      <main data-testid="admin-shell-main" />
    </div>
  ),
}));

vi.mock('@features/access-control', () => ({
  RequirePermission: ({ children }: { children: React.ReactNode }) => <>{children}</>,
  RequireAdmin: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

const renderAt = async (path: string) => {
  vi.resetModules();
  capturedRootElement = null;
  document.body.innerHTML = '<div id="app"></div>';
  await import('../../app');
  expect(capturedRootElement).not.toBeNull();
  return render(
    <MemoryRouter initialEntries={[path]}>
      {capturedRootElement as React.ReactElement}
    </MemoryRouter>,
  );
};

describe('Super Admin Control Plane shell routing', () => {
  beforeEach(() => {
    vi.resetModules();
    capturedRootElement = null;
    document.body.innerHTML = '';
  });

  it('does not leave operational admin route or page ownership behind', () => {
    const source = readFileSync(resolve(cwd(), 'resources/js/app.tsx'), 'utf8');

    expect(source).toMatch(/widgets\/admin-shell|path=\\"\/admin/);
  });

  it('redirects /admin/overview out of the operational app', async () => {
    await renderAt('/admin/overview');

    expect(await screen.findByTestId('admin-control-plane-shell')).toBeInTheDocument();
  });

  it('keeps /dashboard in the regular AppLayout and out of the admin shell', async () => {
    await renderAt('/dashboard');

    expect(await screen.findByTestId('app-layout-shell')).toBeInTheDocument();
    expect(
      screen.queryByTestId('admin-control-plane-shell'),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByTestId('admin-shell-nav-marker'),
    ).not.toBeInTheDocument();
  });

  it('redirects /admin to the operational dashboard fallback', async () => {
    await renderAt('/admin');

    expect(await screen.findByTestId('admin-control-plane-shell')).toBeInTheDocument();
  });
});
