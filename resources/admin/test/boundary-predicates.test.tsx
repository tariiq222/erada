import React from 'react';
import { render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import i18n from '@shared/config/i18n';

const authState: { user: Record<string, unknown> | null } = { user: null };

vi.mock('@shared/contexts/AuthContext', () => ({
  useAuth: () => ({ user: authState.user, isAuthenticated: authState.user !== null, isLoading: false }),
}));

vi.mock('@shared/contexts/LocaleContext', () => ({
  useLocale: () => ({ locale: 'en', direction: 'ltr', setLocale: vi.fn() }),
}));

import { SuperAdminBoundary } from '@admin/app/SuperAdminBoundary';
import {
  AdminNavigation,
  isAdminNavItemVisible,
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
      is_org_admin: false,
      roles: [],
    };

    renderProtectedAt('/protected');

    expect(await screen.findByTestId('admin-protected-page')).toBeInTheDocument();
  });

  it('renders Forbidden for an authenticated user flagged is_super_admin === false', async () => {
    authState.user = {
      id: 2,
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
      id: 3,
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
      id: 4,
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
      id: 5,
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

describe('admin navigation visibility predicate', () => {
  const TestIcon = () => null;
  const navItem = (group: AdminNavItem['group']): AdminNavItem => ({
    href: `/${group}`,
    labelKey: `admin.test.${group}`,
    fallback: group,
    group,
    icon: TestIcon,
  });
  const visibilityMatrix: Array<{
    description: string;
    group: AdminNavItem['group'];
    user: Parameters<typeof isAdminNavItemVisible>[1];
    expected: boolean;
  }> = [
    { description: 'hides a system item when the user is missing', group: 'system', user: null, expected: false },
    { description: 'hides a system item when canonical flags are missing', group: 'system', user: {}, expected: false },
    { description: 'hides a system item from an org admin', group: 'system', user: { is_super_admin: false, is_org_admin: true }, expected: false },
    { description: 'shows a system item to a super admin', group: 'system', user: { is_super_admin: true, is_org_admin: false }, expected: true },
    { description: 'hides an org item when the user is missing', group: 'org', user: null, expected: false },
    { description: 'hides an org item from a non-admin', group: 'org', user: { is_super_admin: false, is_org_admin: false }, expected: false },
    { description: 'shows an org item to an org admin', group: 'org', user: { is_super_admin: false, is_org_admin: true }, expected: true },
    { description: 'shows an org item to a super admin', group: 'org', user: { is_super_admin: true, is_org_admin: false }, expected: true },
    { description: 'keeps a governance item visible without admin flags', group: 'governance', user: {}, expected: true },
    { description: 'keeps a controls item visible to a non-admin', group: 'controls', user: { is_super_admin: false, is_org_admin: false }, expected: true },
  ];

  it.each(visibilityMatrix)('$description', ({ group, user, expected }) => {
    expect(isAdminNavItemVisible(navItem(group), user)).toBe(expected);
  });

  it('does not throw when admin navigation renders for a non-super-admin authenticated user', () => {
    authState.user = {
      id: 6,
      name: 'Viewer',
      email: 'viewer@example.test',
      is_super_admin: false,
      is_org_admin: false,
    };

    expect(() =>
      render(
        <MemoryRouter initialEntries={['/overview']}>
          <AdminNavigation mode="desktop" />
        </MemoryRouter>,
      ),
    ).not.toThrow();
  });
});
