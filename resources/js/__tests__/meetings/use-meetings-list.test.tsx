import { describe, it, expect, vi, beforeEach } from 'vitest';
import { renderHook, act, waitFor } from '@testing-library/react';

vi.mock('@features/meetings/api', () => ({
  meetingsApi: { getAll: vi.fn(), create: vi.fn(), update: vi.fn(), delete: vi.fn() },
}));

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (k: string) => k }),
}));

import { meetingsApi } from '@features/meetings/api';
import { useMeetingsList } from '@pages/strategy/meetings/list/useMeetingsList';

const mockResponse = {
  data: [{
    id: 1, reference_number: 'MTG-2026-0001', title: 'اجتماع',
    description: null, scheduled_at: '2026-06-22T09:00:00Z', duration_minutes: 60,
    location: null, virtual_link: null, agenda: null, minutes: null,
    status: 'scheduled' as const, organizer_id: 7, subject_type: 'project', subject_id: 42,
    organization_id: 1, created_at: '2026-06-19T10:00:00Z', updated_at: '2026-06-19T10:00:00Z',
    status_label: 'مجدول', organizer: { id: 7, name: 'أحمد' },
  }],
  current_page: 1, last_page: 1, per_page: 15, total: 1,
};

describe('useMeetingsList', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    (meetingsApi.getAll as ReturnType<typeof vi.fn>).mockResolvedValue(mockResponse);
  });

  it('fetches meetings on mount', async () => {
    const { result } = renderHook(() => useMeetingsList());
    await waitFor(() => expect(result.current.meetings).toHaveLength(1));
    expect(result.current.pagination.total).toBe(1);
  });

  it('refetches when status filter changes', async () => {
    const { result } = renderHook(() => useMeetingsList());
    await waitFor(() => expect(result.current.meetings).toHaveLength(1));
    await act(async () => {
      result.current.setFilter('status', 'scheduled');
    });
    await waitFor(() => expect(meetingsApi.getAll).toHaveBeenCalledTimes(2));
    expect((meetingsApi.getAll as ReturnType<typeof vi.fn>).mock.calls.at(-1)![0])
      .toMatchObject({ status: 'scheduled' });
  });
});
