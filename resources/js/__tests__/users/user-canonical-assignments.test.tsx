import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import UserForm from '@pages/users/UserForm';

const mocks = vi.hoisted(() => ({
  getOne: vi.fn(),
  update: vi.fn(),
  create: vi.fn(),
  listRoles: vi.fn(),
  getDepartments: vi.fn(),
  refreshUser: vi.fn(),
  showToast: vi.fn(),
}));

vi.mock('@entities/user', () => ({
  usersApi: {
    getOne: mocks.getOne,
    update: mocks.update,
    create: mocks.create,
  },
}));

vi.mock('@entities/role', () => ({
  rolesApi: {
    list: mocks.listRoles,
  },
}));

vi.mock('@entities/hr', () => ({
  departmentsApi: { getList: mocks.getDepartments },
}));

vi.mock('@shared/contexts/AuthContext', () => ({
  useAuth: () => ({ user: { id: 7 }, refreshUser: mocks.refreshUser }),
}));

vi.mock('@shared/contexts/OrganizationContext', () => ({
  useOrganization: () => ({ currentOrganization: { id: 42, name: 'Erada' } }),
}));

vi.mock('@shared/ui/Toast', () => ({
  useToast: () => ({ showToast: mocks.showToast }),
}));

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}));

function renderEditForm() {
  return render(
    <MemoryRouter initialEntries={['/admin/users/7/edit']}>
      <Routes>
        <Route path="/admin/users/:id/edit" element={<UserForm />} />
        <Route path="/admin/users" element={<div>users-index</div>} />
      </Routes>
    </MemoryRouter>,
  );
}

describe('UserForm canonical role assignments', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mocks.getDepartments.mockResolvedValue([]);
    mocks.listRoles.mockResolvedValue({
      data: [
        { id: 11, name: 'admin', label: 'Admin', is_active: true },
        { id: 12, name: 'viewer', label: 'Viewer', is_active: true },
      ],
    });
    mocks.getOne.mockResolvedValue({
      id: 7,
      name: 'Tariq',
      email: 'tariq@example.com',
      is_active: true,
      roles: ['viewer'],
    });
    mocks.update.mockResolvedValue({ user: { id: 7 } });
  });

  it('submits numeric canonical role assignments at the selected organization scope', async () => {
    renderEditForm();

    await screen.findByDisplayValue('tariq@example.com');
    fireEvent.click(await screen.findByText('Admin'));
    fireEvent.click(screen.getByRole('button', { name: 'common.save_changes' }));

    await waitFor(() => {
      expect(mocks.update).toHaveBeenCalledWith(7, expect.objectContaining({
        assignments: [
          {
            role_id: 11,
            scope_type: 'organization',
            scope_id: 42,
            inherit_to_children: false,
          },
          {
            role_id: 12,
            scope_type: 'organization',
            scope_id: 42,
            inherit_to_children: false,
          },
        ],
      }));
      expect(mocks.update).toHaveBeenCalledWith(7, expect.not.objectContaining({ roles: expect.anything() }));
    });
    expect(mocks.refreshUser).toHaveBeenCalledOnce();
    expect(await screen.findByText('users-index')).toBeInTheDocument();
  });

  it.each([403, 409])('keeps the form open for assignment HTTP %s without optimistic navigation', async (status) => {
    mocks.update.mockRejectedValue({ status, message: 'assignment rejected' });
    renderEditForm();

    await screen.findByDisplayValue('tariq@example.com');
    fireEvent.click(screen.getByRole('button', { name: 'common.save_changes' }));

    await waitFor(() => expect(mocks.showToast).toHaveBeenCalledWith('error', 'assignment rejected'));
    expect(screen.queryByText('users-index')).not.toBeInTheDocument();
    expect(mocks.refreshUser).not.toHaveBeenCalled();
  });

  it('renders canonical assignment validation errors and keeps the form open', async () => {
    mocks.update.mockRejectedValue({
      status: 422,
      message: 'invalid assignment',
      errors: { 'assignments.0.scope_id': ['scope is required'] },
    });
    renderEditForm();

    await screen.findByDisplayValue('tariq@example.com');
    fireEvent.click(screen.getByRole('button', { name: 'common.save_changes' }));

    expect(await screen.findByText('scope is required')).toBeInTheDocument();
    expect(screen.queryByText('users-index')).not.toBeInTheDocument();
    expect(mocks.refreshUser).not.toHaveBeenCalled();
  });
});
