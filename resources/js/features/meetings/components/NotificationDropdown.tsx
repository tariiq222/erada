import { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { IconBell, IconCheck, IconChecks } from '@shared/ui/icons';
import { notificationsApi } from '@features/meetings/api';
import type { Notification } from '@features/meetings/types';

interface NotificationDropdownProps {
  onClose: () => void;
  onRead: () => void;
}

export function NotificationDropdown({ onClose, onRead }: NotificationDropdownProps) {
  const { t } = useTranslation();
  const [notifications, setNotifications] = useState<Notification[]>([]);
  const [isLoading, setIsLoading] = useState(true);

  useEffect(() => {
    let cancelled = false;
    notificationsApi
      .getAll({ per_page: 10 })
      .then((res) => {
        if (!cancelled) setNotifications(res.data ?? []);
      })
      .catch(() => {
        if (!cancelled) setNotifications([]);
      })
      .finally(() => {
        if (!cancelled) setIsLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, []);

  const handleMarkRead = async (id: string) => {
    await notificationsApi.markRead(id);
    setNotifications((prev) =>
      prev.map((n) => (n.id === id ? { ...n, read_at: new Date().toISOString() } : n)),
    );
    onRead();
  };

  const handleMarkAllRead = async () => {
    await notificationsApi.markAllRead();
    setNotifications((prev) =>
      prev.map((n) => ({ ...n, read_at: n.read_at ?? new Date().toISOString() })),
    );
    onRead();
  };

  return (
    <div
      role="dialog"
      aria-label={t('meetings.notification.dropdown_label')}
      className="absolute top-full end-0 mt-2 w-80 sm:w-96 bg-[var(--surface-base)] rounded-xl shadow-lg border border-[var(--border-default)] overflow-hidden z-50"
    >
      {/* Header */}
      <div className="flex items-center justify-between px-4 py-3 border-b border-[var(--border-default)]">
        <h2 className="text-sm font-semibold text-[var(--text-primary)]">
          {t('meetings.notification.label')}
        </h2>
        <button
          type="button"
          onClick={handleMarkAllRead}
          className="flex items-center gap-1.5 text-xs text-[var(--accent-default)] hover:text-[var(--accent-emphasis)] transition-colors"
          aria-label={t('meetings.notification.mark_all_read')}
        >
          <IconChecks className="h-3.5 w-3.5" aria-hidden="true" />
          {t('meetings.notification.mark_all_read')}
        </button>
      </div>

      {/* Body */}
      <div className="max-h-80 overflow-y-auto">
        {isLoading ? (
          <div className="px-4 py-8 text-center text-sm text-[var(--text-tertiary)]">
            {t('common.loading', { defaultValue: 'جارٍ التحميل...' })}
          </div>
        ) : notifications.length === 0 ? (
          <div className="flex flex-col items-center gap-2 px-4 py-10 text-[var(--text-tertiary)]">
            <IconBell className="h-8 w-8 opacity-40" aria-hidden="true" />
            <p className="text-sm">{t('meetings.notification.empty')}</p>
          </div>
        ) : (
          <ul role="list">
            {notifications.map((notification) => (
              <li
                key={notification.id}
                className={`relative flex gap-3 px-4 py-3 border-b border-[var(--border-subtle)] last:border-b-0 transition-colors ${
                  notification.read_at
                    ? 'bg-[var(--surface-base)]'
                    : 'bg-[var(--accent-subtle)]'
                }`}
              >
                {!notification.read_at && (
                  <span
                    aria-hidden="true"
                    className="absolute start-2 top-1/2 -translate-y-1/2 h-1.5 w-1.5 rounded-full bg-[var(--accent-default)]"
                  />
                )}
                <div className="flex-1 min-w-0 ps-2">
                  <p className="text-xs text-[var(--text-secondary)] line-clamp-2">
                    {notification.data.message}
                  </p>
                  <p className="mt-1 text-xs text-[var(--text-tertiary)]">
                    {new Date(notification.created_at).toLocaleDateString()}
                  </p>
                </div>
                {!notification.read_at && (
                  <button
                    type="button"
                    onClick={() => handleMarkRead(notification.id)}
                    className="shrink-0 h-7 w-7 inline-flex items-center justify-center rounded-lg text-[var(--text-tertiary)] hover:text-[var(--accent-default)] hover:bg-[var(--surface-muted)] transition-colors"
                    aria-label={t('meetings.notification.mark_as_read')}
                  >
                    <IconCheck className="h-3.5 w-3.5" aria-hidden="true" />
                  </button>
                )}
              </li>
            ))}
          </ul>
        )}
      </div>

      {/* Footer */}
      <div className="px-4 py-3 border-t border-[var(--border-default)] text-center">
        <Link
          to="/strategy/meetings/notifications"
          onClick={onClose}
          className="text-xs font-medium text-[var(--accent-default)] hover:text-[var(--accent-emphasis)] transition-colors"
        >
          {t('meetings.notification.view_all')}
        </Link>
      </div>
    </div>
  );
}
