import * as React from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useLocation } from 'react-router-dom';
import { useAuth } from '@shared/contexts/AuthContext';
import {
  IconLayoutDashboard,
  IconAlertTriangle,
  IconHistory,
  IconBuildingCommunity,
  IconKey,
  IconShieldLock,
  IconUsers,
  IconActivity,
  IconHistory as IconAssignmentAudit,
  IconClipboardList,
} from '@tabler/icons-react';

export interface AdminNavItem {
  href: string;
  labelKey: string;
  icon: React.ComponentType<{ className?: string }>;
  /** When true, the link is rendered but visually de-emphasized (out of M1 scope). */
  secondary?: boolean;
  capability: string;
}

/**
 * Concise technical navigation for the Super Admin Control Plane.
 *
 * M1 (System Governance Console) - Overview, Security Alerts, Audit Recent -
 * is the primary surface and is rendered first. The remaining items
 * (organizations, access, roles, users, registrations, activity logs,
 * authorization-assignment audit) reuse the existing admin pages but are visually
 * grouped as the secondary cluster.
 */
export const ADMIN_NAV_PRIMARY: AdminNavItem[] = [
  {
    href: '/admin/overview',
    labelKey: 'admin.shell.nav.overview',
    icon: IconLayoutDashboard,
    capability: 'core.view_organizations',
  },
  {
    href: '/admin/security/alerts',
    labelKey: 'admin.shell.nav.security',
    icon: IconAlertTriangle,
    capability: 'audit.view',
  },
  {
    href: '/admin/audit/recent',
    labelKey: 'admin.shell.nav.audit_recent',
    icon: IconHistory,
    capability: 'audit.view',
  },
];

export const ADMIN_NAV_SECONDARY: AdminNavItem[] = [
  {
    href: '/admin/organizations',
    labelKey: 'admin.shell.nav.organizations',
    icon: IconBuildingCommunity,
    capability: 'core.view_organizations',
    secondary: true,
  },
  {
    href: '/admin/access',
    labelKey: 'admin.shell.nav.access',
    icon: IconKey,
    capability: 'core.assign_roles',
    secondary: true,
  },
  {
    href: '/admin/roles',
    labelKey: 'admin.shell.nav.roles',
    icon: IconShieldLock,
    capability: 'roles.view',
    secondary: true,
  },
  {
    href: '/admin/users',
    labelKey: 'admin.shell.nav.users',
    icon: IconUsers,
    capability: 'users.view',
    secondary: true,
  },
  {
    href: '/admin/activity-logs',
    labelKey: 'admin.shell.nav.activity_logs',
    icon: IconActivity,
    capability: 'audit.view',
    secondary: true,
  },
  {
    href: '/admin/authorization/audit-logs',
    labelKey: 'admin.shell.nav.authorization_assignment_audit',
    icon: IconAssignmentAudit,
    capability: 'audit.view',
    secondary: true,
  },
];

const ALL_ITEMS: AdminNavItem[] = [
  ...ADMIN_NAV_PRIMARY,
  ...ADMIN_NAV_SECONDARY,
];

function deriveActiveHref(pathname: string): string {
  // Most specific paths first - admin/shell logic MUST NOT match /admin or
  // /admin/overview against any secondary cluster.
  const matches = ALL_ITEMS.filter(
    (item) => pathname === item.href || pathname.startsWith(`${item.href}/`),
  );
  if (matches.length > 0) {
    // Pick the most specific (longest href) match.
    return matches.reduce((acc, m) =>
      m.href.length > acc.href.length ? m : acc,
    ).href;
  }
  return '/admin/overview';
}

const AdminSidebar: React.FC = () => {
  const { t } = useTranslation();
  const location = useLocation();
  const { can } = useAuth();
  const primaryItems = ADMIN_NAV_PRIMARY.filter((item) =>
    can(item.capability),
  );
  const secondaryItems = ADMIN_NAV_SECONDARY.filter((item) =>
    can(item.capability),
  );
  const activeHref = deriveActiveHref(location.pathname);

  return (
    <aside
      className="hidden lg:flex w-64 shrink-0 flex-col border-e border-[var(--border-default)] bg-[var(--surface-raised)]"
      aria-label={t('admin.shell.sidebar.aria', 'Super Admin navigation')}
      data-testid="admin-shell-sidebar"
    >
      <div className="px-4 py-4 border-b border-[var(--border-default)]">
        <p className="text-xs uppercase tracking-wide text-[var(--text-tertiary)]">
          {t('admin.shell.sidebar.section_primary', 'Governance')}
        </p>
      </div>
      <nav className="flex-1 overflow-y-auto px-2 py-3">
        <ul className="space-y-1">
          {primaryItems.map((item) => (
            <NavRow
              key={item.href}
              item={item}
              active={activeHref === item.href}
            />
          ))}
        </ul>
        <p className="mt-6 px-3 text-xs uppercase tracking-wide text-[var(--text-tertiary)]">
          {t('admin.shell.sidebar.section_secondary', 'Technical controls')}
        </p>
        <ul className="mt-2 space-y-1">
          {secondaryItems.map((item) => (
            <NavRow
              key={item.href}
              item={item}
              active={activeHref === item.href}
            />
          ))}
        </ul>
      </nav>
      <div className="px-4 py-3 border-t border-[var(--border-default)] text-xs text-[var(--text-tertiary)]">
        <p className="flex items-center gap-1.5">
          <IconClipboardList className="h-3.5 w-3.5" />
          {t('admin.shell.sidebar.footer', 'Read-only control plane')}
        </p>
      </div>
    </aside>
  );
};

const NavRow: React.FC<{ item: AdminNavItem; active: boolean }> = ({
  item,
  active,
}) => {
  const { t } = useTranslation();
  const Icon = item.icon;
  const className = [
    'flex items-center gap-2 rounded-md px-3 py-2 text-sm transition-colors',
    active
      ? 'bg-[var(--accent-default)] text-[var(--text-inverse)] font-semibold'
      : 'text-[var(--text-secondary)] hover:bg-[var(--surface-muted)] hover:text-[var(--text-primary)]',
  ].join(' ');

  return (
    <li>
      <Link
        to={item.href}
        className={className}
        aria-current={active ? 'page' : undefined}
        data-testid={`admin-shell-nav-${item.href}`}
      >
        <Icon className="h-4 w-4 shrink-0" />
        <span className="truncate">{t(item.labelKey, item.href)}</span>
      </Link>
    </li>
  );
};

export default AdminSidebar;
