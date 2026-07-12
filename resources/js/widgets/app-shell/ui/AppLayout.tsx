import React, { useEffect, useMemo, useState } from "react";
import { Outlet, Navigate, useLocation, useNavigate } from "react-router-dom";
import { useTranslation } from "react-i18next";
import { useAuth } from "@shared/contexts/AuthContext";
import { useTheme } from "@shared/contexts/ThemeContext";
import { useLocale } from "@shared/contexts/LocaleContext";
import { useOrganization } from "@shared/contexts/OrganizationContext";
import { useSystemSettings } from "@shared/contexts/SystemSettingsContext";
import {
  NASAQ_NAV,
  NASAQ_PALETTES,
  NASAQ_BG_THEMES,
  buildNasaqGroups,
  App,
} from "@shared/nasaq/app";
import {IconSparkles, IconLoader} from '@tabler/icons-react';

const NAV_KEY_TO_I18N: Record<string, string> = {
  dashboard: "nav.dashboard",
  tasks: "nav.tasks",
  my_tasks: "nav.my_tasks",
  projects: "nav.projects",
  strategy: "nav.strategy",
  ovr: "nav.ovr",
  surveys: "nav.surveys",
  risks: "nav.risks",
  departments: "nav.departments",
  employees: "nav.employees",
  users: "nav.users",
  organizations: "nav.organizations",
  roles: "nav.roles",
  activity: "nav.activity_logs",
};

const PATH_TO_KEY: Record<string, string> = (() => {
  const m: Record<string, string> = {};
  for (const item of NASAQ_NAV) {
    m[item.path] = item.key;
    if (item.path === "/tasks") m["/my-tasks"] = "my_tasks";
  }
  return m;
})();

function deriveActiveKey(pathname: string): string {
  if (pathname.startsWith("/admin/activity-logs")) return "activity";
  if (pathname.startsWith("/admin/organizations")) return "organizations";
  if (pathname.startsWith("/admin/roles")) return "roles";
  if (pathname.startsWith("/admin/users")) return "users";
  if (pathname.startsWith("/hr/employees")) return "employees";
  if (pathname.startsWith("/hr")) return "departments";
  if (pathname.startsWith("/ovr")) return "ovr";
  if (pathname.startsWith("/risk-management")) return "risks";
  if (pathname.startsWith("/surveys")) return "surveys";
  if (pathname.startsWith("/strategy")) return "strategy";
  if (pathname.startsWith("/projects")) return "projects";
  if (pathname.startsWith("/my-tasks")) return "my_tasks";
  if (pathname.startsWith("/tasks")) return "tasks";
  if (pathname.startsWith("/dashboard")) return "dashboard";
  if (PATH_TO_KEY[pathname]) return PATH_TO_KEY[pathname];
  return "dashboard";
}

type BrandKey = keyof typeof NASAQ_PALETTES;
type BgKey = keyof typeof NASAQ_BG_THEMES;
type Textscale = "sm" | "md" | "lg";
type Density = "compact" | "regular" | "spacious";
type Character = "soft" | "balanced" | "sharp";

const STORAGE_KEY = "erada:nasaq:tweaks";

type Tweaks = {
  brand: BrandKey;
  bg: BgKey;
  textscale: Textscale;
  density: Density;
  character: Character;
};

const DEFAULT_TWEAKS: Tweaks = {
  brand: "blue",
  bg: "cool",
  textscale: "md",
  density: "regular",
  character: "balanced",
};

function loadTweaks(): Tweaks {
  if (typeof window === "undefined") return DEFAULT_TWEAKS;
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (!raw) return DEFAULT_TWEAKS;
    return { ...DEFAULT_TWEAKS, ...JSON.parse(raw) };
  } catch {
    return DEFAULT_TWEAKS;
  }
}

function getInitials(name?: string): string {
  if (!name) return "U";
  const parts = name.trim().split(/\s+/);
  if (parts.length === 1) return parts[0]!.slice(0, 2);
  return (parts[0]![0]! + parts[parts.length - 1]![0]!).toUpperCase();
}

const AppLayout: React.FC = () => {
  const { t, i18n } = useTranslation();
  const { isAuthenticated, isLoading, user, can, refreshUser, logout } = useAuth();
  const { resolvedTheme, setTheme } = useTheme();
  const { direction, setLocale } = useLocale();
  const { currentOrganization, organizations, switchOrganization } = useOrganization();
  const { settings: systemSettings } = useSystemSettings();
  const navigate = useNavigate();
  const location = useLocation();

  const [tweaks, setTweaks] = useState<Tweaks>(() => loadTweaks());

  useEffect(() => {
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(tweaks));
    } catch {
      // ignore quota errors
    }
  }, [tweaks]);

  // Apply brand palette CSS variables
  useEffect(() => {
    const pal = NASAQ_PALETTES[tweaks.brand] || NASAQ_PALETTES.blue;
    const v = resolvedTheme === "dark" ? pal.dark : pal.light;
    const root = document.documentElement;
    root.style.setProperty("--primary", v.p);
    root.style.setProperty("--primary-2", v.p2);
    root.style.setProperty("--primary-soft", v.soft);
    root.style.setProperty("--primary-soft-2", v.soft2);
    root.style.setProperty("--ring", v.ring);
    root.setAttribute("data-brand", tweaks.brand);
  }, [tweaks.brand, resolvedTheme]);

  // Apply bg theme CSS variables
  useEffect(() => {
    const bgT = NASAQ_BG_THEMES[tweaks.bg] || NASAQ_BG_THEMES.cool;
    const v = resolvedTheme === "dark" ? bgT.dark : bgT.light;
    const root = document.documentElement;
    root.style.setProperty("--bg", v.bg);
    root.style.setProperty("--bg-grad", v.grad);
    root.style.setProperty("--surface", v.s1);
    root.style.setProperty("--surface-2", v.s2);
    root.style.setProperty("--surface-3", v.s3);
    root.style.setProperty("--border", v.b1);
    root.style.setProperty("--border-2", v.b2);
    root.setAttribute("data-bg", tweaks.bg);
  }, [tweaks.bg, resolvedTheme]);

  // Apply other tweak attributes
  useEffect(() => {
    const root = document.documentElement;
    root.setAttribute("data-textscale", tweaks.textscale);
    root.setAttribute("data-density", tweaks.density);
    root.setAttribute("data-character", tweaks.character);
  }, [tweaks.textscale, tweaks.density, tweaks.character]);

  // Apply theme + locale to <html>
  useEffect(() => {
    const root = document.documentElement;
    root.setAttribute("data-theme", resolvedTheme);
    root.classList.toggle("dark", resolvedTheme === "dark");
  }, [resolvedTheme]);

  useEffect(() => {
    const root = document.documentElement;
    root.setAttribute("lang", i18n.language);
    root.setAttribute("dir", direction);
  }, [i18n.language, direction]);

  const navGroups = useMemo(
    () => buildNasaqGroups((key) => t(key), can, can('core.view_organizations')),
    [t, can],
  );

  if (isLoading) {
    return (
      <div className="min-h-screen bg-[var(--accent-default)] flex items-center justify-center">
        <div className="text-center">
          <div className="relative mb-6">
            <div className="h-16 w-16 rounded-2xl bg-[var(--text-inverse)]/20 flex items-center justify-center mx-auto animate-pulse motion-reduce:animate-none">
              <IconSparkles className="h-8 w-8 text-[var(--text-inverse)]" />
            </div>
          </div>
          <div className="flex items-center justify-center mb-3">
            <IconLoader className="h-5 w-5 text-[var(--text-inverse)] opacity-80 animate-spin motion-reduce:animate-none" />
          </div>
          <p className="text-[var(--text-inverse)] font-medium opacity-80">{t("common.loading")}</p>
        </div>
      </div>
    );
  }

  if (!isAuthenticated) {
    return <Navigate to="/login" replace />;
  }

  const brandName = (i18n.language === "en" && systemSettings?.name_en)
    ? systemSettings.name_en
    : (systemSettings?.name || t("common.app_name", "إرادة"));

  const activeKey = deriveActiveKey(location.pathname);
  const crumb = t(NAV_KEY_TO_I18N[activeKey] || "nav.dashboard", NAV_KEY_TO_I18N[activeKey]?.split(".").pop() || "");



  const lang = (i18n.language === "en" ? "en" : "ar") as "ar" | "en";
  const theme = (resolvedTheme === "dark" ? "dark" : "light") as "light" | "dark";
  const userName = user?.name || "";
  const userInitials = getInitials(user?.name);
  const primaryRole = user?.role_assignments?.[0];
  const userRole = primaryRole
    ? t(`role.${primaryRole.role}`, primaryRole.label || primaryRole.role)
    : "";

  const sidebarLabels = {
    brandName,
    brandSub: brandName,
    search: t("common.search_system", "بحث سريع في النظام..."),
    sectionPlanning: t("nav.planning", "التخطيط"),
    sectionQuality: t("nav.quality", "الجودة"),
    sectionCrisis: t("nav.crisis_disasters_tool", "أداة الأزمات والكوارث"),
    sectionOps: t("nav.operations", "العمليات"),
    sectionAdmin: t("nav.administration", "الإدارة"),
  };

  const activeHref = `${location.pathname}${location.search}`;

  const topbarLabels = {
    brandSub: brandName,
    search: t("common.search_system", "بحث سريع في النظام..."),
    settings: t("nav.settings", "الإعدادات"),
    fontSize: t("common.font_size", "حجم الخط"),
    language: t("common.language", "اللغة"),
    appearance: t("common.appearance", "المظهر"),
    sizeS: t("common.size_small", "صغير"),
    sizeM: t("common.size_medium", "متوسط"),
    sizeL: t("common.size_large", "كبير"),
    light: t("theme.light", "فاتح"),
    dark: t("theme.dark", "داكن"),
  };

  const orgName = currentOrganization?.name || "";
  const orgMeta = currentOrganization?.code || "";

  return (
    <App
      navGroups={navGroups}
      activePath={location.pathname}
      activeHref={activeHref}
      onNavigate={(path: string) => {
        navigate(path);
      }}
      sidebarLabels={sidebarLabels}
      topbarLabels={topbarLabels}
      orgName={orgName}
      orgMeta={orgMeta}
      crumb={crumb}
      lang={lang}
      setLang={(next) => {
        void setLocale(next);
      }}
      theme={theme}
      setTheme={(next) => {
        setTheme(next);
      }}
      textscale={tweaks.textscale}
      setTextscale={(s) => setTweaks((prev) => ({ ...prev, textscale: s }))}
      userName={userName}
      userRole={userRole}
      userInitials={userInitials}
      technicalDashboardLabel={t("nav.technical_dashboard", "اللوحة التقنية")}
      userMenuLabels={{
        profile: t("nav.profile", "الملف الشخصي"),
        settings: t("nav.settings", "الإعدادات"),
        logout: t("nav.logout", "تسجيل الخروج"),
      }}
      isAdmin={can('core.view_organizations')}
      onProfile={() => navigate("/profile")}
      onTechnicalDashboard={() => navigate("/admin/overview")}
      onSettings={() => navigate("/admin/organizations")}
      onLogout={() => {
        void logout();
      }}
      organizations={organizations}
      currentOrgId={currentOrganization?.id}
      orgMenuLabels={{
        switch: t("org.switch", "تبديل المؤسسة"),
        manage: t("admin.organizations.title", "إدارة المؤسسات"),
      }}
      onSwitchOrg={(id) => {
        void switchOrganization(id).then(refreshUser);
      }}
      onManageOrgs={() => navigate("/admin/organizations")}
      direction={direction}
      t={(key: string, fallback?: string) => t(key, fallback || "")}
      paletteLabels={{
        main: t("nav.main", "الرئيسية"),
        planning: t("nav.planning", "التخطيط"),
        quality: t("nav.quality", "الجودة"),
        crisis: t("nav.crisis_disasters_tool", "أداة الأزمات والكوارث"),
        ops: t("nav.operations", "العمليات"),
        admin: t("nav.administration", "الإدارة"),
      }}
    >
      <main className="flex-1 min-w-0">
        <Outlet />
      </main>
    </App>
  );
};

export default AppLayout;
