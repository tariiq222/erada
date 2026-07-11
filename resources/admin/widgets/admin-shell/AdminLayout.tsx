import { useEffect, useState } from 'react';
import { Outlet } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { IconX } from '@tabler/icons-react';
import { useLocale } from '@shared/contexts/LocaleContext';
import { useTheme } from '@shared/contexts/ThemeContext';
import { AdminHeader } from '@admin/widgets/admin-shell/AdminHeader';
import { AdminNavigation } from '@admin/widgets/admin-shell/AdminNavigation';

export function AdminLayout() {
  const { i18n } = useTranslation();
  const { direction } = useLocale();
  const { resolvedTheme } = useTheme();
  const [mobileOpen, setMobileOpen] = useState(false);

  useEffect(() => {
    document.documentElement.dir = direction;
    document.documentElement.lang = i18n.language;
    document.documentElement.dataset.theme = resolvedTheme;
  }, [direction, i18n.language, resolvedTheme]);

  return (
    <div
      className="flex min-h-screen flex-col bg-[var(--surface-base)] text-[var(--text-primary)]"
      dir={direction}
      data-testid="admin-control-plane-shell"
    >
      <AdminHeader onOpenMenu={() => setMobileOpen(true)} />
      <div className="flex min-h-0 flex-1">
        <aside className="hidden w-72 shrink-0 border-e border-[var(--border-default)] bg-[var(--surface-raised)] lg:flex">
          <AdminNavigation mode="desktop" />
        </aside>

        {mobileOpen && (
          <div className="fixed inset-0 z-50 lg:hidden" role="dialog" aria-modal="true" aria-label={i18n.t('admin.shell.sidebar.aria')}>
            <button
              type="button"
              aria-label={i18n.t('common.close')}
              className="absolute inset-0 bg-black/40"
              onClick={() => setMobileOpen(false)}
            />
            <aside className="absolute inset-y-0 start-0 flex w-[min(88vw,22rem)] flex-col border-e border-[var(--border-default)] bg-[var(--surface-raised)] shadow-xl">
              <div className="flex min-h-16 items-center justify-between border-b border-[var(--border-default)] px-4">
                <span className="font-bold">{i18n.t('admin.shell.brand')}</span>
                <button
                  type="button"
                  aria-label={i18n.t('common.close')}
                  onClick={() => setMobileOpen(false)}
                  className="inline-flex h-10 w-10 items-center justify-center rounded-lg text-[var(--text-secondary)]"
                >
                  <IconX className="h-5 w-5" />
                </button>
              </div>
              <AdminNavigation mode="mobile" onNavigate={() => setMobileOpen(false)} />
            </aside>
          </div>
        )}

        <main className="min-w-0 flex-1 overflow-y-auto" data-testid="admin-shell-main">
          <Outlet />
        </main>
      </div>
    </div>
  );
}
