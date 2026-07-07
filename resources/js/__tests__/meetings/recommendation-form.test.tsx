import { describe, it, expect, vi, beforeEach } from 'vitest';
import React from 'react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';

vi.mock('@shared/contexts/AuthContext', () => ({
  useAuth: () => ({
    user: {
      id: 7,
      name: 'أحمد',
      permissions: ['meetings.record_decisions'],
      access: {
        meetings: { view: true, create: true, edit: true, delete: true, record_decisions: true },
      },
    },
    hasPermission: (p: string) => p === 'meetings.record_decisions',
    canAccess: () => true,
    isAdmin: () => false,
  }),
}));

vi.mock('@features/meetings/api', () => ({
  recommendationsApi: {
    create: vi.fn().mockResolvedValue({
      id: 99,
      reference_number: 'REC-2026-0099',
      status: 'proposed',
    }),
    update: vi.fn(),
    getAll: vi.fn().mockResolvedValue({ data: [] }),
  },
}));

vi.mock('@entities/strategy', () => ({
  decisionsApi: {
    getAll: vi.fn().mockResolvedValue({ data: [] }),
  },
}));

vi.mock('@shared/ui/Toast', () => ({
  useToast: () => ({ showToast: vi.fn() }),
  ToastProvider: ({ children }: { children: React.ReactNode }) => <>{children}</>,
  ToastContainer: () => null,
}));

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (k: string) => k }),
}));

import { recommendationsApi } from '@features/meetings/api';
import RecommendationForm from '@pages/strategy/meetings/recommendations/RecommendationForm';

describe('RecommendationForm', () => {
  beforeEach(() => vi.clearAllMocks());

  it('calls create when submitting a new recommendation', async () => {
    render(
      <MemoryRouter>
        <RecommendationForm mode="page" prefill={{ decision_id: 18 }} />
      </MemoryRouter>,
    );
    const titleInput = screen.getByRole('textbox', { name: /title/i });
    fireEvent.change(titleInput, {
      target: { value: 'توصية جديدة' },
    });
    const form = titleInput.closest('form') as HTMLFormElement;
    fireEvent.submit(form);
    await waitFor(() => expect(recommendationsApi.create).toHaveBeenCalled());
  });
});
