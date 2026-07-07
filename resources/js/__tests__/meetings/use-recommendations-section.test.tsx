import { describe, it, expect, vi, beforeEach } from 'vitest';
import { act, renderHook, waitFor } from '@testing-library/react';

vi.mock('@features/meetings/api', () => ({
  recommendationsApi: {
    getAll: vi.fn(),
    create: vi.fn(),
    update: vi.fn(),
    delete: vi.fn(),
    accept: vi.fn(),
    reject: vi.fn(),
    defer: vi.fn(),
    complete: vi.fn(),
  },
}));

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (k: string) => k }),
}));

import { recommendationsApi } from '@features/meetings/api';
import { useRecommendationsSection } from '@features/meetings/components/useRecommendationsSection';

const mock = {
  data: [
    {
      id: 1,
      reference_number: 'REC-2026-0001',
      decision_id: 18,
      title: 'توصية',
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
  per_page: 5,
  total: 1,
};

describe('useRecommendationsSection', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    (recommendationsApi.getAll as ReturnType<typeof vi.fn>).mockResolvedValue(mock);
  });

  it('fetches recommendations scoped to decision_id', async () => {
    const { result } = renderHook(() => useRecommendationsSection({ decision_id: 18 }));
    await waitFor(() => expect(result.current.loading).toBe(false));
    expect(recommendationsApi.getAll).toHaveBeenCalledWith(
      expect.objectContaining({ decision_id: '18', per_page: '5' }),
    );
    expect(result.current.recommendations).toHaveLength(1);
  });

  it('refetches when refetch() is called', async () => {
    const { result } = renderHook(() => useRecommendationsSection({ decision_id: 18 }));
    await waitFor(() => expect(result.current.recommendations).toHaveLength(1));
    await act(async () => {
      await result.current.refetch();
    });
    expect(recommendationsApi.getAll).toHaveBeenCalledTimes(2);
  });
});
