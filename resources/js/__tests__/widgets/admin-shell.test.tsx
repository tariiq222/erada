import React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, within } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, fallback?: string) => fallback || key,
    i18n: { language: 'ar' },
  }),
}));

vi.mock('@shared/contexts/AuthContext', () => ({
  useAuth: () => ({
    user: { id: 1, name: 'Super Admin', capabilities: [] },
    isLoading: false,
    isAuthenticated: true,
    can: vi.fn().mockReturnValue(true),
    canAccess: vi.fn().mockReturnValue(true),
    logout: vi.fn(),
  }),
}));
vi.mock('@shared/contexts/ThemeContext', () => ({
  useTheme: () => ({ resolvedTheme: 'light', setTheme: vi.fn() }),
}));
vi.mock('@shared/contexts/LocaleContext', () => ({
  useLocale: () => ({ direction: 'rtl', locale: 'ar', setLocale: vi.fn() }),
}));
vi.mock('@shared/contexts/SystemSettingsContext', () => ({
  useSystemSettings: () => ({ settings: { name: 'Erada', name_en: 'Erada' } }),
}));

vi.mock('@shared/ui/LanguageSwitcher', () => ({
  default: () => <span data-testid="language-switcher-stub" />,
}));
vi.mock('@shared/ui/ThemeSwitcher', () => ({
  default: () => <span data-testid="theme-switcher-stub" />,
}));

import { AdminLayout } from '@widgets/admin-shell';

const renderShellAt = (path: string) =>
  render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route element={<AdminLayout />}>
          <Route
            path="/admin/overview"
            element={<div data-testid="overview-stub" />}
          />
          <Route
            path="/admin/security/alerts"
            element={<div data-testid="alerts-stub" />}
          />
          <Route
            path="/admin/audit/recent"
            element={<div data-testid="audit-stub" />}
          />
          <Route
            path="/admin/organizations"
            element={<div data-testid="organizations-stub" />}
          />
          <Route
            path="/admin/access"
            element={<div data-testid="access-stub" />}
          />
          <Route
            path="/admin/roles"
            element={<div data-testid="roles-stub" />}
          />
          <Route
            path="/admin/users"
            element={<div data-testid="users-stub" />}
          />
          <Route
            path="/admin/activity-logs"
            element={<div data-testid="activity-logs-stub" />}
          />
          <Route
            path="/admin/authorization/audit-logs"
            element={<div data-testid="authorization-audit-stub" />}
          />
        </Route>
      </Routes>
    </MemoryRouter>,
  );

describe('AdminLayout shell', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders the admin control plane chrome without the regular app shell', () => {
    renderShellAt('/admin/overview');

    expect(
      screen.getByTestId('admin-control-plane-shell'),
    ).toBeInTheDocument();
    expect(screen.getByTestId('admin-shell-header')).toBeInTheDocument();
    expect(screen.getByTestId('admin-shell-sidebar')).toBeInTheDocument();
    expect(screen.getByTestId('admin-shell-main')).toBeInTheDocument();
  });

  it('exposes the M1 governance nav (overview, security, audit-recent) as the primary quick links', () => {
    renderShellAt('/admin/overview');

    const sidebar = screen.getByTestId('admin-shell-sidebar');
    expect(
      within(sidebar).getByTestId('admin-shell-nav-/admin/overview'),
    ).toBeInTheDocument();
    expect(
      within(sidebar).getByTestId('admin-shell-nav-/admin/security/alerts'),
    ).toBeInTheDocument();
    expect(
      within(sidebar).getByTestId('admin-shell-nav-/admin/audit/recent'),
    ).toBeInTheDocument();
  });

  it('exposes the secondary technical-controls cluster', () => {
    renderShellAt('/admin/overview');

    const sidebar = screen.getByTestId('admin-shell-sidebar');
    for (const href of [
      '/admin/organizations',
      '/admin/access',
      '/admin/roles',
      '/admin/users',
      '/admin/activity-logs',
      '/admin/authorization/audit-logs',
    ]) {
      expect(
        within(sidebar).getByTestId(`admin-shell-nav-${href}`),
      ).toBeInTheDocument();
    }
  });

  it('marks the active nav item with aria-current=page', () => {
    renderShellAt('/admin/security/alerts');

    const active = screen.getByTestId('admin-shell-nav-/admin/security/alerts');
    expect(active).toHaveAttribute('aria-current', 'page');
    const inactive = screen.getByTestId('admin-shell-nav-/admin/overview');
    expect(inactive).not.toHaveAttribute('aria-current', 'page');
  });
});
