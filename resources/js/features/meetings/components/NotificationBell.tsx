import { useState, useRef, useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { IconBell } from '@shared/ui/icons';
import { useNotificationStream } from './useNotificationStream';
import { NotificationDropdown } from './NotificationDropdown';

export function NotificationBell() {
  const { t } = useTranslation();
  const [open, setOpen] = useState(false);
  const containerRef = useRef<HTMLDivElement>(null);
  const { unread, refresh } = useNotificationStream();

  useEffect(() => {
    const handleClickOutside = (e: MouseEvent) => {
      if (containerRef.current && !containerRef.current.contains(e.target as Node)) {
        setOpen(false);
      }
    };
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  return (
    <div className="relative" ref={containerRef}>
      <button
        type="button"
        aria-label={t('meetings.notification.label')}
        aria-haspopup="dialog"
        aria-expanded={open}
        onClick={() => setOpen((prev) => !prev)}
        className="relative h-11 w-11 shrink-0 inline-flex items-center justify-center rounded-lg sm:rounded-xl hover:bg-[var(--surface-muted)] text-[var(--text-tertiary)] hover:text-[var(--text-secondary)] transition-colors duration-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--accent-default)] focus-visible:ring-offset-2 focus-visible:ring-offset-[var(--surface-base)]"
      >
        <IconBell className="h-4 w-4 sm:h-5 sm:w-5" aria-hidden="true" />
        {unread > 0 && (
          <span
            aria-hidden="true"
            className="absolute top-1.5 sm:top-2 end-1.5 sm:end-2 flex h-2 w-2 sm:h-2.5 sm:w-2.5"
          >
            <span className="motion-safe:animate-ping motion-reduce:hidden absolute inline-flex h-full w-full rounded-full bg-[var(--status-danger-subtle)] opacity-75" />
            <span className="relative inline-flex rounded-full h-2 w-2 sm:h-2.5 sm:w-2.5 bg-[var(--status-danger-subtle)]" />
          </span>
        )}
        {unread > 0 && (
          <span className="sr-only">
            {t('meetings.notification.unread_summary', { count: unread })}
          </span>
        )}
      </button>

      {open && (
        <NotificationDropdown
          onClose={() => setOpen(false)}
          onRead={refresh}
        />
      )}
    </div>
  );
}
