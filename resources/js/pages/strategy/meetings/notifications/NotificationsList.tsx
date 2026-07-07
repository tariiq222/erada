import { useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { IconBell, IconCheck, IconChecks, IconChevronLeft, IconChevronRight } from '@shared/ui/icons';
import { useNotificationsList } from './list/useNotificationsList';

export function NotificationsList() {
  const { t } = useTranslation();
  const {
    notifications,
    isLoading,
    error,
    currentPage,
    lastPage,
    total,
    unreadOnly,
    loadPage,
    setUnreadOnly,
    markRead,
    markAllRead,
  } = useNotificationsList();

  useEffect(() => {
    loadPage(1);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  return (
    <div className="max-w-3xl mx-auto px-4 py-8">
      {/* Page Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <div className="flex items-center gap-3">
          <div className="h-10 w-10 rounded-xl bg-[var(--accent-subtle)] flex items-center justify-center">
            <IconBell className="h-5 w-5 text-[var(--accent-default)]" aria-hidden="true" />
          </div>
          <div>
            <h1 className="text-xl font-semibold text-[var(--text-primary)]">
              {t('meetings.notification.page_title')}
            </h1>
            {total > 0 && (
              <p className="text-sm text-[var(--text-tertiary)]">
                {t('meetings.notification.total_count', { count: total })}
              </p>
            )}
          </div>
        </div>

        <div className="flex items-center gap-2">
          {/* Unread only toggle */}
          <label className="flex items-center gap-2 cursor-pointer select-none text-sm text-[var(--text-secondary)]">
            <input
              type="checkbox"
              checked={unreadOnly}
              onChange={(e) => setUnreadOnly(e.target.checked)}
              className="h-4 w-4 rounded border border-[var(--border-default)] accent-[var(--accent-default)]"
              aria-label={t('meetings.notification.unread_only')}
            />
            {t('meetings.notification.unread_only')}
          </label>

          {/* Mark all read */}
          <button
            type="button"
            onClick={markAllRead}
            className="flex items-center gap-1.5 px-3 py-2 text-sm rounded-lg border border-[var(--border-default)] text-[var(--text-secondary)] hover:bg-[var(--surface-muted)] transition-colors"
            aria-label={t('meetings.notification.mark_all_read')}
          >
            <IconChecks className="h-4 w-4" aria-hidden="true" />
            {t('meetings.notification.mark_all_read')}
          </button>
        </div>
      </div>

      {/* Content */}
      {error ? (
        <div className="rounded-xl border border-[var(--status-danger-subtle)] bg-[var(--status-danger-subtle)] px-4 py-3 text-sm text-[var(--status-danger)]">
          {error}
        </div>
      ) : isLoading ? (
        <div className="py-20 text-center text-sm text-[var(--text-tertiary)]">
          {t('common.loading', { defaultValue: 'جارٍ التحميل...' })}
        </div>
      ) : notifications.length === 0 ? (
        <div className="flex flex-col items-center gap-3 py-20 text-[var(--text-tertiary)]">
          <IconBell className="h-12 w-12 opacity-30" aria-hidden="true" />
          <p className="text-base">{t('meetings.notification.empty')}</p>
        </div>
      ) : (
        <div className="rounded-xl border border-[var(--border-default)] overflow-hidden">
          <ul role="list" className="divide-y divide-[var(--border-subtle)]">
            {notifications.map((notification) => (
              <li
                key={notification.id}
                className={`relative flex gap-3 px-4 py-4 transition-colors ${
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
                  <p className="text-sm text-[var(--text-primary)] line-clamp-3">
                    {notification.data.message}
                  </p>
                  <p className="mt-1 text-xs text-[var(--text-tertiary)]">
                    {new Date(notification.created_at).toLocaleString()}
                  </p>
                </div>
                {!notification.read_at && (
                  <button
                    type="button"
                    onClick={() => markRead(notification.id)}
                    className="shrink-0 h-8 w-8 inline-flex items-center justify-center rounded-lg text-[var(--text-tertiary)] hover:text-[var(--accent-default)] hover:bg-[var(--surface-muted)] transition-colors"
                    aria-label={t('meetings.notification.mark_as_read')}
                  >
                    <IconCheck className="h-4 w-4" aria-hidden="true" />
                  </button>
                )}
              </li>
            ))}
          </ul>
        </div>
      )}

      {/* Pagination */}
      {lastPage > 1 && (
        <nav
          aria-label={t('meetings.notification.pagination')}
          className="flex items-center justify-between mt-6"
        >
          <button
            type="button"
            onClick={() => loadPage(currentPage - 1)}
            disabled={currentPage <= 1}
            className="flex items-center gap-1.5 px-3 py-2 text-sm rounded-lg border border-[var(--border-default)] text-[var(--text-secondary)] hover:bg-[var(--surface-muted)] disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
          >
            <IconChevronRight className="h-4 w-4" aria-hidden="true" />
            {t('common.previous')}
          </button>

          <p className="text-sm text-[var(--text-tertiary)]">
            {t('common.page_x_of_y', { current: currentPage, total: lastPage })}
          </p>

          <button
            type="button"
            onClick={() => loadPage(currentPage + 1)}
            disabled={currentPage >= lastPage}
            className="flex items-center gap-1.5 px-3 py-2 text-sm rounded-lg border border-[var(--border-default)] text-[var(--text-secondary)] hover:bg-[var(--surface-muted)] disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
          >
            {t('common.next')}
            <IconChevronLeft className="h-4 w-4" aria-hidden="true" />
          </button>
        </nav>
      )}
    </div>
  );
}

export default NotificationsList;
