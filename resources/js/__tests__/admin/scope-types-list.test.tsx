import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ScopeTypesList } from '@pages/admin/scope-types/ScopeTypesList';

const { list } = vi.hoisted(() => ({ list: vi.fn() }));

vi.mock('@entities/admin', () => ({
  scopeTypesApi: { list },
}));

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}));

describe('ScopeTypesList', () => {
  beforeEach(() => {
    list.mockReset();
    list.mockResolvedValue({
      data: [
        {
          key: 'project',
          label_ar: 'المشروع',
          label_en: 'Project',
          target_requirement: 'required',
        },
      ],
    });
  });

  it('renders the canonical scope catalog as a read-only surface', async () => {
    render(<MemoryRouter><ScopeTypesList /></MemoryRouter>);

    await waitFor(() => expect(list).toHaveBeenCalledWith());
    expect(await screen.findByText('المشروع')).toBeInTheDocument();
    expect(screen.getByText('Project')).toBeInTheDocument();
    expect(screen.getByText('admin.scopeTypes.targetRequirements.required')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'admin.scopeTypes.add' })).not.toBeInTheDocument();
    expect(screen.queryByPlaceholderText('admin.scopeTypes.searchPlaceholder')).not.toBeInTheDocument();
  });
});
