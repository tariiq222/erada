import React from 'react';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it, vi } from 'vitest';
import i18n from '@shared/config/i18n';

const authState: { user: Record<string, unknown> | null } = { user: null };

vi.mock('@shared/contexts/AuthContext', () => ({
  useAuth: () => ({
    user: authState.user,
    isAuthenticated: authState.user !== null,
    isLoading: false,
    logout: vi.fn(),
  }),
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

import {
  AdminNavigation,
  ADMIN_NAV_ITEMS,
  isAdminNavItemVisible,
  type AdminNavItem,
} from '@admin/widgets/admin-shell/AdminNavigation';

const TestIcon = () => null;

function orgSuperItem(): AdminNavItem {
  return {
    href: '/org-super/users',
    labelKey: 'admin.shell.nav.org_users',
    fallback: 'Org super users',
    group: 'org-super',
    icon: TestIcon,
  };
}

describe('org-super nav item visibility predicate', () => {
  const matrix: Array<{
    description: string;
    user: Parameters<typeof isAdminNavItemVisible>[1];
    expected: boolean;
  }> = [
    { description: 'hides the org-super item when the user is missing', user: null, expected: false },
    { description: 'hides the org-super item when admin flags are missing', user: {}, expected: false },
    {
      description: 'shows the org-super item to is_super_admin=true',
      user: { is_super_admin: true, is_organization_super_admin: false, is_org_admin: false },
      expected: true,
    },
    {
      description: 'shows the org-super item to is_organization_super_admin=true',
      user: { is_super_admin: false, is_organization_super_admin: true, is_org_admin: false },
      expected: true,
    },
    {
      description: 'hides the org-super item from a curated org admin',
      user: { is_super_admin: false, is_organization_super_admin: false, is_org_admin: true },
      expected: false,
    },
    {
      description: 'hides the org-super item from a regular authenticated user',
      user: { is_super_admin: false, is_organization_super_admin: false, is_org_admin: false },
      expected: false,
    },
    {
      description: 'does not derive visibility from roles list',
      user: {
        is_super_admin: false,
        is_organization_super_admin: false,
        roles: ['organization_super_admin'],
      },
      expected: false,
    },
  ];

  it.each(matrix)('$description', ({ user, expected }) => {
    expect(isAdminNavItemVisible(orgSuperItem(), user)).toBe(expected);
  });
});

describe('ADMIN_NAV_ITEMS includes an org-super item', () => {
  it('ships at least one entry tagged with the org-super group', () => {
    const orgSuperEntries = ADMIN_NAV_ITEMS.filter((item) => item.group === 'org-super');
    expect(orgSuperEntries.length).toBeGreaterThan(0);
  });
});

describe('AdminNavigation renders the org-super item for the right audiences', () => {
  it('renders the org-super navigation entries for is_super_admin=true', async () => {
    authState.user = {
      id: 1,
      name: 'Super',
      email: 'super@example.test',
      is_super_admin: true,
      is_organization_super_admin: false,
      is_org_admin: false,
    };

    render(
      <MemoryRouter initialEntries={['/overview']}>
        <AdminNavigation mode="desktop" />
      </MemoryRouter>,
    );

    const orgSuperItem = ADMIN_NAV_ITEMS.find((item) => item.group === 'org-super');
    expect(orgSuperItem).toBeDefined();
    expect(
      await screen.findByRole('link', { name: i18n.t(orgSuperItem!.labelKey, orgSuperItem!.fallback) }),
    ).toHaveAttribute('href', orgSuperItem!.href);
  });

  it('renders the org-super navigation entries for is_organization_super_admin=true', async () => {
    authState.user = {
      id: 2,
      name: 'Org Super',
      email: 'orgsuper@example.test',
      is_super_admin: false,
      is_organization_super_admin: true,
      is_org_admin: false,
    };

    render(
      <MemoryRouter initialEntries={['/overview']}>
        <AdminNavigation mode="desktop" />
      </MemoryRouter>,
    );

    const orgSuperItem = ADMIN_NAV_ITEMS.find((item) => item.group === 'org-super');
    expect(orgSuperItem).toBeDefined();
    expect(
      await screen.findByRole('link', { name: i18n.t(orgSuperItem!.labelKey, orgSuperItem!.fallback) }),
    ).toHaveAttribute('href', orgSuperItem!.href);
  });

  it('does not render the org-super navigation entries for an authenticated user without the flags', () => {
    authState.user = {
      id: 3,
      name: 'Regular',
      email: 'regular@example.test',
      is_super_admin: false,
      is_organization_super_admin: false,
      is_org_admin: false,
    };

    render(
      <MemoryRouter initialEntries={['/overview']}>
        <AdminNavigation mode="desktop" />
      </MemoryRouter>,
    );

    const orgSuperItem = ADMIN_NAV_ITEMS.find((item) => item.group === 'org-super');
    expect(orgSuperItem).toBeDefined();
    expect(
      screen.queryByRole('link', { name: i18n.t(orgSuperItem!.labelKey, orgSuperItem!.fallback) }),
    ).not.toBeInTheDocument();
  });
});
