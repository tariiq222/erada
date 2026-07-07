import { describe, it, expect, vi, beforeEach } from 'vitest';
import { act, renderHook, waitFor } from '@testing-library/react';

vi.mock('@features/meetings/api', () => ({
  meetingsApi: {
    getAll: vi.fn(),
    create: vi.fn(),
    update: vi.fn(),
    delete: vi.fn(),
    attachAttendees: vi.fn(),
    detachAttendee: vi.fn(),
    start: vi.fn(),
    complete: vi.fn(),
    cancel: vi.fn(),
  },
}));

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, params?: Record<string, string>) =>
      params ? key.replace(/\{(\w+)\}/g, (_, k) => params[k]) : key,
  }),
}));

import { meetingsApi } from '@features/meetings/api';
import { useMeetingsSection } from '@features/meetings/components/useMeetingsSection';

const mockMeetings = {
  data: [
    {
      id: 1,
      reference_number: 'MTG-2026-0001',
      title: 'اجتماع اختبار',
      description: null,
      scheduled_at: '2026-06-22T09:00:00Z',
      duration_minutes: 60,
      location: null,
      virtual_link: null,
      agenda: null,
      minutes: null,
      status: 'scheduled',
      organizer_id: 7,
      subject_type: 'project',
      subject_id: 42,
      organization_id: 1,
      created_at: '2026-06-19T10:00:00Z',
      updated_at: '2026-06-19T10:00:00Z',
      status_label: 'مجدول',
      organizer: { id: 7, name: 'أحمد' },
    },
  ],
  current_page: 1,
  last_page: 1,
  per_page: 5,
  total: 1,
};

describe('useMeetingsSection', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    (meetingsApi.getAll as ReturnType<typeof vi.fn>).mockResolvedValue(mockMeetings);
  });

  it('fetches meetings scoped to subject_type + subject_id', async () => {
    const { result } = renderHook(() =>
      useMeetingsSection({ subject_type: 'project', subject_id: 42 })
    );
    await waitFor(() => expect(result.current.loading).toBe(false));
    expect(meetingsApi.getAll).toHaveBeenCalledWith(
      expect.objectContaining({ subject_type: 'project', subject_id: 42, per_page: '5' })
    );
    expect(result.current.meetings).toHaveLength(1);
  });

  it('refetches when refetch() is called', async () => {
    const { result } = renderHook(() =>
      useMeetingsSection({ subject_type: 'project', subject_id: 42 })
    );
    await waitFor(() => expect(result.current.meetings).toHaveLength(1));
    await act(async () => {
      await result.current.refetch();
    });
    expect(meetingsApi.getAll).toHaveBeenCalledTimes(2);
  });
});
