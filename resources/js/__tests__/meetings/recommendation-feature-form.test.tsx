import { describe, expect, it, vi } from 'vitest';
import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';

const mocks = vi.hoisted(() => ({
  create: vi.fn(),
  meetings: vi.fn(),
  toast: vi.fn(),
}));

vi.mock('@features/meetings/api', () => ({
  meetingsApi: { getAll: mocks.meetings },
  recommendationsApi: { create: mocks.create, update: vi.fn() },
}));
vi.mock('@entities/user', () => ({ usersApi: { getList: vi.fn() } }));
vi.mock('@shared/ui/Toast', () => ({ useToast: () => ({ showToast: mocks.toast }) }));
vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: (key: string, options?: { defaultValue?: string }) => options?.defaultValue ?? key }) }));

import RecommendationForm from '@features/meetings/RecommendationForm';

describe('RecommendationForm request contract', () => {
  it('sends priority when submitting a ruling', async () => {
    mocks.meetings.mockResolvedValue({ data: [] });
    mocks.create.mockResolvedValue({ id: 41, title: 'قرار' });

    render(
      <RecommendationForm
        prefill={{ meeting_id: 7 }}
        initial={{ kind: 'ruling', title: 'قرار', type: 'approval', priority: 'high' }}
      />,
    );

    fireEvent.click(screen.getByRole('button', {
      name: 'meetings.recommendation.form.submit_create',
    }));

    await waitFor(() => {
      expect(mocks.create).toHaveBeenCalledWith(expect.objectContaining({
        meeting_id: 7,
        kind: 'ruling',
        priority: 'high',
      }));
    });
  });
});
