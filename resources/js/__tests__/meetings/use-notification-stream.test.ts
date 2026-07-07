import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { renderHook, act } from '@testing-library/react';
import { useNotificationStream } from '@features/meetings/components/useNotificationStream';
import { notificationsApi } from '@features/meetings/api';

vi.mock('@features/meetings/api', () => ({
  notificationsApi: {
    unreadCount: vi.fn(),
  },
}));

describe('useNotificationStream', () => {
  beforeEach(() => {
    vi.useFakeTimers();
    vi.mocked(notificationsApi.unreadCount).mockResolvedValue({ unread: 3 } as never);
  });

  afterEach(() => {
    vi.restoreAllMocks();
    vi.useRealTimers();
  });

  it('fetches unread count on mount', async () => {
    const { result } = renderHook(() => useNotificationStream());
    // Flush initial async fetch
    await act(async () => {
      await Promise.resolve();
    });
    expect(result.current.unread).toBe(3);
  });

  it('polls at the given interval', async () => {
    renderHook(() => useNotificationStream(1000));
    // Flush initial fetch
    await act(async () => {
      await Promise.resolve();
    });
    expect(notificationsApi.unreadCount).toHaveBeenCalledTimes(1);
    // Advance one interval + flush the resulting async call
    await act(async () => {
      vi.advanceTimersByTime(1000);
      await Promise.resolve();
    });
    expect(notificationsApi.unreadCount).toHaveBeenCalledTimes(2);
  });

  it('does not fetch when disabled', () => {
    renderHook(() => useNotificationStream(60_000, false));
    expect(notificationsApi.unreadCount).not.toHaveBeenCalled();
  });
});
