import { act, renderHook, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

const mocks = vi.hoisted(() => ({
  getOne: vi.fn(),
  complete: vi.fn(),
  toast: vi.fn(),
  navigate: vi.fn(),
}));

vi.mock('@features/meetings/api', () => ({
  recommendationsApi: {
    getOne: mocks.getOne,
    complete: mocks.complete,
    approve: vi.fn(), accept: vi.fn(), reject: vi.fn(), defer: vi.fn(), delete: vi.fn(),
  },
}));
vi.mock('@shared/ui/Toast', () => ({ useToast: () => ({ showToast: mocks.toast }) }));
vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: (key: string) => key }) }));
vi.mock('react-router-dom', () => ({ useNavigate: () => mocks.navigate }));

import { useRecommendationView } from '@pages/strategy/meetings/recommendations/view/useRecommendationView';

describe('useRecommendationView transitions', () => {
  it('handles a plain API error instead of rejecting the action promise', async () => {
    mocks.getOne.mockResolvedValue({ id: 11, title: 'توصية' });
    mocks.complete.mockRejectedValue({ message: 'لا يمكن الإنجاز قبل إغلاق المهام' });

    const { result } = renderHook(() => useRecommendationView('11'));
    await waitFor(() => expect(result.current.recommendation?.id).toBe(11));

    await act(async () => { await expect(result.current.complete()).resolves.toBeUndefined(); });
    expect(mocks.toast).toHaveBeenCalledWith('error', 'لا يمكن الإنجاز قبل إغلاق المهام');
  });
});
