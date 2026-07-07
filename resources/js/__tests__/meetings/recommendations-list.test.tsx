import { describe, it, expect, vi } from 'vitest';
import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';

vi.mock('@shared/contexts/AuthContext', () => ({
  useAuth: () => ({
    user: {
      id: 7,
      name: 'أحمد',
      permissions: ['meetings.view', 'meetings.record_decisions'],
      access: {
        meetings: { view: true, record_decisions: true },
      },
    },
    hasPermission: (p: string) =>
      ['meetings.view', 'meetings.record_decisions'].includes(p),
    canAccess: () => true,
    isAdmin: () => false,
  }),
}));

vi.mock('@features/meetings/api', () => ({
  recommendationsApi: {
    getAll: vi.fn().mockResolvedValue({
      data: [
        {
          id: 1,
          reference_number: 'REC-2026-0001',
          decision_id: 18,
          title: 'توصية اختبار',
          description: null,
          priority: 'medium',
          status: 'proposed',
          assignee_id: 7,
          due_date: '2026-07-15',
          completed_at: null,
          organization_id: 1,
          created_at: '2026-06-19T10:00:00Z',
          updated_at: '2026-06-19T10:00:00Z',
          status_label: 'مقترح',
          priority_label: 'متوسطة',
          is_overdue: false,
          decision: { id: 18, title: 'قرار', reference_number: 'DEC-2026-0018' },
          assignee: { id: 7, name: 'أحمد' },
        },
      ],
      current_page: 1,
      last_page: 1,
      per_page: 15,
      total: 1,
    }),
  },
}));

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (k: string) => k }),
}));

import RecommendationsList from '@pages/strategy/meetings/recommendations/RecommendationsList';

describe('RecommendationsList', () => {
  it('renders the recommendations table with one row', async () => {
    render(
      <MemoryRouter>
        <RecommendationsList />
      </MemoryRouter>,
    );
    await waitFor(() => expect(screen.getByText('توصية اختبار')).toBeInTheDocument());
    expect(screen.getByText('REC-2026-0001')).toBeInTheDocument();
  });
});
