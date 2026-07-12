import React from 'react';
import { describe, expect, it, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { RequireAdmin, RequirePermission } from '@features/access-control/ui/RequirePermission';

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
    mockUseAuth.mockReturnValue({ isLoading: true, can: vi.fn() });

    renderGuard(<RequirePermission capability="projects.view">Secret</RequirePermission>);

    expect(screen.getByRole('status')).toBeInTheDocument();
    expect(screen.queryByText('Secret')).not.toBeInTheDocument();
  });

  it('renders children when can approves the canonical capability', () => {
    const can = vi.fn().mockReturnValue(true);
    mockUseAuth.mockReturnValue({ isLoading: false, can });

    renderGuard(<RequirePermission capability="projects.view">Secret</RequirePermission>);

    expect(screen.getByText('Secret')).toBeInTheDocument();
    expect(can).toHaveBeenCalledWith('projects.view');
  });

  it('renders fallback instead of redirect when access is denied and fallback is supplied', () => {
    mockUseAuth.mockReturnValue({ isLoading: false, can: vi.fn().mockReturnValue(false) });

    renderGuard(
      <RequirePermission capability="projects.delete" fallback={<div>No Access</div>}>
        Secret
      </RequirePermission>,
    );

    expect(screen.getByText('No Access')).toBeInTheDocument();
    expect(screen.queryByText('Dashboard Redirect')).not.toBeInTheDocument();
  });

  it('redirects to dashboard when access is denied and no fallback is supplied', () => {
    mockUseAuth.mockReturnValue({ isLoading: false, can: vi.fn().mockReturnValue(false) });

    renderGuard(<RequirePermission capability="projects.delete">Secret</RequirePermission>);

    expect(screen.getByText('Dashboard Redirect')).toBeInTheDocument();
  });

  it('RequireAdmin delegates to RequirePermission with an explicit canonical capability', () => {
    const can = vi.fn().mockReturnValue(true);
    mockUseAuth.mockReturnValue({ isLoading: false, can });

    renderGuard(<RequireAdmin capability="core.assign_roles">Admin Secret</RequireAdmin>);

    expect(screen.getByText('Admin Secret')).toBeInTheDocument();
    expect(can).toHaveBeenCalledWith('core.assign_roles');
  });

  it('rejects legacy labels instead of translating them', () => {
    const can = vi.fn().mockReturnValue(false);
    mockUseAuth.mockReturnValue({ isLoading: false, can });

    renderGuard(<RequirePermission capability="view_projects">Secret</RequirePermission>);

    expect(can).toHaveBeenCalledWith('view_projects');
    expect(screen.getByText('Dashboard Redirect')).toBeInTheDocument();
  });
});
