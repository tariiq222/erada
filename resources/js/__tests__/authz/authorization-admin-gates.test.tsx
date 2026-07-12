import React from 'react';
import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';

const can = vi.fn<(capability: string) => boolean>();

vi.mock('@shared/contexts/AuthContext', () => ({
  useAuth: () => ({ isLoading: false, can }),
}));

import { RequireAdmin } from '@features/access-control';
import {
  ADMIN_NAV_PRIMARY,
  ADMIN_NAV_SECONDARY,
} from '@widgets/admin-shell/ui/AdminSidebar';

describe('canonical admin gates', () => {
  it('requires the explicit canonical capability without consulting role labels', () => {
    can.mockReturnValue(false);

    render(
      <MemoryRouter>
        <RequireAdmin capability="audit.view">
          <div>restricted</div>
        </RequireAdmin>
      </MemoryRouter>,
    );

    expect(can).toHaveBeenCalledWith('audit.view');
    expect(screen.queryByText('restricted')).not.toBeInTheDocument();
  });

  it('assigns a canonical capability to every admin navigation deep link', () => {
    const items = [...ADMIN_NAV_PRIMARY, ...ADMIN_NAV_SECONDARY];

    expect(items).not.toHaveLength(0);
    expect(items.every((item) => /^[a-z_]+\.[a-z_]+(?:\.[a-z_]+)?$/.test(item.capability))).toBe(true);
    expect(items.some((item) => item.capability === 'manage_organization')).toBe(false);
  });
});
