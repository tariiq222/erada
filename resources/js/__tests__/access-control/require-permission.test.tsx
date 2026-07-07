import React from 'react';
import { describe, expect, it, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { RequireAdmin, RequirePermission } from '@features/access-control/ui/RequirePermission';
import { canAccessCompat } from '@shared/api/access-bridge';

const mockUseAuth = vi.fn();

vi.mock('@shared/contexts/AuthContext', () => ({
  useAuth: () => mockUseAuth(),
}));

function renderGuard(ui: React.ReactNode) {
  return render(
    <MemoryRouter initialEntries={['/restricted']}>
      <Routes>
        <Route path="/restricted" element={ui} />
        <Route path="/dashboard" element={<div>Dashboard Redirect</div>} />
      </Routes>
    </MemoryRouter>,
  );
}

describe('RequirePermission route guard', () => {
  beforeEach(() => {
    mockUseAuth.mockReset();
  });

  it('renders a loading status while auth state is loading', () => {
    mockUseAuth.mockReturnValue({ isLoading: true, canAccess: vi.fn() });

    renderGuard(<RequirePermission config={{ permission: 'view_projects' }}>Secret</RequirePermission>);

    expect(screen.getByRole('status')).toBeInTheDocument();
    expect(screen.queryByText('Secret')).not.toBeInTheDocument();
  });

  it('renders children when canAccess approves the supplied config', () => {
    const canAccess = vi.fn().mockReturnValue(true);
    mockUseAuth.mockReturnValue({ isLoading: false, canAccess });

    renderGuard(<RequirePermission config={{ allPermissions: ['view_projects', 'edit_projects'] }}>Secret</RequirePermission>);

    expect(screen.getByText('Secret')).toBeInTheDocument();
    expect(canAccess).toHaveBeenCalledWith({ allPermissions: ['view_projects', 'edit_projects'] });
  });

  it('renders fallback instead of redirect when access is denied and fallback is supplied', () => {
    mockUseAuth.mockReturnValue({ isLoading: false, canAccess: vi.fn().mockReturnValue(false) });

    renderGuard(
      <RequirePermission config={{ permission: 'delete_projects' }} fallback={<div>No Access</div>}>
        Secret
      </RequirePermission>,
    );

    expect(screen.getByText('No Access')).toBeInTheDocument();
    expect(screen.queryByText('Dashboard Redirect')).not.toBeInTheDocument();
  });

  it('redirects to dashboard when access is denied and no fallback is supplied', () => {
    mockUseAuth.mockReturnValue({ isLoading: false, canAccess: vi.fn().mockReturnValue(false) });

    renderGuard(<RequirePermission config={{ permission: 'delete_projects' }}>Secret</RequirePermission>);

    expect(screen.getByText('Dashboard Redirect')).toBeInTheDocument();
  });

  it('RequireAdmin delegates to RequirePermission with admin role gate', () => {
    const canAccess = vi.fn().mockReturnValue(true);
    mockUseAuth.mockReturnValue({ isLoading: false, canAccess });

    renderGuard(<RequireAdmin>Admin Secret</RequireAdmin>);

    expect(screen.getByText('Admin Secret')).toBeInTheDocument();
    // RequireAdmin now gates on the manage_organization capability, not a role list.
    expect(canAccess).toHaveBeenCalledWith({ permissions: ['manage_organization'] });
  });
});

// Phase F2.1 — verify the central access bridge makes RequirePermission
// grant/deny correctly with empty permissions[] and only an access map.

function makeUser(overrides: Partial<{
  roles: string[];
  permissions: string[];
  access: Record<string, Record<string, true>>;
}> = {}) {
  return {
    id: 1,
    name: 'Test',
    email: 't@e.com',
    department_id: null,
    phone: null,
    extension: null,
    job_title: null,
    is_active: true,
    roles: [],
    permissions: [],
    access: undefined,
    ...overrides,
  };
}

describe('RequirePermission access-only payload', () => {
  beforeEach(() => {
    mockUseAuth.mockReset();
  });

  it('grants access via legacy permission when only access payload carries the capability', () => {
    const user = makeUser({ access: { projects: { view: true } } });
    const canAccess = vi.fn().mockImplementation((c) => canAccessCompat(user, c));
    mockUseAuth.mockReturnValue({ isLoading: false, user, canAccess });

    renderGuard(<RequirePermission config={{ permission: 'view_projects' }}>X</RequirePermission>);

    expect(screen.getByText('X')).toBeInTheDocument();
  });

  it('grants access when a canonical capability (projects.view) is supplied', () => {
    const user = makeUser({ access: { projects: { view: true } } });
    const canAccess = vi.fn().mockImplementation((c) => canAccessCompat(user, c));
    mockUseAuth.mockReturnValue({ isLoading: false, user, canAccess });

    renderGuard(<RequirePermission config={{ permission: 'projects.view' }}>X</RequirePermission>);

    expect(screen.getByText('X')).toBeInTheDocument();
  });

  it('denies transition-only manage_organization when legacy permissions[] is empty', () => {
    const user = makeUser({ access: { projects: { view: true } } });
    const canAccess = vi.fn().mockImplementation((c) => canAccessCompat(user, c));
    mockUseAuth.mockReturnValue({ isLoading: false, user, canAccess });

    renderGuard(<RequirePermission config={{ permission: 'manage_organization' }}>X</RequirePermission>);

    expect(screen.queryByText('X')).not.toBeInTheDocument();
    expect(screen.getByText('Dashboard Redirect')).toBeInTheDocument();
  });

  it('denies transition-only manage_organization even when legacy permissions[] carries it (Phase 9.3 cutover)', () => {
    // Phase 9.3: `user.permissions[]` was removed from `/api/auth/me`.
    // Transition-only strings without canonical capabilities resolve to
    // `false` regardless of legacy payload shape. Owners MUST migrate
    // consumers to a canonical capability before this becomes user-visible.
    const user = makeUser({ permissions: ['manage_organization'] });
    const canAccess = vi.fn().mockImplementation((c) => canAccessCompat(user, c));
    mockUseAuth.mockReturnValue({ isLoading: false, user, canAccess });

    renderGuard(<RequirePermission config={{ permission: 'manage_organization' }}>X</RequirePermission>);

    expect(screen.queryByText('X')).not.toBeInTheDocument();
    expect(screen.getByText('Dashboard Redirect')).toBeInTheDocument();
  });
});
