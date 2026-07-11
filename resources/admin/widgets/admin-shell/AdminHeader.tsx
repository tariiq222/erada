import { Link, useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { IconLanguage, IconLogout, IconMenu2, IconMoon, IconShieldLock } from '@tabler/icons-react';
import { useAuth } from '@shared/contexts/AuthContext';
import { useLocale } from '@shared/contexts/LocaleContext';
import { useTheme } from '@shared/contexts/ThemeContext';

export function AdminHeader({ onOpenMenu }: { onOpenMenu: () => void }) {
  const { t } = useTranslation();
  const { user, logout } = useAuth();
  const { locale, setLocale } = useLocale();
  const { toggleTheme } = useTheme();
  const navigate = useNavigate();

  return (
    <header className="border-b border-[var(--border-default)] bg-[var(--surface-raised)]" data-testid="admin-shell-header">
      <div className="flex min-h-16 items-center justify-between gap-3 px-4 sm:px-6">
        <div className="flex min-w-0 items-center gap-3">
          <button
            type="button"
            aria-label={t('admin.shell.sidebar.aria')}
            onClick={onOpenMenu}
            className="inline-flex h-10 w-10 items-center justify-center rounded-lg text-[var(--text-secondary)] hover:bg-[var(--surface-muted)] lg:hidden"
          >
            <IconMenu2 className="h-5 w-5" />
          </button>
          <Link to="/overview" className="flex min-w-0 items-center gap-2 font-bold text-[var(--text-primary)]">
            <IconShieldLock className="h-6 w-6 shrink-0 text-[var(--accent-default)]" />
            <span className="truncate">{t('admin.shell.brand')}</span>
          </Link>
        </div>

        <div className="flex items-center gap-1">
          <button
            type="button"
            onClick={() => void setLocale(locale === 'ar' ? 'en' : 'ar')}
            aria-label={locale === 'ar' ? 'English' : 'Arabic'}
            className="inline-flex h-10 w-10 items-center justify-center rounded-lg text-[var(--text-secondary)] hover:bg-[var(--surface-muted)]"
          >
            <IconLanguage className="h-5 w-5" />
          </button>
          <button
            type="button"
            onClick={toggleTheme}
            aria-label={t('theme.dark')}
            className="inline-flex h-10 w-10 items-center justify-center rounded-lg text-[var(--text-secondary)] hover:bg-[var(--surface-muted)]"
          >
            <IconMoon className="h-5 w-5" />
          </button>
          <span className="hidden max-w-36 truncate px-2 text-sm text-[var(--text-secondary)] sm:inline">{user?.name}</span>
          <button
            type="button"
            onClick={() => {
              void logout().finally(() => navigate('/login', { replace: true }));
            }}
            aria-label={t('nav.logout')}
            className="inline-flex h-10 w-10 items-center justify-center rounded-lg text-[var(--text-secondary)] hover:bg-[var(--surface-muted)]"
          >
            <IconLogout className="h-5 w-5" />
          </button>
        </div>
      </div>
    </header>
  );
}
