import type { ComponentType } from 'react';
import { NavLink } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import {
  IconActivity,
  IconAlertTriangle,
  IconBuildingCommunity,
  IconBuildingSkyscraper,
  IconFileAnalytics,
  IconHierarchy,
  IconKey,
  IconLayoutDashboard,
  IconListDetails,
  IconShieldLock,
  IconUsers,
  IconUserShield,
} from '@tabler/icons-react';
import { useAuth } from '@shared/contexts/AuthContext';

/**
 * Navigation audience — the authz posture a sidebar item requires.
 *
 * - `'platform'`: visible only to backend `is_super_admin === true`. These
 *   are global control-plane items (organizations, role definitions, scope
 *   types, platform audit).
 * - `'org'`: visible to `is_super_admin === true` OR
 *   `is_organization_super_admin === true`. These are the OrgSuper
 *   surface items (org-scoped users, departments, access summary,
 *   activity logs, scoped-role audit).
 *
 * Authorization is flag-based; role names from `user.roles` are NEVER
 * consulted. Legacy payloads, capability changes, and role renames must
 * not widen or narrow the surface.
 */
export type AdminNavAudience = 'platform' | 'org';

/**
 * Local view of the two navigation-relevant flags on the user payload.
 * Extracted as a type alias so the visibility predicate is testable
 * without importing `User` (the canonical `is_organization_super_admin`
 * flag is mirrored from the backend contract but is admin-specific).
 */
type AdminAuthorityView = {
  is_super_admin?: boolean;
  is_organization_super_admin?: boolean;
};

export interface AdminNavItem {
  href: string;
  labelKey: string;
  fallback: string;
  audience: AdminNavAudience;
  icon: ComponentType<{ className?: string }>;
}

export function isAdminNavItemVisible(
  item: AdminNavItem,
  user: AdminAuthorityView | null | undefined,
): boolean {
  if (item.audience === 'platform') {
    return user?.is_super_admin === true;
  }
  // item.audience === 'org'
  return user?.is_super_admin === true || user?.is_organization_super_admin === true;
}

export const ADMIN_NAV_ITEMS: AdminNavItem[] = [
  // Platform-only surface — super admin authority is required.
  { href: '/overview', labelKey: 'admin.shell.nav.overview', fallback: 'Overview', audience: 'platform', icon: IconLayoutDashboard },
  { href: '/security/alerts', labelKey: 'admin.shell.nav.security', fallback: 'Security alerts', audience: 'platform', icon: IconAlertTriangle },
  { href: '/audit/recent', labelKey: 'admin.shell.nav.audit_recent', fallback: 'Recent audit', audience: 'platform', icon: IconFileAnalytics },
  { href: '/organizations', labelKey: 'admin.shell.nav.organizations', fallback: 'Organizations', audience: 'platform', icon: IconBuildingCommunity },
  { href: '/roles', labelKey: 'admin.shell.nav.roles', fallback: 'Roles', audience: 'platform', icon: IconShieldLock },
  { href: '/scope-types', labelKey: 'admin.scopeTypes.title', fallback: 'Scope types', audience: 'platform', icon: IconHierarchy },
  { href: '/incident-types', labelKey: 'admin.incidentTypes.title', fallback: 'Incident types', audience: 'platform', icon: IconListDetails },
  // Org-Super surface — reachable by super admin OR organization super admin.
  { href: '/users', labelKey: 'admin.shell.nav.users', fallback: 'Users', audience: 'org', icon: IconUsers },
  { href: '/departments', labelKey: 'admin.departments.title', fallback: 'Departments', audience: 'org', icon: IconBuildingSkyscraper },
  { href: '/access', labelKey: 'admin.shell.nav.access', fallback: 'Permissions and access', audience: 'org', icon: IconKey },
  { href: '/activity-logs', labelKey: 'admin.shell.nav.activity_logs', fallback: 'Activity logs', audience: 'org', icon: IconActivity },
  { href: '/scoped-roles/audit-logs', labelKey: 'admin.shell.nav.scoped_role_audit', fallback: 'Scoped-role audit', audience: 'org', icon: IconUserShield },
];

export function getSafeAdminReturnPath(candidate: string | null | undefined): string {
  if (!candidate || !candidate.startsWith('/') || candidate.startsWith('//')) return '/overview';

  try {
    const parsed = new URL(candidate, window.location.origin);
    if (parsed.origin !== window.location.origin) return '/overview';
    const isAllowed = ADMIN_NAV_ITEMS.some(
      ({ href }) => parsed.pathname === href || parsed.pathname.startsWith(`${href}/`),
    );
    return isAllowed ? `${parsed.pathname}${parsed.search}${parsed.hash}` : '/overview';
  } catch {
    return '/overview';
  }
}

interface AdminNavigationProps {
  mode: 'desktop' | 'mobile';
  onNavigate?: () => void;
}

/**
 * Renders nav items in two stacked sections:
 *   1. Org-Super surface (visible to super admin or organization super admin)
 *   2. Platform surface (visible to super admin only)
 *
 * Within each section items render in `ADMIN_NAV_ITEMS` declaration order.
 * Section headings use audience-scoped i18n keys
 * (`admin.shell.sidebar.section_org` / `admin.shell.sidebar.section_platform`)
 * so the labels accurately describe the audience they group, regardless of
 * RTL/LTR. The legacy `section_primary` / `section_secondary` keys are
 * preserved for the legacy main-SPA `AdminSidebar.tsx` and are NOT used
 * here to avoid leaking stale "Governance" / "Technical controls" labels
 * into the unified admin SPA.
 */
export function AdminNavigation({ mode, onNavigate }: AdminNavigationProps) {
  const { t } = useTranslation();
  const { user } = useAuth();

  type Section = {
    id: AdminNavAudience;
    items: AdminNavItem[];
    headingKey:
      | 'admin.shell.sidebar.section_org'
      | 'admin.shell.sidebar.section_platform';
  };

  const sections: Section[] = (
    ['org', 'platform'] as AdminNavAudience[]
  ).map((audience) => {
    const items = ADMIN_NAV_ITEMS.filter(
      (item) => item.audience === audience && isAdminNavItemVisible(item, user),
    );
    return {
      id: audience,
      items,
      headingKey:
        audience === 'org'
          ? 'admin.shell.sidebar.section_org'
          : 'admin.shell.sidebar.section_platform',
    };
  });

  return (
    <nav
      aria-label={t('admin.shell.sidebar.aria')}
      data-testid={`admin-${mode}-navigation`}
      className="flex-1 overflow-y-auto p-3"
    >
      {sections.map((section) => {
        if (section.items.length === 0) {
          return null;
        }
        return (
          <div key={section.id} className="mb-5 last:mb-0">
            <p className="mb-2 px-3 text-xs font-semibold text-[var(--text-tertiary)]">
              {t(section.headingKey)}
            </p>
            <ul className="space-y-1">
              {section.items.map((item) => {
                const Icon = item.icon;
                return (
                  <li key={item.href}>
                    <NavLink
                      to={item.href}
                      onClick={onNavigate}
                      className={({ isActive }) => [
                        'flex min-h-10 items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                        isActive
                          ? 'bg-[var(--accent-default)] text-[var(--text-inverse)]'
                          : 'text-[var(--text-secondary)] hover:bg-[var(--surface-muted)] hover:text-[var(--text-primary])',
                      ].join(' ')}
                    >
                      <Icon className="h-5 w-5 shrink-0" />
                      <span>{t(item.labelKey, item.fallback)}</span>
                    </NavLink>
                  </li>
                );
              })}
            </ul>
          </div>
        );
      })}
    </nav>
  );
}
