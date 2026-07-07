import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import React from 'react';

const mockNavigate = vi.fn();
let mockParams: Record<string, string> = {};
vi.mock('react-router-dom', async (importOriginal) => ({
  ...((await importOriginal()) as Record<string, unknown>),
  useNavigate: () => mockNavigate,
  useParams: () => mockParams,
}));

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (k: string) => k }),
}));

const mockShowToast = vi.fn();
vi.mock('@shared/ui/Toast', async (importOriginal) => ({
  ...((await importOriginal()) as Record<string, unknown>),
  useToast: () => ({ showToast: mockShowToast }),
}));

const CAPACITY_AVAILABLE = [
  { role_key: 'dept_member', label: 'عضو القسم', scope: 'department' },
  { role_key: 'dept_manager', label: 'مدير القسم', scope: 'department' },
  { role_key: 'quality_manager', label: 'مدير الجودة', scope: 'organization' },
];

const { departmentsApi } = vi.hoisted(() => ({
  departmentsApi: {
    getList: vi.fn().mockResolvedValue([]),
    getOne: vi.fn(),
    getAllowedLevels: vi.fn().mockResolvedValue({ levels: { 1: 'Level 1' } }),
    getCapacityRoles: vi.fn(),
    getAvailableCapacityRoles: vi.fn(),
    create: vi.fn().mockResolvedValue({ id: 9 }),
    update: vi.fn().mockResolvedValue({}),
    updateCapacityRoles: vi.fn().mockResolvedValue({}),
  },
}));
vi.mock('@entities/hr', () => ({ departmentsApi }));
vi.mock('@entities/user', () => ({ usersApi: { getList: vi.fn().mockResolvedValue([]) } }));

import DepartmentForm from '../../pages/hr/DepartmentForm';

describe('DepartmentForm — create', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mockParams = {};
    departmentsApi.getAvailableCapacityRoles.mockResolvedValue({
      available: CAPACITY_AVAILABLE,
    });
    departmentsApi.create.mockResolvedValue({ id: 9 });
  });

  it('renders two capacity groups (members and managers)', async () => {
    render(<DepartmentForm />);

    await waitFor(() => {
      expect(screen.getByText('hr.capacity.member_roles')).toBeInTheDocument();
    });
    expect(screen.getByText('hr.capacity.manager_roles')).toBeInTheDocument();
  });

  it('preselects suggested defaults for a new department', async () => {
    render(<DepartmentForm />);

    await waitFor(() =>
      expect(screen.getAllByLabelText('عضو القسم').length).toBeGreaterThan(0)
    );

    // dept_member suggested in the member group, dept_manager in the manager group
    const memberCheckbox = screen.getAllByLabelText('عضو القسم')[0] as HTMLInputElement;
    const managerCheckbox = screen.getAllByLabelText('مدير القسم')[1] as HTMLInputElement;
    expect(memberCheckbox.checked).toBe(true);
    expect(managerCheckbox.checked).toBe(true);
  });

  it('tags cross-cutting organization-scoped roles', async () => {
    render(<DepartmentForm />);

    await waitFor(() =>
      expect(screen.getAllByLabelText('مدير الجودة').length).toBeGreaterThan(0)
    );
    // org-scoped role is tagged as cross-cutting in both groups
    expect(screen.getAllByText('hr.capacity.cross_cutting').length).toBeGreaterThan(0);
  });

  it('saves member and manager keys via updateCapacityRoles after create', async () => {
    render(<DepartmentForm />);

    await waitFor(() =>
      expect(screen.getAllByLabelText('عضو القسم').length).toBeGreaterThan(0)
    );

    fireEvent.change(screen.getByLabelText('hr.department_name', { exact: false }), {
      target: { value: 'New Dept' },
    });

    fireEvent.click(screen.getByRole('button', { name: /common.add/ }));

    await waitFor(() => expect(departmentsApi.create).toHaveBeenCalled());
    await waitFor(() =>
      expect(departmentsApi.updateCapacityRoles).toHaveBeenCalledWith(
        9,
        expect.objectContaining({
          member_role_keys: ['dept_member'],
          manager_role_keys: ['dept_manager'],
        })
      )
    );
    expect(mockNavigate).toHaveBeenCalledWith('/hr/departments');
  });
});

describe('DepartmentForm — empty-state warning', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mockParams = { id: '5' };
    departmentsApi.getOne.mockResolvedValue({
      id: 5,
      name: 'Existing Dept',
      code: 'D5',
      description: '',
      parent_id: null,
      level: 1,
      manager_id: null,
      is_active: true,
    });
    // edit with no policy -> both groups empty -> warning shows
    departmentsApi.getCapacityRoles.mockResolvedValue({
      member_role_keys: [],
      manager_role_keys: [],
      available: CAPACITY_AVAILABLE,
    });
  });

  it('shows a warning when no capacity roles are selected', async () => {
    render(<DepartmentForm />);

    await waitFor(() =>
      expect(screen.getByDisplayValue('Existing Dept')).toBeInTheDocument()
    );
    expect(screen.getByText('hr.capacity.empty_warning')).toBeInTheDocument();
  });
});

describe('DepartmentForm — edit', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mockParams = { id: '5' };
    departmentsApi.getOne.mockResolvedValue({
      id: 5,
      name: 'Existing Dept',
      code: 'D5',
      description: '',
      parent_id: null,
      level: 1,
      manager_id: null,
      is_active: true,
    });
    departmentsApi.getCapacityRoles.mockResolvedValue({
      member_role_keys: ['dept_member'],
      manager_role_keys: ['dept_manager'],
      available: CAPACITY_AVAILABLE,
    });
  });

  it('prefills the form and preselects existing capacity roles', async () => {
    render(<DepartmentForm />);

    await waitFor(() => {
      expect(screen.getByDisplayValue('Existing Dept')).toBeInTheDocument();
    });
    const memberCheckbox = screen.getAllByLabelText('عضو القسم')[0] as HTMLInputElement;
    expect(memberCheckbox.checked).toBe(true);
  });
});
