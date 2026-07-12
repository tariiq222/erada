import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

const navigateMock = vi.fn();
const useParamsMock = vi.fn();

const rolesApiMock = {
  get: vi.fn(),
  create: vi.fn(),
  update: vi.fn(),
  delete: vi.fn(),
  abilities: vi.fn(),
  scopeOptions: vi.fn(),
};

const MOCK_SCOPE_OPTIONS = {
  scopes: [
    { key: 'organization', label: 'المؤسسة' },
    { key: 'department', label: 'القسم' },
  ],
};

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock('react-router-dom', async () => {
  const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom');
  return {
    ...actual,
    useNavigate: () => navigateMock,
    useParams: useParamsMock,
  };
});

vi.mock('@entities/role', () => ({
  rolesApi: rolesApiMock,
}));

const MOCK_REGISTRY = {
  groups: [
    {
      key: 'projects',
      label: 'المشاريع',
      abilities: [
        { id: 'projects.view', label: 'عرض' },
        { id: 'projects.create', label: 'إنشاء' },
      ],
    },
    {
      key: 'meetings',
      label: 'الاجتماعات',
      abilities: [
        { id: 'meetings.view', label: 'عرض الاجتماعات' },
        { id: 'meetings.create', label: 'إنشاء الاجتماعات' },
        { id: 'meetings.edit', label: 'تعديل الاجتماعات' },
        { id: 'meetings.delete', label: 'حذف الاجتماعات' },
        { id: 'meetings.record_decisions', label: 'تسجيل القرارات والتوصيات' },
      ],
    },
  ],
};

describe('RoleForm', () => {
  beforeEach(() => {
    navigateMock.mockReset();
    useParamsMock.mockReset();
    useParamsMock.mockReturnValue({ id: undefined });
    rolesApiMock.get.mockReset();
    rolesApiMock.create.mockReset();
    rolesApiMock.update.mockReset();
    rolesApiMock.delete.mockReset();
    rolesApiMock.abilities.mockReset();
    rolesApiMock.abilities.mockResolvedValue({ data: MOCK_REGISTRY });
    rolesApiMock.scopeOptions.mockReset();
    rolesApiMock.scopeOptions.mockResolvedValue(MOCK_SCOPE_OPTIONS);
    Element.prototype.scrollIntoView = vi.fn();
  });

  it('renders create form with name, labelAr, labelEn fields and ability groups', async () => {
    const { default: RoleForm } = await import('@pages/admin/roles/RoleForm');
    render(<RoleForm />);

    await waitFor(() => expect(rolesApiMock.abilities).toHaveBeenCalled());

    // Basic name field
    expect(screen.getByLabelText(/admin.roles.fields.name/i)).toBeInTheDocument();
    // New label fields
    expect(screen.getByLabelText(/admin.roles.fields.labelAr/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/admin.roles.fields.labelEn/i)).toBeInTheDocument();
    // Ability group rendered from the registry
    expect(screen.getByText('المشاريع')).toBeInTheDocument();
  });

  it('submits create form using canonical capability strings', async () => {
    const user = userEvent.setup();
    rolesApiMock.create.mockResolvedValue({ data: { id: 3 } });

    const { default: RoleForm } = await import('@pages/admin/roles/RoleForm');
    render(<RoleForm />);

    await waitFor(() => expect(rolesApiMock.abilities).toHaveBeenCalled());

    await user.type(screen.getByLabelText(/admin.roles.fields.name/i), 'reviewer');
    await user.type(screen.getByLabelText(/admin.roles.fields.labelAr/i), 'مراجع');
    await user.type(screen.getByLabelText(/admin.roles.fields.labelEn/i), 'Reviewer');

    // Toggle a capability checkbox (projects.view)
    const capCheckbox = screen.getByLabelText('projects.view');
    await user.click(capCheckbox);
    expect(capCheckbox).toBeChecked();

    await user.click(screen.getByRole('button', { name: /common.save/i }));

    await waitFor(() =>
      expect(rolesApiMock.create).toHaveBeenCalledWith(
        expect.objectContaining({
          name: 'reviewer',
          label_ar: 'مراجع',
          label_en: 'Reviewer',
          label: 'مراجع',
          scope_type: 'organization',
          capabilities: ['projects.view'],
          is_active: true,
        })
      )
    );
    expect(navigateMock).toHaveBeenCalledWith('/admin/roles');
  });

  it('renders a scope picker populated from scopeOptions', async () => {
    const { default: RoleForm } = await import('@pages/admin/roles/RoleForm');
    render(<RoleForm />);

    await waitFor(() => expect(rolesApiMock.scopeOptions).toHaveBeenCalled());

    const scopePicker = screen.getByLabelText(/admin.roles.fields.scope/i);
    expect(scopePicker).toBeInTheDocument();
  });

  it('submits the chosen scope_type when a non-default scope is selected', async () => {
    const user = userEvent.setup();
    rolesApiMock.create.mockResolvedValue({ data: { id: 7 } });

    const { default: RoleForm } = await import('@pages/admin/roles/RoleForm');
    render(<RoleForm />);

    await waitFor(() => expect(rolesApiMock.scopeOptions).toHaveBeenCalled());

    await user.type(screen.getByLabelText(/admin.roles.fields.name/i), 'er_manager');

    // Open the scope picker and choose the department scope.
    await user.click(screen.getByLabelText(/admin.roles.fields.scope/i));
    await user.click(screen.getByRole('option', { name: 'القسم' }));

    await user.click(screen.getByRole('button', { name: /common.save/i }));

    await waitFor(() =>
      expect(rolesApiMock.create).toHaveBeenCalledWith(
        expect.objectContaining({
          name: 'er_manager',
          scope_type: 'department',
        })
      )
    );
  });

  it('loads existing role data into form fields including new fields', async () => {
    useParamsMock.mockReturnValue({ id: '5' });

    rolesApiMock.get.mockResolvedValue({
      data: {
        id: 5,
        name: 'editor',
        label: 'Editor',
        users_count: 2,
        is_system: false,
        is_admin_role: false,
        is_active: true,
        scope_type: 'organization',
        label_ar: 'محرر',
        label_en: 'Editor',
        capabilities: ['projects.view', 'tasks.create'],
        reach: {},
      },
    });

    const { default: RoleForm } = await import('@pages/admin/roles/RoleForm');
    render(<RoleForm />);

    await waitFor(() => expect(rolesApiMock.get).toHaveBeenCalledWith(5));

    expect(screen.getByDisplayValue('محرر')).toBeInTheDocument();
    expect(screen.getByDisplayValue('Editor')).toBeInTheDocument();

    // Capabilities loaded: projects.view should be checked
    await waitFor(() => {
      const capCheckbox = screen.getByLabelText('projects.view');
      expect(capCheckbox).toBeChecked();
    });
  });

  it('disables all fields for system roles', async () => {
    useParamsMock.mockReturnValue({ id: '1' });

    rolesApiMock.get.mockResolvedValue({
      data: {
        id: 1,
        name: 'super_admin',
        label: 'Super Admin',
        users_count: 1,
        is_system: true,
        is_admin_role: true,
        is_active: true,
        scope_type: 'all',
        capabilities: [],
        reach: {},
      },
    });

    const { default: RoleForm } = await import('@pages/admin/roles/RoleForm');
    render(<RoleForm />);

    await waitFor(() => expect(rolesApiMock.get).toHaveBeenCalled());

    expect(screen.getByLabelText(/admin.roles.fields.name/i)).toBeDisabled();
    expect(screen.getByLabelText(/admin.roles.fields.labelAr/i)).toBeDisabled();
    expect(screen.getByLabelText(/admin.roles.fields.labelEn/i)).toBeDisabled();
  });
});
