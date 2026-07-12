import React from 'react';
import { describe, expect, it, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { RequirePermission } from '@features/access-control/ui/RequirePermission';

const mockUseAuth = vi.fn();

vi.mock('@shared/contexts/AuthContext', () => ({
  useAuth: () => mockUseAuth(),
}));

function renderDashboardRoute() {
  return render(
    <MemoryRouter initialEntries={['/dashboard']}>
      <Routes>
        <Route
          path="/dashboard"
          element={
            <RequirePermission capability="dashboard.view">
              <div>Dashboard Content</div>
            </RequirePermission>
          }
        />
        <Route path="/login" element={<div>Login Page</div>} />
      </Routes>
    </MemoryRouter>,
  );
}

describe('/dashboard route guard (P3-E)', () => {
  beforeEach(() => {
    mockUseAuth.mockReset();
  });

  it('blocks users without view_dashboard permission from rendering the dashboard', () => {
    const can = vi.fn().mockReturnValue(false);
    mockUseAuth.mockReturnValue({ isLoading: false, can });

    renderDashboardRoute();

    expect(screen.queryByText('Dashboard Content')).not.toBeInTheDocument();
    expect(can).toHaveBeenCalledWith('dashboard.view');
  });

  it('allows users with view_dashboard permission to access the dashboard', () => {
    const can = vi.fn().mockReturnValue(true);
    mockUseAuth.mockReturnValue({ isLoading: false, can });

    renderDashboardRoute();

    expect(screen.getByText('Dashboard Content')).toBeInTheDocument();
    expect(can).toHaveBeenCalledWith('dashboard.view');
  });
});
