import React from 'react';
import { describe, expect, it, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { useCan, useAccess } from '@shared/api/access';
import { useAuth } from '@shared/contexts/AuthContext';
import type { User } from '@shared/types';

/**
 * Phase 8.1 — Compatibility-window route-gate tests.
 *
 * The structured `user.access` payload was added additively in Phase 1 and
 * became the single source of truth in Phase 9.3 (2026-07-05): `user.permissions[]`
 * was removed from `/api/auth/me`. The migration pattern
 * `useCan('module.action') || canAccess({ permission: '...' })` is still valid
 * because `user.access` resolves canonical dotted capabilities, and the
 * legacy dotted-flat string maps onto the canonical capability through the
 * access-bridge — the `canAccess(...)` half is now redundant for canonical
 * strings but the pattern is preserved in product code for clarity.
 *
 * Tests below use a LOCAL `makeAuth` mock that simulates the old `permissions[]`
 * shape — this proves the route-gate pattern works against ANY payload shape,
 * which is the right invariant to pin (mid-deploy staleness, custom test
 * payloads, future provider migrations). The production bridge itself no
 * longer reads `permissions[]`.
 */

const mockUseAuth = vi.fn();

vi.mock('@shared/contexts/AuthContext', () => ({
  useAuth: () => mockUseAuth(),
}));

function makeUser(overrides: Partial<User> = {}): User {
  return {
    id: 1,
    name: 'Test User',
    email: 'test@test.com',
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

function makeAuth(user: User | null) {
  const canAccess = (config: { permission?: string; permissions?: string[] }) => {
    if (!user) return false;
    if (user.roles?.includes('super_admin')) return true;
    const perms = user.permissions ?? [];
    if (config.permission) return perms.includes(config.permission);
    if (config.permissions) return config.permissions.some((p) => perms.includes(p));
    return false;
  };
  return { user, isLoading: false, isAuthenticated: !!user, canAccess };
}

const CapabilityProbe: React.FC<{ capability: string }> = ({ capability }) => {
  const granted = useCan(capability);
  const access = useAccess();
  return (
    <div>
      <div data-testid="useCan">{granted ? 'granted' : 'denied'}</div>
      <div data-testid="access-keys">{Object.keys(access).join(',') || 'empty'}</div>
    </div>
  );
};

const CompatibilityRouteGate: React.FC<{
  capability: string;
  legacyPermission: string;
  children: React.ReactNode;
}> = ({ capability, legacyPermission, children }) => {
  const { user, isLoading, canAccess } = useAuth();
  // Hook order matters: call useCan unconditionally before any early return
  // so the React rules-of-hooks linter does not flag this as conditional.
  const canStructured = useCan(capability);

  if (isLoading) {
    return <div data-testid="gate-loading">loading</div>;
  }

  if (!user) {
    return <div data-testid="gate-denied">unauthenticated</div>;
  }

  const canLegacy = canAccess({ permission: legacyPermission });
  const granted = canStructured || canLegacy;

  return (
    <div data-testid="gate-result">{granted ? children : <span>denied</span>}</div>
  );
};

function renderWithRoutes(ui: React.ReactNode) {
  return render(
    <MemoryRouter initialEntries={['/guarded']}>
      <Routes>
        <Route path="/guarded" element={ui} />
        <Route path="/dashboard" element={<div>Dashboard Redirect</div>} />
      </Routes>
    </MemoryRouter>,
  );
}

function renderCompatibilityGate(
  ui: React.ReactNode,
  capability: string,
  legacyPermission: string,
) {
  // Render the CompatibilityRouteGate directly. The migration pattern
  // wraps a page component, NOT the route guard itself: per-page checks
  // use `useCan(...) || canAccess(...)` and the route guard stays on
  // the legacy flat-string contract during the compatibility window.
  return renderWithRoutes(
    <CompatibilityRouteGate capability={capability} legacyPermission={legacyPermission}>
      {ui}
    </CompatibilityRouteGate>,
  );
}

describe('Stale-session fallback (Phase 8.1)', () => {
  beforeEach(() => {
    mockUseAuth.mockReset();
  });

  describe('useCan helper', () => {
    it('grants when the structured access map carries the capability', () => {
      mockUseAuth.mockReturnValue(
        makeAuth(
          makeUser({
            access: { strategy: { create: true } },
          }),
        ),
      );

      renderWithRoutes(<CapabilityProbe capability="strategy.create" />);

      expect(screen.getByTestId('useCan')).toHaveTextContent('granted');
      expect(screen.getByTestId('access-keys')).toHaveTextContent('strategy');
    });

    it('denies when the access map is present but the capability is absent', () => {
      mockUseAuth.mockReturnValue(
        makeAuth(
          makeUser({
            access: { strategy: { view: true } },
          }),
        ),
      );

      renderWithRoutes(<CapabilityProbe capability="strategy.create" />);

      expect(screen.getByTestId('useCan')).toHaveTextContent('denied');
    });

    it('returns an empty map and denies when access is undefined (stale payload)', () => {
      mockUseAuth.mockReturnValue(
        makeAuth(
          makeUser({
            permissions: ['create_strategy'],
            access: undefined,
          }),
        ),
      );

      renderWithRoutes(<CapabilityProbe capability="strategy.create" />);

      expect(screen.getByTestId('useCan')).toHaveTextContent('denied');
      expect(screen.getByTestId('access-keys')).toHaveTextContent('empty');
    });

    it('denies for a malformed capability string', () => {
      mockUseAuth.mockReturnValue(
        makeAuth(
          makeUser({
            access: { strategy: { create: true } },
          }),
        ),
      );

      renderWithRoutes(<CapabilityProbe capability="not_a_capability" />);

      expect(screen.getByTestId('useCan')).toHaveTextContent('denied');
    });
  });

  describe('compatibility-window route gate', () => {
    it('grants the route when the access payload carries the capability', () => {
      mockUseAuth.mockReturnValue(
        makeAuth(
          makeUser({
            access: { strategy: { create: true } },
          }),
        ),
      );

      renderCompatibilityGate(
        <span>secret-page</span>,
        'strategy.create',
        'create_strategy',
      );

      expect(screen.getByTestId('gate-result')).toHaveTextContent('secret-page');
    });

    it('grants the route when the structured payload is absent but legacy permissions grant the capability (stale session)', () => {
      mockUseAuth.mockReturnValue(
        makeAuth(
          makeUser({
            permissions: ['create_strategy'],
            access: undefined,
          }),
        ),
      );

      renderCompatibilityGate(
        <span>secret-page</span>,
        'strategy.create',
        'create_strategy',
      );

      expect(screen.getByTestId('gate-result')).toHaveTextContent('secret-page');
    });

    it('denies the route when neither the structured access nor the legacy permissions grant the capability', () => {
      mockUseAuth.mockReturnValue(
        makeAuth(
          makeUser({
            permissions: ['view_dashboard'],
            access: { strategy: { view: true } },
          }),
        ),
      );

      renderCompatibilityGate(
        <span>secret-page</span>,
        'strategy.create',
        'create_strategy',
      );

      expect(screen.getByTestId('gate-result')).toHaveTextContent('denied');
      expect(screen.queryByText('secret-page')).not.toBeInTheDocument();
    });

    it('grants the route for super_admin regardless of the structured payload', () => {
      mockUseAuth.mockReturnValue(
        makeAuth(
          makeUser({
            roles: ['super_admin'],
            permissions: [],
            access: undefined,
          }),
        ),
      );

      renderCompatibilityGate(
        <span>secret-page</span>,
        'strategy.create',
        'create_strategy',
      );

      expect(screen.getByTestId('gate-result')).toHaveTextContent('secret-page');
    });
  });
});