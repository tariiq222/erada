import React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

const navigateMock = vi.fn();

const organizationsApiMock = {
  create: vi.fn(),
  update: vi.fn(),
  get: vi.fn(),
  delete: vi.fn(),
  list: vi.fn().mockResolvedValue({ data: [], meta: { current_page: 1, last_page: 1, total: 0 } }),
};

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock('react-router-dom', async () => {
  const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom');
  return {
    ...actual,
    useNavigate: () => navigateMock,
    useParams: () => ({}), // no id -> create mode
  };
});

vi.mock('@entities/admin', () => ({
  organizationsApi: organizationsApiMock,
  ORGANIZATION_TYPES: ['cluster', 'hospital', 'center', 'organization', 'other'],
}));

describe('OrganizationForm (create)', () => {
  beforeEach(() => {
    navigateMock.mockReset();
    organizationsApiMock.create.mockReset();
  });

  it('submits and creates the organization when the Save button is clicked', async () => {
    // Regression: the Save button lives in the PageHeader actions; if it is not
    // inside the <form>, clicking it never triggers submit and nothing happens.
    const user = userEvent.setup();
    organizationsApiMock.create.mockResolvedValue({ data: { id: 5 } });

    const { default: OrganizationForm } = await import('@pages/admin/organizations/OrganizationForm');
    render(<OrganizationForm />);

    const textboxes = screen.getAllByRole('textbox');
    await user.type(textboxes[0], 'هيئة الاختبار'); // name *
    await user.type(textboxes[1], 'TST'); // code *

    await user.click(screen.getByRole('button', { name: /common\.save/ }));

    await waitFor(() => expect(organizationsApiMock.create).toHaveBeenCalledTimes(1));
    expect(organizationsApiMock.create).toHaveBeenCalledWith(
      expect.objectContaining({
        name: 'هيئة الاختبار',
        code: 'TST',
        type: 'organization',
        parent_id: null,
        sort_order: 0,
        is_active: true,
      }),
    );
    await waitFor(() => expect(navigateMock).toHaveBeenCalledWith('/admin/organizations'));
  });

  it('shows an error and does not navigate when creation fails', async () => {
    const user = userEvent.setup();
    organizationsApiMock.create.mockRejectedValue(new Error('الكود مستخدم بالفعل'));

    const { default: OrganizationForm } = await import('@pages/admin/organizations/OrganizationForm');
    render(<OrganizationForm />);

    const textboxes = screen.getAllByRole('textbox');
    await user.type(textboxes[0], 'هيئة الاختبار');
    await user.type(textboxes[1], 'TST');

    await user.click(screen.getByRole('button', { name: /common\.save/ }));

    expect(await screen.findByText('الكود مستخدم بالفعل')).toBeInTheDocument();
    expect(navigateMock).not.toHaveBeenCalled();
  });
});
