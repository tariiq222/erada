import { describe, expect, it, vi } from 'vitest';
import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';

const mocks = vi.hoisted(() => ({
  getOne: vi.fn(),
}));

vi.mock('@features/meetings/api', () => ({
  recommendationsApi: { getOne: mocks.getOne },
}));

vi.mock('@features/meetings/RecommendationForm', () => ({
  default: ({ initial, prefill }: { initial?: { title?: string }; prefill?: { meeting_id?: number } }) => (
    <div>
      <span data-testid="initial-title">{initial?.title ?? 'new recommendation'}</span>
      <span data-testid="prefill-meeting-id">{prefill?.meeting_id ?? ''}</span>
    </div>
  ),
}));

vi.mock('@shared/ui/Toast', () => ({ useToast: () => ({ showToast: vi.fn() }) }));
vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: (key: string) => key }) }));

import RecommendationForm from '@pages/strategy/meetings/recommendations/RecommendationForm';

describe('RecommendationForm route wrapper', () => {
  it('loads the route recommendation before editing it', async () => {
    mocks.getOne.mockResolvedValue({
      id: 99,
      title: 'توصية قائمة',
      allowed_actions: { update: true },
    });

    render(
      <MemoryRouter initialEntries={['/strategy/meetings/recommendations/99/edit']}>
        <Routes>
          <Route path="/strategy/meetings/recommendations/:id/edit" element={<RecommendationForm mode="page" />} />
        </Routes>
      </MemoryRouter>,
    );

    await waitFor(() => {
      expect(mocks.getOne).toHaveBeenCalledWith(99);
      expect(screen.getByText('توصية قائمة')).toBeInTheDocument();
    });
  });

  it('fails closed when the record is not editable', async () => {
    mocks.getOne.mockResolvedValue({
      id: 100,
      title: 'للقراءة فقط',
      allowed_actions: { update: false },
    });

    render(
      <MemoryRouter initialEntries={['/strategy/meetings/recommendations/100/edit']}>
        <Routes>
          <Route path="/strategy/meetings/recommendations/:id/edit" element={<RecommendationForm mode="page" />} />
        </Routes>
      </MemoryRouter>,
    );

    await waitFor(() => {
      expect(mocks.getOne).toHaveBeenCalledWith(100);
      expect(screen.queryByText('للقراءة فقط')).toBeNull();
      expect(screen.queryByText('new recommendation')).toBeNull();
    });
  });

  it('forwards meeting_id from the query string into the form prefill', async () => {
    mocks.getOne.mockResolvedValue({
      id: 5,
      title: '—',
      allowed_actions: { update: true },
    });

    render(
      <MemoryRouter initialEntries={['/strategy/meetings/recommendations/new?meeting_id=42']}>
        <Routes>
          <Route path="/strategy/meetings/recommendations/new" element={<RecommendationForm mode="page" />} />
        </Routes>
      </MemoryRouter>,
    );

    await waitFor(() => {
      expect(screen.getByTestId('prefill-meeting-id')).toHaveTextContent('42');
      expect(screen.getByTestId('initial-title')).toHaveTextContent('new recommendation');
    });
  });
});
