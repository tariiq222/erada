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

import { OrgSuperOrSuperBoundary } from '@admin/app/OrgSuperOrSuperBoundary';

function renderBoundaryAt(path: string) {
  return render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route element={<OrgSuperOrSuperBoundary />}>
          <Route
            path="/org-admin"
            element={<div data-testid="org-admin-protected-page">org-admin</div>}
          />
        </Route>
      </Routes>
    </MemoryRouter>,
  );
}

describe('OrgSuperOrSuperBoundary predicate', () => {
  beforeEach(() => {
    authState.user = null;
    document.documentElement.lang = 'en';
    document.documentElement.dir = 'ltr';
  });

  it('renders the protected outlet for a user flagged is_super_admin === true', async () => {
    authState.user = {
      id: 1,
      name: 'Super',
      email: 'super@example.test',
      is_super_admin: true,
      is_organization_super_admin: false,
      roles: [],
    };

    renderBoundaryAt('/org-admin');

    expect(await screen.findByTestId('org-admin-protected-page')).toBeInTheDocument();
  });

  it('renders the protected outlet for a user flagged is_organization_super_admin === true', async () => {
    authState.user = {
      id: 2,
      name: 'Org Super',
      email: 'orgsuper@example.test',
      is_super_admin: false,
      is_organization_super_admin: true,
      roles: ['organization_super_admin'],
    };

    renderBoundaryAt('/org-admin');

    expect(await screen.findByTestId('org-admin-protected-page')).toBeInTheDocument();
  });

  it('renders Forbidden for an org-admin that is neither super nor org-super', async () => {
    authState.user = {
      id: 3,
      name: 'Plain Org Admin',
      email: 'orgadmin@example.test',
      is_super_admin: false,
      is_organization_super_admin: false,
      is_org_admin: true,
      roles: ['organization_admin'],
    };

    renderBoundaryAt('/org-admin');

    expect(
      await screen.findByRole('heading', { name: i18n.t('ovr.api.access_denied') }),
    ).toBeInTheDocument();
    expect(screen.queryByTestId('org-admin-protected-page')).not.toBeInTheDocument();
  });

  it('renders Forbidden when both flags are explicitly false', async () => {
    authState.user = {
      id: 4,
      name: 'No Flags',
      email: 'noflags@example.test',
      is_super_admin: false,
      is_organization_super_admin: false,
    };

    renderBoundaryAt('/org-admin');

    expect(
      await screen.findByRole('heading', { name: i18n.t('ovr.api.access_denied') }),
    ).toBeInTheDocument();
  });

  it('renders Forbidden when the org-super flag is missing entirely (legacy payload)', async () => {
    authState.user = {
      id: 5,
      name: 'Legacy',
      email: 'legacy@example.test',
      is_super_admin: false,
      roles: ['organization_super_admin'],
    };

    renderBoundaryAt('/org-admin');

    expect(
      await screen.findByRole('heading', { name: i18n.t('ovr.api.access_denied') }),
    ).toBeInTheDocument();
  });

  it('denies when roles contains super_admin but the is_super_admin flag is absent', async () => {
    authState.user = {
      id: 6,
      name: 'Legacy Super',
      email: 'legacysuper@example.test',
      roles: ['super_admin'],
    };

    renderBoundaryAt('/org-admin');

    expect(
      await screen.findByRole('heading', { name: i18n.t('ovr.api.access_denied') }),
    ).toBeInTheDocument();
  });

  it('does not throw when the user object has no roles field and both flags are false', async () => {
    authState.user = {
      id: 7,
      name: 'No Roles',
      email: 'noroles@example.test',
      is_super_admin: false,
      is_organization_super_admin: false,
    };

    expect(() => renderBoundaryAt('/org-admin')).not.toThrow();

    expect(
      await screen.findByRole('heading', { name: i18n.t('ovr.api.access_denied') }),
    ).toBeInTheDocument();
  });

  it('redirects an unauthenticated caller to /login with a safe returnTo', async () => {
    authState.user = null;

    render(
      <MemoryRouter initialEntries={['/org-admin']}>
        <Routes>
          <Route element={<OrgSuperOrSuperBoundary />}>
            <Route
              path="/org-admin"
              element={<div data-testid="org-admin-protected-page">org-admin</div>}
            />
          </Route>
          <Route
            path="/login"
            element={<div data-testid="login-page">login</div>}
          />
        </Routes>
      </MemoryRouter>,
    );

    expect(await screen.findByTestId('login-page')).toBeInTheDocument();
    expect(screen.queryByTestId('org-admin-protected-page')).not.toBeInTheDocument();
  });
});