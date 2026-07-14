import React from 'react';
import userEvent from '@testing-library/user-event';
import { render, screen, within } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import i18n from '@shared/config/i18n';
import { AdminLayout } from '@admin/widgets/admin-shell/AdminLayout';
import { ADMIN_NAV_ITEMS } from '@admin/widgets/admin-shell/AdminNavigation';

const authMocks = vi.hoisted(() => ({
  user: {
    id: 1,
    name: 'System Admin',
    is_super_admin: true,
    is_organization_super_admin: false,
    is_org_admin: false,
  } as Record<string, unknown> | null,
}));

vi.mock('@shared/contexts/AuthContext', () => ({
  useAuth: () => ({ user: authMocks.user, logout: vi.fn() }),
}));

vi.mock('@shared/contexts/LocaleContext', () => ({
  useLocale: () => ({ locale: 'ar', direction: 'rtl', setLocale: vi.fn() }),
}));

vi.mock('@shared/contexts/ThemeContext', () => ({
  useTheme: () => ({ resolvedTheme: 'light', toggleTheme: vi.fn() }),
}));

vi.mock('@shared/contexts/SystemSettingsContext', () => ({
  useSystemSettings: () => ({ settings: { name: 'Erada Platform', name_en: 'Erada' } }),
}));

function renderShell(initialPath = '/overview') {
  return render(
    <MemoryRouter initialEntries={[initialPath]}>
      <Routes>
        <Route element={<AdminLayout />}>
          <Route path="/overview" element={<div>Protected content</div>} />
        </Route>
      </Routes>
    </MemoryRouter>,
  );
}

describe('independent admin shell', () => {
  beforeEach(() => {
    authMocks.user = {
      id: 1,
      name: 'System Admin',
      is_super_admin: true,
      is_organization_super_admin: false,
      is_org_admin: false,
    };
  });

  it('uses Arabic RTL chrome and renders the protected outlet', () => {
    renderShell();

    const shell = screen.getByTestId('admin-control-plane-shell');
    expect(shell).toHaveAttribute('dir', 'rtl');
    expect(screen.getByText('Protected content')).toBeInTheDocument();
    expect(screen.getByRole('banner')).toBeInTheDocument();
    expect(screen.getByTestId('admin-desktop-navigation')).toBeInTheDocument();
  });

  it('exposes every route group in the mobile navigation from the single nav source for a super admin', async () => {
    const user = userEvent.setup();
    renderShell();

    await user.click(screen.getByRole('button', { name: i18n.t('admin.shell.sidebar.aria') }));
    const mobileNavigation = screen.getByTestId('admin-mobile-navigation');

    expect(ADMIN_NAV_ITEMS).toHaveLength(12);
    for (const item of ADMIN_NAV_ITEMS) {
      expect(within(mobileNavigation).getByRole('link', {
        name: i18n.t(item.labelKey, item.fallback),
      })).toHaveAttribute(
        'href',
        item.href,
      );
    }
  });

  it('hides every platform nav item from an organization super admin', async () => {
    authMocks.user = {
      id: 2,
      name: 'Org Super',
      is_super_admin: false,
      is_organization_super_admin: true,
      is_org_admin: false,
      roles: ['organization_super_admin'],
    };

    const user = userEvent.setup();
    renderShell();

    await user.click(screen.getByRole('button', { name: i18n.t('admin.shell.sidebar.aria') }));
    const mobileNavigation = screen.getByTestId('admin-mobile-navigation');
    const renderedHrefs = new Set(
      Array.from(mobileNavigation.querySelectorAll('a')).map((a) => a.getAttribute('href') ?? ''),
    );

    for (const item of ADMIN_NAV_ITEMS) {
      if (item.audience === 'platform') {
        expect(
          renderedHrefs.has(item.href),
          `org-super must not see platform nav item ${item.href}`,
        ).toBe(false);
      } else {
        expect(
          renderedHrefs.has(item.href),
          `org-super should see org nav item ${item.href}`,
        ).toBe(true);
      }
    }
  });
});
