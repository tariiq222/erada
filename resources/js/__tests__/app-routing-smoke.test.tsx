import React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';

let capturedRootElement: React.ReactElement | null = null;

vi.mock('react-i18next', () => ({
  initReactI18next: { type: '3rdParty', init: vi.fn() },
  useTranslation: () => ({ t: (key: string) => key }),
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
  const ReactModule = await import('react');
  return {
    BrowserRouter: ({ children }: { children: React.ReactNode }) => <div data-testid="browser-router">{children}</div>,
    Routes: ({ children }: { children: React.ReactNode }) => <div data-testid="routes">{children}</div>,
    Route: ({ children, element, path }: { children?: React.ReactNode; element?: React.ReactNode; path?: string }) => (
      <div data-testid="route" data-path={path ?? ''}>
        {element ? <span data-testid="route-element" /> : null}
        {children}
      </div>
    ),
    Navigate: ({ to }: { to: string }) => <div data-testid="navigate">{to}</div>,
    Outlet: () => <div data-testid="outlet" />,
    useLocation: () => ({ pathname: '/dashboard' }),
    useNavigate: () => vi.fn(),
    Link: ({ children, to }: { children: React.ReactNode; to: string }) => <a href={to}>{children}</a>,
    NavLink: ({ children, to }: { children: React.ReactNode; to: string }) => <a href={to}>{children}</a>,
    useParams: () => ({}),
    useSearchParams: () => [new URLSearchParams(), vi.fn()],
    matchPath: vi.fn(),
    createSearchParams: (params: Record<string, string>) => new URLSearchParams(params),
    unstable_useBlocker: () => ({ state: 'unblocked', proceed: vi.fn(), reset: vi.fn() }),
    default: ReactModule,
  };
});

vi.mock('@shared/contexts/AuthContext', () => ({
  AuthProvider: ({ children }: { children: React.ReactNode }) => <div data-testid="auth-provider">{children}</div>,
  useAuth: () => ({
    user: { id: 1, name: 'Admin', roles: ['super_admin'], permissions: [] },
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
  SystemSettingsProvider: ({ children }: { children: React.ReactNode }) => <div data-testid="settings-provider">{children}</div>,
}));
vi.mock('@shared/contexts/LocaleContext', () => ({
  LocaleProvider: ({ children }: { children: React.ReactNode }) => <div data-testid="locale-provider">{children}</div>,
}));
vi.mock('@shared/contexts/ThemeContext', () => ({
  ThemeProvider: ({ children }: { children: React.ReactNode }) => <div data-testid="theme-provider">{children}</div>,
}));
vi.mock('@shared/contexts/OrganizationContext', () => ({
  OrganizationProvider: ({ children }: { children: React.ReactNode }) => <div data-testid="org-provider">{children}</div>,
}));
vi.mock('@shared/ui/Toast', () => ({
  ToastProvider: ({ children }: { children: React.ReactNode }) => <div data-testid="toast-provider">{children}</div>,
  useToast: () => ({ success: vi.fn(), error: vi.fn(), warning: vi.fn(), info: vi.fn() }),
}));
vi.mock('@shared/ui/ErrorBoundary', () => ({
  default: ({ children }: { children: React.ReactNode }) => <div data-testid="error-boundary">{children}</div>,
}));
vi.mock('@widgets/app-shell', () => ({
  AppLayout: () => <div data-testid="app-layout" />,
}));
vi.mock('@features/access-control', () => ({
  RequirePermission: ({ children, config }: { children: React.ReactNode; config?: unknown }) => (
    <div data-testid="require-permission" data-config={JSON.stringify(config ?? {})}>{children}</div>
  ),
  RequireAdmin: ({ children }: { children: React.ReactNode }) => <div data-testid="require-admin">{children}</div>,
}));

describe('application route registry', () => {
  beforeEach(() => {
    vi.resetModules();
    capturedRootElement = null;
    document.body.innerHTML = '<div id="app"></div>';
  });

  it('mounts the root provider tree and registers the large route map', async () => {
    await import('../app');

    expect(capturedRootElement).not.toBeNull();
    render(capturedRootElement as React.ReactElement);

    expect(screen.getByTestId('browser-router')).toBeInTheDocument();
    expect(screen.getAllByTestId('route').length).toBeGreaterThan(60);
    expect(screen.getByTestId('theme-provider')).toBeInTheDocument();
    expect(document.querySelector('[data-path="/dashboard"]')).not.toBeNull();
    expect(document.querySelector('[data-path="/projects/create"]')).not.toBeNull();
    expect(document.querySelector('[data-path="/admin/organizations"]')).toBeNull();
    expect(document.querySelector('[data-path="/surveys"]')).not.toBeNull();
    expect(document.querySelector('[data-path="risks"]')).not.toBeNull();
  });

  it('does not mount when the Laravel app container is absent', async () => {
    document.body.innerHTML = '';

    await import('../app');

    expect(capturedRootElement).toBeNull();
  });
});
