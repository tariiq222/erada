import { describe, it, expect, vi, beforeEach } from 'vitest';
import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';

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
    getOne: vi.fn(),
    delete: vi.fn(),
    accept: vi.fn(),
    reject: vi.fn(),
    defer: vi.fn(),
    complete: vi.fn(),
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
import RecommendationView from '@pages/strategy/meetings/recommendations/RecommendationView';

const mockRec = {
  id: 11,
  reference_number: 'REC-2026-0011',
  decision_id: 18,
  title: 'إعادة جدولة المرحلة 2',
  description: 'وصف',
  priority: 'high',
  status: 'accepted',
  assignee_id: 7,
  due_date: '2026-07-15',
  completed_at: null,
  organization_id: 1,
  created_at: '2026-06-19T10:00:00Z',
  updated_at: '2026-06-19T10:00:00Z',
  status_label: 'مقبول',
  priority_label: 'عالية',
  is_overdue: false,
  decision: { id: 18, title: 'قرار', reference_number: 'DEC-2026-0018' },
  assignee: { id: 7, name: 'أحمد' },
};

describe('RecommendationView', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    (recommendationsApi.getOne as ReturnType<typeof vi.fn>).mockResolvedValue(mockRec);
  });

  it('renders the recommendation title', async () => {
    render(
      <MemoryRouter initialEntries={['/strategy/meetings/recommendations/11']}>
        <Routes>
          <Route
            path="/strategy/meetings/recommendations/:id"
            element={<RecommendationView />}
          />
        </Routes>
      </MemoryRouter>,
    );
    await waitFor(() =>
      expect(screen.getByText('إعادة جدولة المرحلة 2')).toBeInTheDocument(),
    );
  });
});
