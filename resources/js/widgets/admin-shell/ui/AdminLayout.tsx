import React, { useEffect } from 'react';
import { Outlet, Navigate, useLocation } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { useAuth } from '@shared/contexts/AuthContext';
import { useTheme } from '@shared/contexts/ThemeContext';
import { useLocale } from '@shared/contexts/LocaleContext';
import AdminHeader from './AdminHeader';
import AdminSidebar from './AdminSidebar';

/**
 * AdminLayout - Super Admin Control Plane shell.
 *
 * Distinct from the regular <AppLayout>: this shell is read-mostly and does
 * NOT render the operational NASAQ sidebar, project/task/survey operational
 * nav, or the OrgSwitcher. Same auth/session is used; the only difference is
 * the chrome that wraps `/admin/*` routes.
 */
const AdminLayout: React.FC = () => {
  const { t, i18n } = useTranslation();
  const { isAuthenticated, isLoading } = useAuth();
  const { resolvedTheme } = useTheme();
  const { direction } = useLocale();
  const location = useLocation();

  // Keep html attributes in sync with the rest of the app so the Admin shell
  // doesn't drop theme/dir/lang when entered from a deep link.
  useEffect(() => {
    const root = document.documentElement;
    root.setAttribute('data-theme', resolvedTheme);
    root.classList.toggle('dark', resolvedTheme === 'dark');
    root.setAttribute('dir', direction);
    root.setAttribute('lang', i18n.language);
  }, [resolvedTheme, direction, i18n.language]);

  if (isLoading) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-[var(--surface-base)]">
        <p className="text-[var(--text-secondary)]">{t('common.loading')}</p>
      </div>
    );
  }

  if (!isAuthenticated) {
    return <Navigate to="/login" replace state={{ from: location }} />;
  }

  return (
    <div
      className="h-screen flex flex-col overflow-hidden bg-[var(--surface-base)] text-[var(--text-primary)]"
      data-testid="admin-control-plane-shell"
    >
      <AdminHeader />
      <div className="flex flex-1 min-h-0">
        <AdminSidebar />
        <main
          className="flex-1 min-w-0 overflow-y-auto"
          data-testid="admin-shell-main"
        >
          <Outlet />
        </main>
      </div>
    </div>
  );
};

export default AdminLayout;