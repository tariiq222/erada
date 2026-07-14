import { Navigate, Outlet, useLocation } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import type { User } from '@shared/types';
import { useAuth } from '@shared/contexts/AuthContext';
import { getSafeAdminReturnPath } from '@admin/widgets/admin-shell/AdminNavigation';
import { Forbidden } from '@admin/pages/Forbidden';

/**
 * Local view of the two `/api/user` flags that decide which admin SPA
 * boundaries a caller may enter. The backend (`AuthController::user`)
 * exposes both flags verbatim on the user payload; the frontend never
 * consults role names for authorization.
 */
type AdminAuthorityFlags = {
  is_super_admin?: boolean;
  is_organization_super_admin?: boolean;
};

function adminAuthorityOf(user: User | null | undefined): AdminAuthorityFlags {
  return (user ?? null) as unknown as AdminAuthorityFlags;
}

/**
 * Predicate: true when the user can enter routes scoped to the OrgSuper
 * surface (org-admin user lifecycle, org-scoped settings, scoped audit
 * of role assignments) in addition to the platform surface that the
 * global super admin always reaches.
 *
 * Both predicates are flag-based:
 *   - `is_super_admin === true`            → super admin (gate #1)
 *   - `is_organization_super_admin === true` → org-super (gate #2)
 *
 * Role names are intentionally NEVER consulted; legacy payloads, capability
 * changes, or role renames must not widen the surface.
 */
export function isAllowedIntoOrgSuperSurface(user: User | null | undefined): boolean {
  const flags = adminAuthorityOf(user);
  return flags.is_super_admin === true || flags.is_organization_super_admin === true;
}

/**
 * Parallel guard for the Org-Super-reachable subset of the admin SPA.
 *
 * Behaviour:
 *  - loading:            renders the localized loading chrome.
 *  - not authenticated:  redirects to `/login` with a safe admin return path.
 *  - super admin OR organization super admin: renders the protected outlet.
 *  - everyone else:      renders `<Forbidden />`.
 *
 * The flag-based predicate is extracted as `isAllowedIntoOrgSuperSurface`
 * so unit tests can exercise it without mounting the boundary component.
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

  if (!isAllowedIntoOrgSuperSurface(user)) {
    return <Forbidden />;
  }

  return <Outlet />;
}