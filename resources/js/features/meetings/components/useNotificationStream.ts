import { useState, useEffect, useCallback } from 'react';
import { notificationsApi } from '@features/meetings/api';

export interface NotificationStreamState {
  unread: number;
  isLoading: boolean;
  error: string | null;
  refresh: () => void;
}

export function useNotificationStream(
  intervalMs = 60_000,
  enabled = true,
): NotificationStreamState {
  const [unread, setUnread] = useState(0);
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const refresh = useCallback(async () => {
    setIsLoading(true);
    setError(null);
    try {
      const res = await notificationsApi.unreadCount();
      setUnread(res.unread ?? 0);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to fetch notifications');
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    if (!enabled) return;
    refresh();
    const id = setInterval(refresh, intervalMs);
    return () => clearInterval(id);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [intervalMs, enabled]);

  return { unread, isLoading, error, refresh };
}
