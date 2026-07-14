import React from 'react';
import { render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import i18n from '@shared/config/i18n';

const authState: {
  user: Record<string, unknown> | null;
  isLoading: boolean;
  isAuthenticated: boolean;
} = { user: null, isLoading: false, isAuthenticated: false };

vi.mock('@shared/contexts/AuthContext', () => ({
  useAuth: () => ({
    user: authState.user,
    isLoading: authState.isLoading,
    isAuthenticated: authState.isAuthenticated,
  }),
}));

vi.mock('@shared/contexts/LocaleContext', () => ({
  useLocale: () => ({ locale: 'en', direction: 'ltr', setLocale: vi.fn() }),
}));

import { OrgSuperOrSuperBoundary } from '@admin/app/OrgSuperOrSuperBoundary';

describe('OrgSuperOrSuperBoundary predicate', () => {
  beforeEach(() => {
    authState.user = null;
    authState.isLoading = false;
    authState.isAuthenticated = false;
    document.documentElement.lang = 'en';
    document.documentElement.dir = 'ltr';
  });

  function renderProtectedAt(path: string) {
    return render(
      <MemoryRouter initialEntries={[path]}>
        <Routes>
          <Route element={<OrgSuperOrSuperBoundary />}>
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
    authState.isAuthenticated = true;

    renderProtectedAt('/protected');

    expect(await screen.findByTestId('admin-protected-page')).toBeInTheDocument();
  });

  it('renders the protected outlet for a user flagged is_organization_super_admin === true', async () => {
    authState.user = {
      id: 2,
      name: 'Org Super',
      email: 'orgsuper@example.test',
      is_super_admin: false,
      is_organization_super_admin: true,
      is_org_admin: false,
      roles: [],
    };
    authState.isAuthenticated = true;

    renderProtectedAt('/protected');

    expect(await screen.findByTestId('admin-protected-page')).toBeInTheDocument();
  });

  it('renders Forbidden for an authenticated user flagged is_super_admin=false and is_organization_super_admin=false', async () => {
    authState.user = {
      id: 3,
      name: 'Org Admin',
      email: 'orgadmin@example.test',
      is_super_admin: false,
      is_organization_super_admin: false,
      is_org_admin: true,
      roles: ['organization_admin'],
    };
    authState.isAuthenticated = true;

    renderProtectedAt('/protected');

    expect(
      await screen.findByRole('heading', { name: i18n.t('ovr.api.access_denied') }),
    ).toBeInTheDocument();
  });

  it('does NOT rely on the legacy roles list (no role names referenced for authorization)', async () => {
    authState.user = {
      id: 4,
      name: 'Role-Only',
      email: 'roleonly@example.test',
      is_super_admin: false,
      is_organization_super_admin: false,
      is_org_admin: false,
      roles: ['organization_super_admin'],
    };
    authState.isAuthenticated = true;

    renderProtectedAt('/protected');

    expect(
      await screen.findByRole('heading', { name: i18n.t('ovr.api.access_denied') }),
    ).toBeInTheDocument();
  });

  it('renders Forbidden when the user object omits both flags entirely', async () => {
    authState.user = {
      id: 5,
      name: 'Guest',
      email: 'guest@example.test',
      is_org_admin: false,
    };
    authState.isAuthenticated = true;

    renderProtectedAt('/protected');

    expect(
      await screen.findByRole('heading', { name: i18n.t('ovr.api.access_denied') }),
    ).toBeInTheDocument();
    expect(screen.queryByTestId('admin-protected-page')).not.toBeInTheDocument();
  });

  it('renders the protected outlet when only is_organization_super_admin is true (no is_super_admin)', async () => {
    authState.user = {
      id: 6,
      name: 'Orphan OrgSuper',
      email: 'orphan@example.test',
      is_super_admin: false,
      is_organization_super_admin: true,
      is_org_admin: false,
    };
    authState.isAuthenticated = true;

    renderProtectedAt('/protected');

    expect(await screen.findByTestId('admin-protected-page')).toBeInTheDocument();
  });
});
