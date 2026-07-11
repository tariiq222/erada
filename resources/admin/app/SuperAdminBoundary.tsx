import { Navigate, Outlet, useLocation } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { useAuth } from '@shared/contexts/AuthContext';
import { getSafeAdminReturnPath } from '@admin/widgets/admin-shell/AdminNavigation';
import { Forbidden } from '@admin/pages/Forbidden';

export function SuperAdminBoundary() {
  const { t } = useTranslation();
  const { user, isLoading, isAuthenticated } = useAuth();
  const location = useLocation();

  if (isLoading) {
    return (
      <main className="flex min-h-screen items-center justify-center bg-[var(--surface-base)]">
        <p role="status" className="text-sm text-[var(--text-secondary)]">
          {t('common.loading')}
        </p>
      </main>
    );
  }

  if (!isAuthenticated) {
    const returnTo = getSafeAdminReturnPath(`${location.pathname}${location.search}`);
    return <Navigate to={`/login?returnTo=${encodeURIComponent(returnTo)}`} replace />;
  }

  if (!user?.roles.includes('super_admin')) {
    return <Forbidden />;
  }

  return <Outlet />;
}
