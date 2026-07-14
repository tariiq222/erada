import { Navigate, Outlet, useLocation } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { useAuth } from '@shared/contexts/AuthContext';
import { getSafeAdminReturnPath } from '@admin/widgets/admin-shell/AdminNavigation';
import { Forbidden } from '@admin/pages/Forbidden';

/**
 * Parallel guard for the Org-Super-reachable subset of the admin SPA.
 *
 * Predicate is derived solely from the backend-computed `is_super_admin` and
 * `is_organization_super_admin` payload flags exposed on `/api/user`. Role
 * names from `user.roles` are intentionally NOT consulted — authorization is
 * flag-based so that legacy payloads, capability-string changes, and role
 * renames cannot widen or narrow the surface.
 *
 * Behaviour:
 *  - loading:            renders the localized loading chrome.
 *  - not authenticated:  redirects to `/login` with a safe admin return path.
 *  - system admin OR organization super admin: renders the protected outlet.
 *  - everyone else:      renders `<Forbidden />`.
 */
export function OrgSuperOrSuperBoundary() {
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

  const allowed = user?.is_super_admin === true || user?.is_organization_super_admin === true;
  if (!allowed) {
    return <Forbidden />;
  }

  return <Outlet />;
}
