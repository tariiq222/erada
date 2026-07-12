import React from "react";
import { Tooltip, Kbd, CommandPalette } from "@shared/ui";
import { Icon, Avatar } from "./ui";

export type NasaqNavGroupId = "main" | "planning" | "meetings" | "ops" | "quality" | "crisis" | "admin";

export const NASAQ_NAV: Array<{
  group: NasaqNavGroupId;
  key: string;
  path: string;
  icon: string;
  requireAdmin?: boolean;
}> = [
  { group: "main", key: "dashboard", path: "/dashboard", icon: "grid" },
  { group: "main", key: "tasks", path: "/tasks", icon: "check" },
  { group: "main", key: "my_tasks", path: "/my-tasks", icon: "list" },
  { group: "planning", key: "projects", path: "/projects", icon: "folder" },
  { group: "planning", key: "strategy", path: "/strategy/portfolios", icon: "target" },
  { group: "planning", key: "performance_kpis", path: "/performance/kpis", icon: "trend" },
  { group: "meetings", key: "meetings", path: "/strategy/meetings", icon: "calendar" },
  { group: "quality", key: "ovr", path: "/ovr/incidents", icon: "alert" },
  { group: "ops", key: "surveys", path: "/surveys", icon: "inbox" },
  { group: "crisis", key: "risks", path: "/risk-management/risks", icon: "shield" },
  { group: "ops", key: "departments", path: "/hr/departments", icon: "users" },
  { group: "admin", key: "users", path: "/admin/users", icon: "users", requireAdmin: true },
  { group: "admin", key: "organizations", path: "/admin/organizations", icon: "building", requireAdmin: true },
  { group: "admin", key: "roles", path: "/admin/access", icon: "badge", requireAdmin: true },
  { group: "admin", key: "activity", path: "/admin/activity-logs", icon: "list", requireAdmin: true },
];

// ── Accordion navigation tree ───────────────────────────────────────────────
// Each module exposes a uniform toolset (create / list / statistics) plus any
// module-specific pages. Labels and access filtering are resolved by the host
// (AppLayout) via buildNasaqGroups so this module stays presentational.

export type NavAccess = import('@shared/api/access').AccessRequirement;

export type NasaqNavAccent = "primary" | "indigo" | "teal" | "amber" | "violet" | "crisis";

type RawNavItem = {
  key: string;
  labelKey: string;
  icon: string;
  path: string;
  accent?: NasaqNavAccent;
  access?: NavAccess;
  children?: RawNavItem[];
};

type RawNavModule = RawNavItem & {
  group: NasaqNavGroupId;
  requireAdmin?: boolean;
};

const STATS = (key: string, path: string): RawNavItem => ({
  key,
  labelKey: "nav.statistics",
  icon: "gauge",
  path,
});
const CREATE = (key: string, path: string, access?: NavAccess): RawNavItem => ({
  key,
  labelKey: "nav.create_new",
  icon: "plus",
  path,
  access,
});

const NASAQ_MODULE_ACCENTS: Partial<Record<string, NasaqNavAccent>> = {
  dashboard: "primary",
  tasks: "teal",
  projects: "indigo",
  strategy: "indigo",
  performance_kpis: "teal",
  meetings: "indigo",
  recommendations: "indigo",
  meeting_resolutions: "indigo",
  decisions: "indigo",
  ovr: "amber",
  risks: "amber",
  surveys: "violet",
  crisis: "crisis",
  hr: "violet",
  users: "violet",
  organizations: "violet",
  roles: "violet",
  activity: "violet",
};

export const NASAQ_NAV_TREE: RawNavModule[] = [
  { group: "main", key: "dashboard", labelKey: "nav.dashboard", icon: "grid", path: "/dashboard" },
  {
    group: "main",
    key: "tasks",
    labelKey: "nav.tasks",
    icon: "check",
    path: "/tasks",
    access: { anyCapabilities: ["tasks.view", "tasks.view"] },
    children: [
      CREATE("tasks-create", "/tasks/create", { capability: "tasks.create" }),
      { key: "tasks-all", labelKey: "nav.view_all", icon: "list", path: "/tasks" },
      { key: "tasks-mine", labelKey: "nav.my_tasks", icon: "check", path: "/my-tasks" },
    ],
  },
  {
    group: "planning",
    key: "portfolios",
    labelKey: "nav.portfolios",
    icon: "target",
    path: "/strategy/portfolios",
    access: { capability: "strategy.view" },
    children: [
      CREATE("portfolios-create", "/strategy/portfolios/new", { capability: "strategy.create" }),
      { key: "portfolios-all", labelKey: "nav.view_all", icon: "list", path: "/strategy/portfolios" },
      STATS("portfolios-stats", "/strategy/portfolios/statistics"),
    ],
  },
  {
    group: "planning",
    key: "programs",
    labelKey: "nav.programs",
    icon: "flag",
    path: "/strategy/programs",
    access: { capability: "strategy.view" },
    children: [
      CREATE("programs-create", "/strategy/programs/new", { capability: "strategy.create" }),
      { key: "programs-all", labelKey: "nav.view_all", icon: "list", path: "/strategy/programs" },
      STATS("programs-stats", "/strategy/programs/statistics"),
    ],
  },
  {
    group: "planning",
    key: "projects",
    labelKey: "nav.projects",
    icon: "folder",
    path: "/projects",
    access: { anyCapabilities: ["projects.view", "projects.view"] },
    children: [
      CREATE("projects-create", "/projects?intake=1", { capability: "projects.create" }),
      { key: "projects-all", labelKey: "nav.projects_list", icon: "folder", path: "/projects" },
      STATS("projects-stats", "/projects/statistics"),
      { key: "projects-settings", labelKey: "nav.projects_settings", icon: "settings", path: "/projects/settings", access: { capability: "settings.manage" } },
    ],
  },
  {
    group: "planning",
    key: "performance_kpis",
    labelKey: "nav.performance_kpis",
    icon: "trend",
    path: "/performance/kpis",
    accent: "teal",
    access: { anyCapabilities: ["strategy.view", "strategy.view"] },
    children: [
      CREATE("performance-kpis-create", "/performance/kpis/new", { capability: "strategy.create" }),
      { key: "performance-kpis-all", labelKey: "nav.view_all", icon: "list", path: "/performance/kpis" },
    ],
  },
  {
    group: "meetings",
    key: "meetings",
    labelKey: "meetings.meeting.list.header",
    icon: "calendar",
    path: "/strategy/meetings",
    access: { capability: "meetings.view" },
    children: [
      CREATE("meetings-create", "/strategy/meetings/new", { capability: "meetings.edit" }),
      { key: "meetings-all", labelKey: "nav.view_all", icon: "list", path: "/strategy/meetings" },
      { key: "meetings-settings", labelKey: "meetings.categories.nav", icon: "settings", path: "/strategy/meetings/settings", access: { capability: "meetings.edit" } },
    ],
  },
  {
    group: "meetings",
    key: "recommendations",
    labelKey: "meetings.recommendation.list.header",
    icon: "flag",
    path: "/strategy/meetings/recommendations",
    access: { capability: "meetings.view" },
    children: [
      { key: "recommendations-all", labelKey: "nav.view_all", icon: "list", path: "/strategy/meetings/recommendations" },
    ],
  },
  {
    group: "meetings",
    key: "meeting_resolutions",
    labelKey: "meetings.resolution.list.header",
    icon: "check",
    path: "/strategy/meetings/resolutions",
    access: { capability: "meeting_resolutions.view" },
    children: [
      { key: "resolutions-all", labelKey: "nav.view_all", icon: "list", path: "/strategy/meetings/resolutions" },
    ],
  },
  {
    group: "quality",
    key: "ovr",
    labelKey: "nav.ovr",
    icon: "alert",
    path: "/ovr",
    access: { anyCapabilities: ["ovr.view_all", "ovr.view_all", "ovr.view_all"] },
    children: [
      { key: "ovr-incidents", labelKey: "nav.incidents", icon: "alert", path: "/ovr/incidents", access: { anyCapabilities: ["ovr.view_all", "ovr.view_all", "ovr.view_all"] } },
      { key: "ovr-new", labelKey: "ovr.report_incident", icon: "alert", path: "/ovr/incidents/new", access: { capability: "ovr.create" } },
      { key: "ovr-stats", labelKey: "nav.statistics", icon: "gauge", path: "/ovr/statistics", access: { allCapabilities: ["ovr.view_statistics"], anyCapabilities: ["ovr.view_all", "ovr.view_all", "ovr.view_all"] } },
      { key: "ovr-settings", labelKey: "nav.ovr_settings", icon: "settings", path: "/ovr/settings", access: { capability: "ovr.manage_types" } },
    ],
  },
  {
    group: "ops",
    key: "surveys",
    labelKey: "nav.surveys",
    icon: "inbox",
    path: "/surveys",
    access: { capability: "surveys.view" },
    children: [
      CREATE("surveys-create", "/surveys/create", { capability: "surveys.create" }),
      { key: "surveys-all", labelKey: "nav.view_all", icon: "list", path: "/surveys" },
      STATS("surveys-stats", "/surveys/statistics"),
    ],
  },
  {
    group: "crisis",
    key: "risks",
    labelKey: "nav.risks",
    icon: "shield",
    path: "/risk-management",
    access: { capability: "risks.view" },
    children: [
      CREATE("risks-create", "/risk-management/create", { capability: "risks.create" }),
      { key: "risks-all", labelKey: "nav.risks_list", icon: "list", path: "/risk-management/risks" },
      STATS("risks-stats", "/risk-management/statistics"),
      { key: "risks-settings", labelKey: "nav.risks_settings", icon: "settings", path: "/risk-management/settings", access: { capability: "settings.manage" } },
    ],
  },

  {
    group: "ops",
    key: "hr",
    labelKey: "nav.hr",
    icon: "users",
    path: "/hr",
    access: { anyCapabilities: ["hr.view", "departments.view"] },
    children: [
      { key: "hr-employees", labelKey: "nav.employees", icon: "badge", path: "/hr/employees", access: { capability: "hr.view" } },
      { key: "hr-departments", labelKey: "nav.departments", icon: "building", path: "/hr/departments", access: { capability: "departments.view" } },
      { key: "hr-stats", labelKey: "nav.statistics", icon: "gauge", path: "/hr/departments/statistics", access: { capability: "departments.view" } },
    ],
  },
  {
    group: "admin",
    key: "users",
    labelKey: "nav.users",
    icon: "users",
    path: "/admin/users",
    requireAdmin: true,
    children: [
      CREATE("users-create", "/admin/users/create"),
      { key: "users-all", labelKey: "nav.view_all", icon: "list", path: "/admin/users" },
      STATS("users-stats", "/users/statistics"),
    ],
  },
  {
    group: "admin",
    key: "organizations",
    labelKey: "nav.organizations",
    icon: "building",
    path: "/admin/organizations",
    requireAdmin: true,
    children: [
      { key: "organizations-create", labelKey: "nav.create_new", icon: "plus", path: "/admin/organizations/new" },
      { key: "organizations-all", labelKey: "nav.view_all", icon: "list", path: "/admin/organizations" },
    ],
  },
  {
    group: "admin",
    key: "roles",
    labelKey: "nav.roles",
    icon: "badge",
    path: "/admin/access",
    requireAdmin: true,
    children: [
      { key: "access-roles", labelKey: "nav.access.tabs.roles", icon: "badge", path: "/admin/access?tab=roles" },
      { key: "access-members", labelKey: "nav.access.tabs.members", icon: "users", path: "/admin/access?tab=members" },
      { key: "access-governance", labelKey: "nav.access.tabs.governance", icon: "building", path: "/admin/access?tab=governance" },
      { key: "access-audit", labelKey: "nav.access.tabs.audit", icon: "log", path: "/admin/access?tab=audit" },
      { key: "roles-create", labelKey: "nav.create_new", icon: "plus", path: "/admin/roles/new" },
    ],
  },
  { group: "admin", key: "activity", labelKey: "nav.activity_logs", icon: "log", path: "/admin/activity-logs", requireAdmin: true },
];

export type NavItem = { key: string; label: string; icon: string; path: string; accent?: NasaqNavAccent; children?: NavItem[] };
export type NavGroup = { id: string; label?: string; items: NavItem[] };

export function buildNasaqGroups(
  t: (key: string) => string,
  can: (capability: string) => boolean,
  isAdmin: boolean,
): NavGroup[] {
  const allowed = (access?: NavAccess) => !access || (
    !access.allCapabilities?.some((capability) => !can(capability)) &&
    ([
      ...(access.capability ? [access.capability] : []),
      ...(access.anyCapabilities ?? []),
    ].some((capability) => can(capability)) || Boolean(access.allCapabilities?.length))
  );

  const mapItem = (item: RawNavItem, inheritedAccent?: NasaqNavAccent): NavItem | null => {
    if (!allowed(item.access)) return null;
    const accent = item.accent ?? inheritedAccent ?? NASAQ_MODULE_ACCENTS[item.key] ?? "primary";
    const children = item.children
      ?.map((child) => mapItem(child, accent))
      .filter((child): child is NavItem => child !== null);
    return {
      key: item.key,
      label: t(item.labelKey),
      icon: item.icon,
      path: item.path,
      accent,
      children: children && children.length > 0 ? children : undefined,
    };
  };

  const groupItems = (group: NasaqNavGroupId) =>
    NASAQ_NAV_TREE.filter(
      (module) => module.group === group && (!module.requireAdmin || isAdmin),
    )
      .map((module) => mapItem(module))
      .filter((item): item is NavItem => item !== null);

  return [
    { id: "main", items: groupItems("main") },
    { id: "meetings", label: t("meetings.title"), items: groupItems("meetings") },
    { id: "planning", label: t("nav.planning"), items: groupItems("planning") },
    { id: "quality", label: t("nav.quality"), items: groupItems("quality") },
    { id: "crisis", label: t("nav.crisis_disasters_tool"), items: groupItems("crisis") },
    { id: "ops", label: t("nav.operations"), items: groupItems("ops") },
  ].filter((group) => group.items.length > 0);
}

export const NASAQ_PALETTES: Record<string, { name: string; light: { p: string; p2: string; soft: string; soft2: string; ring: string }; dark: { p: string; p2: string; soft: string; soft2: string; ring: string } }> = {
  blue: {
    name: "blue",
    light: { p: "#1C82C7", p2: "#136BA8", soft: "#E2F2FB", soft2: "#F0F8FD", ring: "rgba(28,130,199,.22)" },
    dark: { p: "#54B7EC", p2: "#2E9BD6", soft: "#0E2A3C", soft2: "#0A2030", ring: "rgba(84,183,236,.30)" },
  },
  indigo: {
    name: "indigo",
    light: { p: "#4F46E5", p2: "#4338CA", soft: "#ECECFD", soft2: "#F5F5FE", ring: "rgba(79,70,229,.20)" },
    dark: { p: "#8B83F2", p2: "#6D63EC", soft: "#221F3E", soft2: "#1A1830", ring: "rgba(139,131,242,.28)" },
  },
  green: {
    name: "green",
    light: { p: "#15803D", p2: "#166534", soft: "#E8F5EC", soft2: "#F1F9F3", ring: "rgba(21,128,61,.20)" },
    dark: { p: "#34C46B", p2: "#28A857", soft: "#16321F", soft2: "#122A1A", ring: "rgba(52,196,107,.26)" },
  },
  teal: {
    name: "teal",
    light: { p: "#0D9488", p2: "#0F766E", soft: "#E0F4F1", soft2: "#EEFAF8", ring: "rgba(13,148,136,.20)" },
    dark: { p: "#2DD4BF", p2: "#14B8A6", soft: "#0E3330", soft2: "#0B2A27", ring: "rgba(45,212,191,.26)" },
  },
};

export const NASAQ_BG_THEMES: Record<string, { name: string; light: { bg: string; grad: string; s1: string; s2: string; s3: string; b1: string; b2: string }; dark: { bg: string; grad: string; s1: string; s2: string; s3: string; b1: string; b2: string } }> = {
  cool: {
    name: "cool",
    light: { bg: "#F7F8FA", grad: "radial-gradient(130% 110% at 92% -12%, #F0F3F8 0%, #F7F8FA 46%)", s1: "#FFFFFF", s2: "#F4F6F9", s3: "#ECEFF3", b1: "#E7EAF0", b2: "#D6DBE4" },
    dark: { bg: "#08151F", grad: "radial-gradient(130% 110% at 92% -12%, #0E2433 0%, #08151F 52%)", s1: "#102230", s2: "#162C3C", s3: "#1E3849", b1: "#21404F", b2: "#2D5365" },
  },
  white: {
    name: "white",
    light: { bg: "#FFFFFF", grad: "radial-gradient(130% 110% at 92% -12%, #FBFCFD 0%, #FFFFFF 46%)", s1: "#FFFFFF", s2: "#F6F8FA", s3: "#EDF0F3", b1: "#E8EBEF", b2: "#D7DBE1" },
    dark: { bg: "#0A0C0F", grad: "radial-gradient(130% 110% at 92% -12%, #111419 0%, #0A0C0F 48%)", s1: "#14171C", s2: "#1B1F25", s3: "#242931", b1: "#272C34", b2: "#363C45" },
  },
  stone: {
    name: "stone",
    light: { bg: "#FAF8F6", grad: "radial-gradient(130% 110% at 92% -12%, #F3F6F2 0%, #FAF8F6 46%)", s1: "#FFFFFF", s2: "#F6F4F1", s3: "#EFEDEA", b1: "#E9E5E1", b2: "#DCD7D1" },
    dark: { bg: "#16130F", grad: "radial-gradient(130% 110% at 92% -12%, #1A2018 0%, #16130F 48%)", s1: "#211D19", s2: "#2A2520", s3: "#332D27", b1: "#34302B", b2: "#423B34" },
  },
  sky: {
    name: "sky",
    light: { bg: "#EDF2FB", grad: "radial-gradient(130% 110% at 92% -12%, #E2EBFA 0%, #EDF2FB 46%)", s1: "#FFFFFF", s2: "#F0F4FC", s3: "#E5ECF8", b1: "#DCE6F4", b2: "#C8D6EC" },
    dark: { bg: "#0D121C", grad: "radial-gradient(130% 110% at 92% -12%, #141C2C 0%, #0D121C 48%)", s1: "#161D2A", s2: "#1D2636", s3: "#263042", b1: "#2A3548", b2: "#374459" },
  },
};

export type NasaqSidebarLabels = {
  brandName: string;
  brandSub: string;
  search: string;
  sectionPlanning?: string;
  sectionQuality?: string;
  sectionCrisis?: string;
  sectionOps: string;
  sectionAdmin: string;
  sectionMain?: string;
  collapse?: string;
  expand?: string;
};

export type NasaqSidebarProps = {
  labels: NasaqSidebarLabels;
  groups: NavGroup[];
  /** Current location.pathname */
  activePath: string;
  /** Current location.pathname + location.search */
  activeHref: string;
  onNavigate: (path: string) => void;
  collapsed?: boolean;
};

const GROUP_LABEL: Partial<Record<string, keyof NasaqSidebarLabels>> = {
  planning: "sectionPlanning",
  quality: "sectionQuality",
  crisis: "sectionCrisis",
  ops: "sectionOps",
  admin: "sectionAdmin",
};

/** Active when the location is exactly this leaf (query-aware). */
function isLeafActive(path: string, activePath: string, activeHref: string): boolean {
  const [base, query] = path.split("?");
  if (query) return activeHref === path;
  // List leaves must not light up when a query filter is applied.
  return activePath === base && !activeHref.includes("?");
}

/** Active when the location lives anywhere under this item's branch. */
function isBranchActive(item: NavItem, activePath: string, activeHref: string): boolean {
  const [base, query] = item.path.split("?");
  const selfMatch = query
    ? activeHref === item.path
    : activePath === base || activePath.startsWith(base + "/");
  if (selfMatch) return true;
  return (item.children ?? []).some((child) => isBranchActive(child, activePath, activeHref));
}

export function Sidebar({ labels, groups, activePath, activeHref, onNavigate, collapsed = false }: NasaqSidebarProps) {
  const activeBranchKey = React.useMemo(() => {
    let key: string | null = null;
    const walk = (items: NavItem[]) => {
      for (const item of items) {
        if (key) return;
        if (item.children && isBranchActive(item, activePath, activeHref)) {
          key = item.key;
          return;
        }
        if (item.children) walk(item.children);
      }
    };
    groups.forEach((group) => walk(group.items));
    return key;
  }, [groups, activePath, activeHref]);

  const [expanded, setExpanded] = React.useState<string[]>(() => (activeBranchKey ? [activeBranchKey] : []));

  React.useEffect(() => {
    setExpanded(activeBranchKey ? [activeBranchKey] : []);
  }, [activeBranchKey]);

  const toggle = (key: string) =>
    setExpanded((current) =>
      current.includes(key) ? current.filter((k) => k !== key) : [...current, key],
    );

  const renderItem = (item: NavItem, depth: number, inheritedAccent?: NasaqNavAccent): React.ReactNode => {
    const accent = item.accent ?? inheritedAccent ?? "primary";
    const indent = depth > 0 ? { paddingInlineStart: 11 + depth * 18 } : undefined;

    if (item.children && item.children.length > 0) {
      const isOpen = expanded.includes(item.key);
      const branchActive = isBranchActive(item, activePath, activeHref);
      const parentBtn = (
        <button
          type="button"
          className={"nasaq-nav-item nasaq-nav-parent" + (branchActive ? " branch-active" : "")}
          data-accent={accent}
          style={indent}
          aria-expanded={isOpen}
          aria-label={item.label}
          onClick={() => (collapsed ? onNavigate(item.path) : toggle(item.key))}
        >
          <Icon name={item.icon} className="ic" />
          <span className="nasaq-nav-label">{item.label}</span>
          {!collapsed && (
            <Icon name="chevD" className={"nasaq-nav-caret" + (isOpen ? " open" : "")} />
          )}
        </button>
      );
      return (
        <div key={item.key} className="nasaq-nav-acc" data-accent={accent}>
          {collapsed ? (
            <Tooltip content={item.label} position="right">{parentBtn}</Tooltip>
          ) : (
            parentBtn
          )}
          {!collapsed && isOpen && (
            <div className="nasaq-nav-children">
              {item.children.map((child) => renderItem(child, depth + 1, accent))}
            </div>
          )}
        </div>
      );
    }

    const isActive = isLeafActive(item.path, activePath, activeHref);
    const leafBtn = (
      <button
        type="button"
        className={"nasaq-nav-item" + (isActive ? " active" : "")}
        data-accent={accent}
        style={indent}
        aria-label={item.label}
        onClick={() => onNavigate(item.path)}
      >
        <Icon name={item.icon} className="ic" />
        <span className="nasaq-nav-label">{item.label}</span>
      </button>
    );
    return collapsed ? (
      <Tooltip content={item.label} position="right" key={item.key}>{leafBtn}</Tooltip>
    ) : (
      <React.Fragment key={item.key}>{leafBtn}</React.Fragment>
    );
  };

  return (
    <aside className={"nasaq-sidebar" + (collapsed ? " collapsed" : "")}>
      <div className="nasaq-brand">
        <div className="nasaq-brand-mark" aria-hidden="true">
          <img src="/images/logo.png" alt="" className="nasaq-brand-mark-img" />
        </div>
        {!collapsed && (
          <div>
            <div className="nasaq-brand-name">{labels.brandName}</div>
            <div className="nasaq-brand-sub">{labels.brandSub}</div>
          </div>
        )}
      </div>
      <div className="nasaq-nav-scroll">
        {groups.map((group) => {
          const labelKey = GROUP_LABEL[group.id];
          return (
            <div key={group.id} className="nasaq-nav-group">
              {labelKey && <div className="nasaq-nav-label nasaq-nav-group-label">{labels[labelKey]}</div>}
              {group.items.map((item) => renderItem(item, 0))}
            </div>
          );
        })}
      </div>
    </aside>
  );
}

export type NasaqTopbarLabels = {
  brandSub: string;
  search: string;
  settings: string;
  fontSize: string;
  language: string;
  appearance: string;
  sizeS: string;
  sizeM: string;
  sizeL: string;
  light: string;
  dark: string;
  collapse?: string;
  expand?: string;
};

export type NasaqTopbarProps = {
  labels: NasaqTopbarLabels;
  crumb: string;
  lang: "ar" | "en";
  setLang: (lang: "ar" | "en") => void;
  theme: "light" | "dark";
  setTheme: (theme: "light" | "dark") => void;
  textscale: "sm" | "md" | "lg";
  setTextscale: (size: "sm" | "md" | "lg") => void;
  userName: string;
  userRole: string;
  userInitials: string;
  userMenuLabels: NasaqUserMenuLabels;
  isAdmin?: boolean;
  technicalDashboardLabel?: string;
  onProfile?: () => void;
  onSettings?: () => void;
  onTechnicalDashboard?: () => void;
  onLogout?: () => void;
  orgName?: string;
  orgMeta?: string;
  organizations?: NasaqOrgOption[];
  currentOrgId?: number;
  orgMenuLabels: NasaqOrgMenuLabels;
  onSwitchOrg?: (id: number) => void;
  onManageOrgs?: () => void;
  sidebarCollapsed: boolean;
  onToggleSidebar: () => void;
  onOpenPalette?: () => void;
};

export function Topbar(props: NasaqTopbarProps) {
  const { labels, crumb, userName, userRole, userInitials, userMenuLabels, isAdmin, technicalDashboardLabel, onProfile, onSettings, onTechnicalDashboard, onLogout, orgName, orgMeta, organizations, currentOrgId, orgMenuLabels, onSwitchOrg, onManageOrgs, lang, setLang, theme, setTheme, textscale, setTextscale, sidebarCollapsed, onToggleSidebar, onOpenPalette } = props;
  const sidebarToggleLabel = sidebarCollapsed ? (labels.expand || "توسيع القائمة") : (labels.collapse || "طي القائمة");
  const adminDashboardLabel = technicalDashboardLabel || "اللوحة التقنية";
  return (
    <header className="nasaq-topbar">
      <div className="nasaq-crumbs">
        <Tooltip content={sidebarToggleLabel} position="bottom">
          <button
            type="button"
            className="nasaq-header-sidebar-toggle"
            onClick={onToggleSidebar}
            aria-label={sidebarToggleLabel}
          >
            <span className="nasaq-header-sidebar-toggle-icon">
              <Icon name="list" className="nasaq-header-sidebar-toggle-bars" />
              <Icon name={sidebarCollapsed ? "chevR" : "chevL"} className="nasaq-header-sidebar-toggle-arrow" />
            </span>
          </button>
        </Tooltip>
        <b>{crumb}</b>
      </div>
      <div className="nasaq-top-search">
        <Icon name="search" style={{ width: 17, height: 17 }} />
        <button
          type="button"
          className="nasaq-top-search-btn"
          onClick={onOpenPalette}
          aria-label={labels.search}
        >
          <span className="nasaq-top-search-placeholder">{labels.search}</span>
        </button>
        <Kbd>⌘K</Kbd>
      </div>
      <div className="nasaq-top-actions">
        {isAdmin && onTechnicalDashboard && (
          <Tooltip content={adminDashboardLabel} position="bottom">
            <button
              type="button"
              className="nasaq-tech-dashboard-btn"
              aria-label={adminDashboardLabel}
              onClick={onTechnicalDashboard}
            >
              <Icon name="shield" className="ic" />
              <span>{adminDashboardLabel}</span>
            </button>
          </Tooltip>
        )}
        <SettingsMenu
          labels={labels}
          lang={lang}
          setLang={setLang}
          theme={theme}
          setTheme={setTheme}
          textscale={textscale}
          setTextscale={setTextscale}
        />
        <button type="button" className="nasaq-icon-btn" aria-label={labels.settings}>
          <span className="nasaq-dot" />
          <Icon name="bell" style={{ width: 18, height: 18 }} />
        </button>
        {orgName && (
          <OrgMenu
            orgName={orgName}
            orgMeta={orgMeta}
            organizations={organizations ?? []}
            currentOrgId={currentOrgId}
            labels={orgMenuLabels}
            isAdmin={isAdmin}
            onSwitchOrg={onSwitchOrg}
            onManageOrgs={onManageOrgs}
          />
        )}
        <UserMenu
          userName={userName}
          userRole={userRole}
          userInitials={userInitials}
          labels={userMenuLabels}
          isAdmin={isAdmin}
          onProfile={onProfile}
          onSettings={onSettings}
          onLogout={onLogout}
        />
      </div>
    </header>
  );
}

export type NasaqSettingsMenuLabels = {
  settings: string;
  fontSize: string;
  language: string;
  appearance: string;
  sizeS: string;
  sizeM: string;
  sizeL: string;
  light: string;
  dark: string;
};

export type NasaqSettingsMenuProps = {
  labels: NasaqSettingsMenuLabels;
  lang: "ar" | "en";
  setLang: (lang: "ar" | "en") => void;
  theme: "light" | "dark";
  setTheme: (theme: "light" | "dark") => void;
  textscale: "sm" | "md" | "lg";
  setTextscale: (size: "sm" | "md" | "lg") => void;
};

export function SettingsMenu({ labels, lang, setLang, theme, setTheme, textscale, setTextscale }: NasaqSettingsMenuProps) {
  const [open, setOpen] = React.useState(false);
  const menuRef = React.useRef<HTMLDivElement>(null);
  React.useEffect(() => {
    if (!open) return;
    const onDown = (e: MouseEvent) => {
      if (menuRef.current && !menuRef.current.contains(e.target as Node)) setOpen(false);
    };
    const onEsc = (e: KeyboardEvent) => {
      if (e.key === "Escape") setOpen(false);
    };
    document.addEventListener("mousedown", onDown);
    document.addEventListener("keydown", onEsc);
    return () => {
      document.removeEventListener("mousedown", onDown);
      document.removeEventListener("keydown", onEsc);
    };
  }, [open]);
  return (
    <div className="nasaq-settings-menu" ref={menuRef}>
      <button type="button" className="nasaq-icon-btn" onClick={() => setOpen((o) => !o)} aria-label={labels.settings} title={labels.settings}>
        <Icon name="settings" style={{ width: 19, height: 19 }} />
      </button>
      {open && (
        <div className="nasaq-menu-pop">
          <div className="nasaq-menu-title">{labels.settings}</div>
          <div className="nasaq-menu-sec">
            <div className="nasaq-menu-lbl"><Icon name="type" className="ic" />{labels.fontSize}</div>
            <div className="nasaq-menu-seg">
              <button type="button" className={textscale === "sm" ? "on" : ""} onClick={() => setTextscale("sm")}><span className="a">A</span>{labels.sizeS}</button>
              <button type="button" className={textscale === "md" ? "on" : ""} onClick={() => setTextscale("md")}><span className="a">A</span>{labels.sizeM}</button>
              <button type="button" className={textscale === "lg" ? "on" : ""} onClick={() => setTextscale("lg")}><span className="a">A</span>{labels.sizeL}</button>
            </div>
          </div>
          <div className="nasaq-menu-sec">
            <div className="nasaq-menu-lbl"><Icon name="globe" className="ic" />{labels.language}</div>
            <div className="nasaq-menu-seg">
              <button type="button" className={lang === "ar" ? "on" : ""} onClick={() => setLang("ar")}>العربية</button>
              <button type="button" className={lang === "en" ? "on" : ""} onClick={() => setLang("en")}>English</button>
            </div>
          </div>
          <div className="nasaq-menu-sec">
            <div className="nasaq-menu-lbl"><Icon name="sun" className="ic" />{labels.appearance}</div>
            <div className="nasaq-menu-seg">
              <button type="button" className={theme === "light" ? "on" : ""} onClick={() => setTheme("light")}><Icon name="sun" className="ic" />{labels.light}</button>
              <button type="button" className={theme === "dark" ? "on" : ""} onClick={() => setTheme("dark")}><Icon name="moon" className="ic" />{labels.dark}</button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}


export type NasaqUserMenuLabels = {
  profile: string;
  settings: string;
  logout: string;
};

export type NasaqUserMenuProps = {
  userName: string;
  userRole: string;
  userInitials: string;
  labels: NasaqUserMenuLabels;
  isAdmin?: boolean;
  onProfile?: () => void;
  onSettings?: () => void;
  onLogout?: () => void;
};

export function UserMenu({ userName, userRole, userInitials, labels, isAdmin, onProfile, onSettings, onLogout }: NasaqUserMenuProps) {
  const [open, setOpen] = React.useState(false);
  const menuRef = React.useRef<HTMLDivElement>(null);
  React.useEffect(() => {
    if (!open) return;
    const onDown = (e: MouseEvent) => {
      if (menuRef.current && !menuRef.current.contains(e.target as Node)) setOpen(false);
    };
    const onEsc = (e: KeyboardEvent) => {
      if (e.key === "Escape") setOpen(false);
    };
    document.addEventListener("mousedown", onDown);
    document.addEventListener("keydown", onEsc);
    return () => {
      document.removeEventListener("mousedown", onDown);
      document.removeEventListener("keydown", onEsc);
    };
  }, [open]);

  const run = (fn?: () => void) => () => {
    setOpen(false);
    fn?.();
  };

  return (
    <div className="nasaq-user-menu" ref={menuRef}>
      <button
        type="button"
        className="nasaq-user-chip"
        onClick={() => setOpen((o) => !o)}
        aria-haspopup="menu"
        aria-expanded={open}
      >
        <div>
          <div className="nm">{userName}</div>
          <div className="rl">{userRole}</div>
        </div>
        <Avatar p={{ initials: userInitials, color: "var(--primary)" }} size={32} />
      </button>
      {open && (
        <div className="nasaq-menu-pop" role="menu" aria-label={userName}>
          <div className="nasaq-user-menu-head">
            <Avatar p={{ initials: userInitials, color: "var(--primary)" }} size={40} />
            <div className="nasaq-user-menu-id">
              <div className="nasaq-user-menu-name">{userName}</div>
              <div className="nasaq-user-menu-role">{userRole}</div>
            </div>
          </div>
          <button type="button" role="menuitem" className="nasaq-user-menu-item" onClick={run(onProfile)}>
            <Icon name="user" className="ic" />{labels.profile}
          </button>
          {isAdmin && (
            <button type="button" role="menuitem" className="nasaq-user-menu-item" onClick={run(onSettings)}>
              <Icon name="settings" className="ic" />{labels.settings}
            </button>
          )}
          <button type="button" role="menuitem" className="nasaq-user-menu-item is-danger" onClick={run(onLogout)}>
            <Icon name="logout" className="ic" />{labels.logout}
          </button>
        </div>
      )}
    </div>
  );
}


export type NasaqOrgOption = { id: number; name: string; code?: string };

export type NasaqOrgMenuLabels = {
  switch: string;
  manage: string;
};

export type NasaqOrgMenuProps = {
  orgName: string;
  orgMeta?: string;
  organizations: NasaqOrgOption[];
  currentOrgId?: number;
  labels: NasaqOrgMenuLabels;
  isAdmin?: boolean;
  onSwitchOrg?: (id: number) => void;
  onManageOrgs?: () => void;
};

export function OrgMenu({ orgName, orgMeta, organizations, currentOrgId, labels, isAdmin, onSwitchOrg, onManageOrgs }: NasaqOrgMenuProps) {
  const [open, setOpen] = React.useState(false);
  const menuRef = React.useRef<HTMLDivElement>(null);
  React.useEffect(() => {
    if (!open) return;
    const onDown = (e: MouseEvent) => {
      if (menuRef.current && !menuRef.current.contains(e.target as Node)) setOpen(false);
    };
    const onEsc = (e: KeyboardEvent) => {
      if (e.key === "Escape") setOpen(false);
    };
    document.addEventListener("mousedown", onDown);
    document.addEventListener("keydown", onEsc);
    return () => {
      document.removeEventListener("mousedown", onDown);
      document.removeEventListener("keydown", onEsc);
    };
  }, [open]);

  const orgTitle = [orgName, orgMeta].filter(Boolean).join(" - ");

  // Interactive only when a switch is meaningful (multiple orgs) or the caller
  // can manage organizations (admins / super_admin). Otherwise render a static
  // chip identical to the previous behavior.
  const interactive = organizations.length > 1 || (isAdmin === true && onManageOrgs != null);

  if (!interactive) {
    return (
      <div className="nasaq-top-org-chip" title={orgTitle}>
        <span className="nasaq-top-org-icon"><Icon name="building" style={{ width: 16, height: 16 }} /></span>
        <span className="nasaq-top-org-copy">
          <span className="nasaq-top-org-name">{orgName}</span>
          {orgMeta && <span className="nasaq-top-org-meta">{orgMeta}</span>}
        </span>
      </div>
    );
  }

  return (
    <div className="nasaq-org-menu" ref={menuRef}>
      <button
        type="button"
        className="nasaq-top-org-chip is-interactive"
        title={orgTitle}
        onClick={() => setOpen((o) => !o)}
        aria-haspopup="menu"
        aria-expanded={open}
      >
        <span className="nasaq-top-org-icon"><Icon name="building" style={{ width: 16, height: 16 }} /></span>
        <span className="nasaq-top-org-copy">
          <span className="nasaq-top-org-name">{orgName}</span>
          {orgMeta && <span className="nasaq-top-org-meta">{orgMeta}</span>}
        </span>
        <Icon name="chevD" className="nasaq-top-org-caret" />
      </button>
      {open && (
        <div className="nasaq-menu-pop" role="menu" aria-label={labels.switch}>
          <div className="nasaq-menu-title">{labels.switch}</div>
          <div className="nasaq-org-menu-list">
            {organizations.map((org) => (
              <button
                key={org.id}
                type="button"
                role="menuitemradio"
                aria-checked={org.id === currentOrgId}
                className={"nasaq-org-menu-item" + (org.id === currentOrgId ? " is-current" : "")}
                onClick={() => {
                  setOpen(false);
                  if (org.id !== currentOrgId) onSwitchOrg?.(org.id);
                }}
              >
                <span className="nasaq-org-menu-id">
                  <span className="nasaq-org-menu-name">{org.name}</span>
                  {org.code && <span className="nasaq-org-menu-code">{org.code}</span>}
                </span>
                {org.id === currentOrgId && <Icon name="tick" className="nasaq-org-menu-tick" />}
              </button>
            ))}
          </div>
          {isAdmin && onManageOrgs && (
            <button
              type="button"
              role="menuitem"
              className="nasaq-org-menu-manage"
              onClick={() => {
                setOpen(false);
                onManageOrgs();
              }}
            >
              <Icon name="settings" className="ic" />{labels.manage}
            </button>
          )}
        </div>
      )}
    </div>
  );
}


// ── App shell: wires Sidebar + Topbar + CommandPalette with shared state ──
const SIDEBAR_COLLAPSED_KEY = "erada:sidebar:collapsed";

export type NasaqAppProps = {
  navGroups: NavGroup[];
  activePath: string;
  activeHref: string;
  onNavigate: (path: string) => void;
  sidebarLabels: NasaqSidebarLabels;
  topbarLabels: NasaqTopbarLabels;
  orgName?: string;
  orgMeta?: string;
  crumb: string;
  lang: "ar" | "en";
  setLang: (lang: "ar" | "en") => void;
  theme: "light" | "dark";
  setTheme: (theme: "light" | "dark") => void;
  textscale: "sm" | "md" | "lg";
  setTextscale: (size: "sm" | "md" | "lg") => void;
  userName: string;
  userRole: string;
  userInitials: string;
  userMenuLabels: NasaqUserMenuLabels;
  isAdmin?: boolean;
  technicalDashboardLabel?: string;
  onProfile?: () => void;
  onSettings?: () => void;
  onTechnicalDashboard?: () => void;
  onLogout?: () => void;
  organizations?: NasaqOrgOption[];
  currentOrgId?: number;
  orgMenuLabels: NasaqOrgMenuLabels;
  onSwitchOrg?: (id: number) => void;
  onManageOrgs?: () => void;
  direction?: "ltr" | "rtl";
  /** i18next t function passed through to the command palette. */
  t: (key: string, fallback?: string) => string;
  /** Optional i18n labels keyed by group id (main, ops, admin). */
  paletteLabels?: Record<string, string>;
  children?: React.ReactNode;
};

export function App(props: NasaqAppProps) {
  const {
    navGroups,
    activePath,
    activeHref,
    onNavigate,
    sidebarLabels,
    topbarLabels,
    orgName,
    orgMeta,
    crumb,
    lang,
    setLang,
    theme,
    setTheme,
    textscale,
    setTextscale,
    userName,
    userRole,
    userInitials,
    userMenuLabels,
    isAdmin,
    technicalDashboardLabel,
    onProfile,
    onSettings,
    onTechnicalDashboard,
    onLogout,
    organizations,
    currentOrgId,
    orgMenuLabels,
    onSwitchOrg,
    onManageOrgs,
    direction,
    t,
    paletteLabels,
  } = props;

  const [paletteOpen, setPaletteOpen] = React.useState(false);
  const [collapsed, setCollapsed] = React.useState<boolean>(() => {
    if (typeof window === "undefined") return false;
    try {
      return window.localStorage.getItem(SIDEBAR_COLLAPSED_KEY) === "1";
    } catch {
      return false;
    }
  });

  React.useEffect(() => {
    if (paletteOpen) return;
    const onKey = (e: KeyboardEvent) => {
      // Some keydown events (browser autofill, IME/compose) arrive with no `key`.
      const k = e.key?.toLowerCase();
      if (!k) return;
      if ((e.metaKey || e.ctrlKey) && k === "k") {
        e.preventDefault();
        setPaletteOpen(true);
      } else if (e.key === "/" && (e.target as HTMLElement | null)?.tagName !== "INPUT" && (e.target as HTMLElement | null)?.tagName !== "TEXTAREA") {
        e.preventDefault();
        setPaletteOpen(true);
      }
    };
    document.addEventListener("keydown", onKey);
    return () => document.removeEventListener("keydown", onKey);
  }, [paletteOpen]);

  React.useEffect(() => {
    try {
      window.localStorage.setItem(SIDEBAR_COLLAPSED_KEY, collapsed ? "1" : "0");
    } catch {
      /* ignore quota / disabled storage */
    }
  }, [collapsed]);

  const handlePaletteNavigate = React.useCallback(
    (path: string) => {
      setPaletteOpen(false);
      onNavigate(path);
    },
    [onNavigate],
  );

  return (
    <div className="nasaq-app" dir={direction}>
      <Sidebar
        labels={sidebarLabels}
        groups={navGroups}
        activePath={activePath}
        activeHref={activeHref}
        onNavigate={onNavigate}
        collapsed={collapsed}
      />
      <div className="nasaq-app-main">
        <Topbar
          labels={topbarLabels}
          crumb={crumb}
          lang={lang}
          setLang={setLang}
          theme={theme}
          setTheme={setTheme}
          textscale={textscale}
          setTextscale={setTextscale}
          userName={userName}
          userRole={userRole}
          userInitials={userInitials}
          userMenuLabels={userMenuLabels}
          isAdmin={isAdmin}
          technicalDashboardLabel={technicalDashboardLabel}
          onProfile={onProfile}
          onSettings={onSettings}
          onTechnicalDashboard={onTechnicalDashboard}
          onLogout={onLogout}
          orgName={orgName}
          orgMeta={orgMeta}
          organizations={organizations}
          currentOrgId={currentOrgId}
          orgMenuLabels={orgMenuLabels}
          onSwitchOrg={onSwitchOrg}
          onManageOrgs={onManageOrgs}
          sidebarCollapsed={collapsed}
          onToggleSidebar={() => setCollapsed((c) => !c)}
          onOpenPalette={() => setPaletteOpen(true)}
        />
        <div className="nasaq-content">
          {props.children}
        </div>
      </div>
      <CommandPalette
        open={paletteOpen}
        onClose={() => setPaletteOpen(false)}
        groups={navGroups}
        labels={paletteLabels}
        onNavigate={handlePaletteNavigate}
        t={t}
      />
    </div>
  );
}
