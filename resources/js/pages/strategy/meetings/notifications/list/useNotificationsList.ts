import { useState, useCallback } from 'react';
import { notificationsApi } from '@features/meetings/api';
import type { Notification, NotificationListParams, NotificationType } from '@features/meetings/types';

interface NotificationsListState {
  notifications: Notification[];
  isLoading: boolean;
  error: string | null;
  currentPage: number;
  lastPage: number;
  total: number;
  unreadOnly: boolean;
  typeFilter: NotificationType | undefined;
}

interface NotificationsListActions {
  loadPage: (page: number) => void;
  setUnreadOnly: (v: boolean) => void;
  setTypeFilter: (v: NotificationType | undefined) => void;
  markRead: (id: string) => Promise<void>;
  markAllRead: () => Promise<void>;
  refresh: () => void;
}

export function useNotificationsList(): NotificationsListState & NotificationsListActions {
  const [notifications, setNotifications] = useState<Notification[]>([]);
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [currentPage, setCurrentPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [total, setTotal] = useState(0);
  const [unreadOnly, setUnreadOnly] = useState(false);
  const [typeFilter, setTypeFilter] = useState<NotificationType | undefined>(undefined);

  const fetchNotifications = useCallback(
    async (params: NotificationListParams) => {
      setIsLoading(true);
      setError(null);
      try {
        const res = await notificationsApi.getAll(params);
        setNotifications(res.data ?? []);
        setCurrentPage(res.meta.current_page);
        setLastPage(res.meta.last_page);
        setTotal(res.meta.total);
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Failed to load notifications');
      } finally {
        setIsLoading(false);
      }
    },
    [],
  );

  const loadPage = useCallback(
    (page: number) => {
      fetchNotifications({ page, unread_only: unreadOnly, type: typeFilter, per_page: 20 });
    },
    [fetchNotifications, unreadOnly, typeFilter],
  );

  const refresh = useCallback(() => {
    loadPage(currentPage);
  }, [loadPage, currentPage]);

  const markRead = useCallback(async (id: string) => {
    await notificationsApi.markRead(id);
    setNotifications((prev) =>
      prev.map((n) => (n.id === id ? { ...n, read_at: new Date().toISOString() } : n)),
    );
  }, []);

  const markAllRead = useCallback(async () => {
    await notificationsApi.markAllRead();
    setNotifications((prev) =>
      prev.map((n) => ({ ...n, read_at: n.read_at ?? new Date().toISOString() })),
    );
  }, []);

  const handleSetUnreadOnly = useCallback(
    (v: boolean) => {
      setUnreadOnly(v);
      fetchNotifications({ page: 1, unread_only: v, type: typeFilter, per_page: 20 });
    },
    [fetchNotifications, typeFilter],
  );

  const handleSetTypeFilter = useCallback(
    (v: NotificationType | undefined) => {
      setTypeFilter(v);
      fetchNotifications({ page: 1, unread_only: unreadOnly, type: v, per_page: 20 });
    },
    [fetchNotifications, unreadOnly],
  );

  return {
    notifications,
    isLoading,
    error,
    currentPage,
    lastPage,
    total,
    unreadOnly,
    typeFilter,
    loadPage,
    setUnreadOnly: handleSetUnreadOnly,
    setTypeFilter: handleSetTypeFilter,
    markRead,
    markAllRead,
    refresh,
  };
}
