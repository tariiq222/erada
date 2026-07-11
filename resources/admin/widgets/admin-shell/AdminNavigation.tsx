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

export interface AdminNavItem {
  href: string;
  labelKey: string;
  fallback: string;
  group: 'governance' | 'controls';
  icon: ComponentType<{ className?: string }>;
}

export const ADMIN_NAV_ITEMS: AdminNavItem[] = [
  { href: '/overview', labelKey: 'admin.shell.nav.overview', fallback: 'Overview', group: 'governance', icon: IconLayoutDashboard },
  { href: '/security/alerts', labelKey: 'admin.shell.nav.security', fallback: 'Security alerts', group: 'governance', icon: IconAlertTriangle },
  { href: '/audit/recent', labelKey: 'admin.shell.nav.audit_recent', fallback: 'Recent audit', group: 'governance', icon: IconFileAnalytics },
  { href: '/organizations', labelKey: 'admin.shell.nav.organizations', fallback: 'Organizations', group: 'controls', icon: IconBuildingCommunity },
  { href: '/access', labelKey: 'admin.shell.nav.access', fallback: 'Permissions and access', group: 'controls', icon: IconKey },
  { href: '/roles', labelKey: 'admin.shell.nav.roles', fallback: 'Roles', group: 'controls', icon: IconShieldLock },
  { href: '/users', labelKey: 'admin.shell.nav.users', fallback: 'Users', group: 'controls', icon: IconUsers },
  { href: '/activity-logs', labelKey: 'admin.shell.nav.activity_logs', fallback: 'Activity logs', group: 'controls', icon: IconActivity },
  { href: '/scoped-roles/audit-logs', labelKey: 'admin.shell.nav.scoped_role_audit', fallback: 'Scoped-role audit', group: 'controls', icon: IconUserShield },
  { href: '/scope-types', labelKey: 'admin.scopeTypes.title', fallback: 'Scope types', group: 'controls', icon: IconHierarchy },
  { href: '/departments', labelKey: 'admin.departments.title', fallback: 'Departments', group: 'controls', icon: IconBuildingSkyscraper },
  { href: '/incident-types', labelKey: 'admin.incidentTypes.title', fallback: 'Incident types', group: 'controls', icon: IconListDetails },
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

export function AdminNavigation({ mode, onNavigate }: AdminNavigationProps) {
  const { t } = useTranslation();
  const groups: Array<AdminNavItem['group']> = ['governance', 'controls'];

  return (
    <nav
      aria-label={t('admin.shell.sidebar.aria')}
      data-testid={`admin-${mode}-navigation`}
      className="flex-1 overflow-y-auto p-3"
    >
      {groups.map((group) => (
        <div key={group} className="mb-5 last:mb-0">
          <p className="mb-2 px-3 text-xs font-semibold text-[var(--text-tertiary)]">
            {group === 'governance'
              ? t('admin.shell.sidebar.section_primary')
              : t('admin.shell.sidebar.section_secondary')}
          </p>
          <ul className="space-y-1">
            {ADMIN_NAV_ITEMS.filter((item) => item.group === group).map((item) => {
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
                        : 'text-[var(--text-secondary)] hover:bg-[var(--surface-muted)] hover:text-[var(--text-primary)]',
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
      ))}
    </nav>
  );
}
