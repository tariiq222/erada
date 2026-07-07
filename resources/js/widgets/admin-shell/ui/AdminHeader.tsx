import * as React from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { Avatar, Badge } from '@shared/ui';
import LanguageSwitcher from '@shared/ui/LanguageSwitcher';
import ThemeSwitcher from '@shared/ui/ThemeSwitcher';
import {
  IconShieldLock,
  IconChevronLeft,
  IconLogout,
} from '@tabler/icons-react';
import { useAuth } from '@shared/contexts/AuthContext';
import { useSystemSettings } from '@shared/contexts/SystemSettingsContext';

const AdminHeader: React.FC = () => {
  const { t, i18n } = useTranslation();
  const { user, logout } = useAuth();
  const { settings: systemSettings } = useSystemSettings();
  const navigate = useNavigate();

  const platformName =
    i18n.language === 'en' && systemSettings?.name_en
      ? systemSettings.name_en
      : systemSettings?.name || t('common.app_name', 'Erada');

  return (
    <header
      className="flex flex-col border-b border-[var(--border-default)] bg-[var(--surface-raised)]"
      data-testid="admin-shell-header"
    >
      <div className="flex items-center justify-between gap-4 px-4 py-3 sm:px-6">
        <button
          type="button"
          onClick={() => navigate('/admin/overview')}
          className="flex items-center gap-2 text-[var(--text-primary)] font-semibold"
          data-testid="admin-shell-brand"
        >
          <IconShieldLock className="h-5 w-5 text-[var(--accent-default)]" />
          <span>{t('admin.shell.brand', 'Super Admin Console')}</span>
          <Badge variant="warning" size="sm">
            {t('admin.shell.tag', 'Technical')}
          </Badge>
        </button>

        <div className="flex items-center gap-2">
          <span className="hidden sm:inline text-xs text-[var(--text-tertiary)]">
            {platformName}
          </span>
          <LanguageSwitcher />
          <ThemeSwitcher />
          <button
            type="button"
            onClick={() => navigate('/dashboard')}
            className="inline-flex items-center gap-1 rounded-md border border-[var(--border-default)] px-2 py-1 text-xs text-[var(--text-secondary)] hover:bg-[var(--surface-muted)] hover:text-[var(--text-primary)]"
            data-testid="admin-shell-back-to-app"
          >
            <IconChevronLeft className="h-3.5 w-3.5 rtl-flip" />
            {t('admin.shell.back_to_app', 'Back to app')}
          </button>
          {user?.name && (
            <div className="flex items-center gap-2 border-s border-[var(--border-default)] ps-2 ms-1">
              <Avatar name={user.name} size="sm" />
              <span className="hidden md:inline text-sm text-[var(--text-primary)]">
                {user.name}
              </span>
              <button
                type="button"
                onClick={() => {
                  void logout();
                }}
                aria-label={t('nav.logout', 'Logout')}
                className="inline-flex items-center justify-center rounded-md p-1.5 text-[var(--text-secondary)] hover:bg-[var(--surface-muted)] hover:text-[var(--text-primary)]"
              >
                <IconLogout className="h-4 w-4" />
              </button>
            </div>
          )}
        </div>
      </div>
    </header>
  );
};

export default AdminHeader;