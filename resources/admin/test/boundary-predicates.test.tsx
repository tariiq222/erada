import React from 'react';
import { render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import i18n from '@shared/config/i18n';

type AuthView = {
  user: Record<string, unknown> | null;
};

const authState: AuthView = { user: null };

vi.mock('@shared/contexts/AuthContext', () => ({
  useAuth: () => ({
    user: authState.user,
    isAuthenticated: authState.user !== null,
    isLoading: false,
  }),
}));

vi.mock('@shared/contexts/LocaleContext', () => ({
  useLocale: () => ({ locale: 'en', direction: 'ltr', setLocale: vi.fn() }),
}));

import { SuperAdminBoundary } from '@admin/app/SuperAdminBoundary';
import {
  AdminNavigation,
  isAdminNavItemVisible,
  ADMIN_NAV_ITEMS,
  type AdminNavItem,
} from '@admin/widgets/admin-shell/AdminNavigation';

describe('SuperAdminBoundary predicate', () => {
  beforeEach(() => {
    authState.user = null;
    document.documentElement.lang = 'en';
    document.documentElement.dir = 'ltr';
  });

  function renderProtectedAt(path: string) {
    return render(
      <MemoryRouter initialEntries={[path]}>
        <Routes>
          <Route element={<SuperAdminBoundary />}>
            <Route
              path="/protected"
              element={<div data-testid="admin-protected-page">protected</div>}
            />
          </Route>
        </Routes>
      </MemoryRouter>,
    );
  }

  it('renders the protected outlet for a user flagged is_super_admin === true', async () => {
    authState.user = {
      id: 1,
      name: 'Super',
      email: 'super@example.test',
      is_super_admin: true,
      is_organization_super_admin: false,
      is_org_admin: false,
      roles: [],
    };

    renderProtectedAt('/protected');

    expect(await screen.findByTestId('admin-protected-page')).toBeInTheDocument();
  });

  it('renders Forbidden for a user flagged is_super_admin === false even with is_organization_super_admin === true', async () => {
    authState.user = {
      id: 2,
      name: 'Org Super',
      email: 'orgsuper@example.test',
      is_super_admin: false,
      is_organization_super_admin: true,
      is_org_admin: false,
      roles: ['organization_super_admin'],
    };

    renderProtectedAt('/protected');

    expect(
      await screen.findByRole('heading', { name: i18n.t('ovr.api.access_denied') }),
    ).toBeInTheDocument();
  });

  it('renders Forbidden for an authenticated user flagged is_super_admin === false', async () => {
    authState.user = {
      id: 3,
      name: 'Org Admin',
      email: 'org@example.test',
      is_super_admin: false,
      is_org_admin: true,
      roles: ['organization_admin'],
    };

    renderProtectedAt('/protected');

    expect(
      await screen.findByRole('heading', { name: i18n.t('ovr.api.access_denied') }),
    ).toBeInTheDocument();
  });

  it('denies when roles says super_admin but is_super_admin flag is absent (legacy payload)', async () => {
    authState.user = {
      id: 4,
      name: 'Legacy Flag',
      email: 'legacy@example.test',
      roles: ['super_admin'],
    };

    renderProtectedAt('/protected');

    expect(
      await screen.findByRole('heading', { name: i18n.t('ovr.api.access_denied') }),
    ).toBeInTheDocument();
  });

  it('does not throw when the user object has no roles field and is_super_admin is false', async () => {
    authState.user = {
      id: 5,
      name: 'No Roles',
      email: 'noroles@example.test',
      is_super_admin: false,
      is_org_admin: false,
    };

    expect(() => renderProtectedAt('/protected')).not.toThrow();

    expect(
      await screen.findByRole('heading', { name: i18n.t('ovr.api.access_denied') }),
    ).toBeInTheDocument();
  });

  it('renders Forbidden when canonical admin flags are missing', async () => {
    authState.user = {
      id: 6,
      name: 'Guest',
      email: 'guest@example.test',
    };

    renderProtectedAt('/protected');

    expect(
      await screen.findByRole('heading', { name: i18n.t('ovr.api.access_denied') }),
    ).toBeInTheDocument();
    expect(screen.queryByTestId('admin-protected-page')).not.toBeInTheDocument();
  });
});

describe('admin navigation audience predicate', () => {
  const TestIcon = () => null;
  const navItem = (audience: AdminNavItem['audience']): AdminNavItem => ({
    href: `/${audience}`,
    labelKey: `admin.test.${audience}`,
    fallback: audience,
    audience,
    icon: TestIcon,
  });

  const platformHiddenFromOrgSuper = {
    description: 'hides a platform item from an organization super admin',
    audience: 'platform' as const,
    user: { is_super_admin: false, is_organization_super_admin: true },
    expected: false,
  };
  const platformVisibleToSuper = {
    description: 'shows a platform item to a super admin',
    audience: 'platform' as const,
    user: { is_super_admin: true, is_organization_super_admin: false },
    expected: true,
  };
  const platformHiddenFromOrgAdmin = {
    description: 'hides a platform item from a plain org admin',
    audience: 'platform' as const,
    user: { is_super_admin: false, is_organization_super_admin: false, is_org_admin: true },
    expected: false,
  };
  const platformHiddenFromAnonymous = {
    description: 'hides a platform item when the user is missing',
    audience: 'platform' as const,
    user: null,
    expected: false,
  };
  const orgVisibleToOrgSuper = {
    description: 'shows an org item to an organization super admin',
    audience: 'org' as const,
    user: { is_super_admin: false, is_organization_super_admin: true },
    expected: true,
  };
  const orgVisibleToSuper = {
    description: 'shows an org item to a super admin',
    audience: 'org' as const,
    user: { is_super_admin: true, is_organization_super_admin: false },
    expected: true,
  };
  const orgHiddenFromOrgAdmin = {
    description: 'hides an org item from a plain org admin',
    audience: 'org' as const,
    user: { is_super_admin: false, is_organization_super_admin: false, is_org_admin: true },
    expected: false,
  };
  const orgHiddenFromAnonymous = {
    description: 'hides an org item when the user is missing',
    audience: 'org' as const,
    user: null,
    expected: false,
  };
  const orgHiddenWhenOrgSuperFlagAbsent = {
    description: 'hides an org item when is_organization_super_admin flag is absent',
    audience: 'org' as const,
    user: { is_super_admin: false, roles: ['organization_super_admin'] },
    expected: false,
  };

  const visibilityMatrix = [
    platformHiddenFromAnonymous,
    platformHiddenFromOrgAdmin,
    platformHiddenFromOrgSuper,
    platformVisibleToSuper,
    orgHiddenFromAnonymous,
    orgHiddenFromOrgAdmin,
    orgHiddenWhenOrgSuperFlagAbsent,
    orgVisibleToOrgSuper,
    orgVisibleToSuper,
  ];

  it.each(visibilityMatrix)('$description', ({ audience, user, expected }) => {
    expect(isAdminNavItemVisible(navItem(audience), user)).toBe(expected);
  });

  it('does not throw when admin navigation renders for an organization super admin', () => {
    authState.user = {
      id: 10,
      name: 'Org Super',
      email: 'orgsuper@example.test',
      is_super_admin: false,
      is_organization_super_admin: true,
      is_org_admin: false,
      roles: ['organization_super_admin'],
    };

    expect(() =>
      render(
        <MemoryRouter initialEntries={['/users']}>
          <AdminNavigation mode="desktop" />
        </MemoryRouter>,
      ),
    ).not.toThrow();
  });

  it('shows only the org items to an organization super admin', () => {
    authState.user = {
      id: 11,
      name: 'Org Super',
      email: 'orgsuper@example.test',
      is_super_admin: false,
      is_organization_super_admin: true,
      is_org_admin: false,
      roles: ['organization_super_admin'],
    };

    render(
      <MemoryRouter initialEntries={['/overview']}>
        <AdminNavigation mode="desktop" />
      </MemoryRouter>,
    );

    const nav = screen.getByTestId('admin-desktop-navigation');
    const renderedHrefs = Array.from(nav.querySelectorAll('a')).map((a) => a.getAttribute('href') ?? '');

    for (const item of ADMIN_NAV_ITEMS) {
      if (item.audience === 'org') {
        expect(
          renderedHrefs,
          `org-super should see org item ${item.href}`,
        ).toContain(item.href);
      } else {
        expect(
          renderedHrefs,
          `org-super must not see platform item ${item.href}`,
        ).not.toContain(item.href);
      }
    }
  });

  it('shows every nav item to a super admin', () => {
    authState.user = {
      id: 12,
      name: 'Super',
      email: 'super@example.test',
      is_super_admin: true,
      is_organization_super_admin: false,
      is_org_admin: false,
      roles: [],
    };

    render(
      <MemoryRouter initialEntries={['/overview']}>
        <AdminNavigation mode="desktop" />
      </MemoryRouter>,
    );

    const nav = screen.getByTestId('admin-desktop-navigation');
    const renderedHrefs = Array.from(nav.querySelectorAll('a')).map((a) => a.getAttribute('href') ?? '');

    for (const item of ADMIN_NAV_ITEMS) {
      expect(renderedHrefs, `super admin should see ${item.href}`).toContain(item.href);
    }
  });

  it('audience annotation matches the documented platform / org split', () => {
    const platformItems = ADMIN_NAV_ITEMS.filter((item) => item.audience === 'platform');
    const orgItems = ADMIN_NAV_ITEMS.filter((item) => item.audience === 'org');

    expect(platformItems.map((item) => item.href)).toEqual(
      expect.arrayContaining([
        '/overview',
        '/security/alerts',
        '/audit/recent',
        '/organizations',
        '/roles',
        '/scope-types',
        '/incident-types',
      ]),
    );
    expect(orgItems.map((item) => item.href)).toEqual(
      expect.arrayContaining([
        '/users',
        '/departments',
        '/access',
        '/activity-logs',
        '/scoped-roles/audit-logs',
      ]),
    );
    // No platform item may have a route the org-super surface is meant to own.
    for (const item of platformItems) {
      expect(orgItems.map((orgItem) => orgItem.href)).not.toContain(item.href);
    }
  });
});