import React, { useEffect, useMemo, useState } from "react";
import { NavLink, useLocation } from "react-router-dom";
import { useTranslation } from "react-i18next";
import { cn } from "@shared/lib/utils";
import { useAuth, AccessConfig } from "@shared/contexts/AuthContext";
import { useSystemSettings } from "@shared/contexts/SystemSettingsContext";
import {IconAlertOctagon, IconAlertTriangle, IconChartBar, IconBriefcase, IconBuilding, IconSquareCheck, IconChevronLeft, IconClipboardList, IconLayoutKanban, IconHistory, IconLayoutDashboard, IconLayoutSidebarLeftCollapse, IconLayoutSidebarLeftExpand, IconLifebuoy, IconNetwork, IconPlus, IconRocket, IconSettings, IconShield, IconShieldCheck, IconTag, IconTarget, IconUsers, type LucideIcon} from '@tabler/icons-react';
import { IconClipboardCheck } from '@shared/ui/icons';

interface SidebarProps {
	isOpen: boolean;
	onToggle: () => void;
}

interface NavItem {
	label: string;
	href: string;
	icon: LucideIcon;
	access?: AccessConfig;
	children?: NavItem[];
}

type SectionId =
	| "dashboard"
	| "strategy"
	| "projects"
	| "tasks"
	| "surveys"
	| "ovr"
	| "risks"
	| "admin";

interface NavSection {
	id: SectionId;
	title: string;
	icon: LucideIcon;
	items: NavItem[];
}

const deriveSectionId = (pathname: string): SectionId => {
	if (pathname.startsWith("/admin")) return "admin";
	if (pathname.startsWith("/ovr")) return "ovr";
	if (pathname.startsWith("/risk-management")) return "risks";
	if (pathname.startsWith("/strategy")) return "strategy";
	if (pathname.startsWith("/projects")) return "projects";
	if (pathname.startsWith("/tasks")) return "tasks";
	if (pathname.startsWith("/surveys")) return "surveys";
	return "dashboard";
};

const shouldUseExactMatch = (href: string) =>
	href === "/dashboard" ||
	href === "/strategy" ||
	href === "/strategy/portfolios" ||
	href === "/strategy/programs" ||
	href === "/projects";

const Sidebar: React.FC<SidebarProps> = ({ isOpen, onToggle }) => {
	const { t } = useTranslation();
	const { canAccess } = useAuth();
	const { settings: systemSettings } = useSystemSettings();
	const location = useLocation();
	const [railOpen, setRailOpen] = useState(false);
	const [activeSectionId, setActiveSectionId] = useState<SectionId>(() =>
		deriveSectionId(location.pathname),
	);
	const [mobileOpenSections, setMobileOpenSections] = useState<SectionId[]>(
		() => [deriveSectionId(location.pathname)],
	);
	const [expandedItems, setExpandedItems] = useState<string[]>([]);

	useEffect(() => {
		const derivedSectionId = deriveSectionId(location.pathname);
		setActiveSectionId(derivedSectionId);
		setMobileOpenSections((current) =>
			current.includes(derivedSectionId)
				? current
				: [...current, derivedSectionId],
		);
	}, [location.pathname]);

	const sections = useMemo<NavSection[]>(() => {
		const baseSections: NavSection[] = [
			{
				id: "dashboard",
				title: t("nav.dashboard"),
				icon: IconLayoutDashboard,
				items: [
					{
						label: t("nav.dashboard"),
						href: "/dashboard",
						icon: IconLayoutDashboard,
					},
				],
			},
			{
				id: "strategy",
				title: t("nav.strategy"),
				icon: IconTarget,
				items: [
					{
						label: t("nav.portfolios"),
						href: "/strategy/portfolios",
						icon: IconBriefcase,
						access: { permission: "strategy.view" },
						children: [
							{
								label: t("strategy.create_new_portfolio"),
								href: "/strategy/portfolios/new",
								icon: IconPlus,
								access: { permission: "strategy.create" },
							},
							{
								label: t("strategy.portfolios.allPortfolios"),
								href: "/strategy/portfolios",
								icon: IconBriefcase,
								access: { permission: "strategy.view" },
							},
							{
								label: t("nav.statistics"),
								href: "/strategy/portfolios/statistics",
								icon: IconChartBar,
								access: { permission: "strategy.view" },
							},
						],
					},
					{
						label: t("nav.programs"),
						href: "/strategy/programs",
						icon: IconRocket,
						access: { permission: "strategy.view" },
						children: [
							{
								label: t("strategy.create_new_program"),
								href: "/strategy/programs/new",
								icon: IconPlus,
								access: { permission: "strategy.create" },
							},
							{
								label: t("strategy.programs.programs"),
								href: "/strategy/programs",
								icon: IconRocket,
								access: { permission: "strategy.view" },
							},
							{
								label: t("nav.statistics"),
								href: "/strategy/programs/statistics",
								icon: IconChartBar,
								access: { permission: "strategy.view" },
							},
						],
					},
					{
						label: t("strategy.decisions.title"),
						href: "/strategy/decisions",
						icon: IconClipboardCheck,
						access: { permission: "meetings.view" },
					},
				],
			},
			{
				id: "projects",
				title: t("nav.projects"),
				icon: IconLayoutKanban,
				items: [
					{
						label: t("nav.projects_list"),
						href: "/projects",
						icon: IconBriefcase,
						access: {
							permissions: ["projects.view", "view_own_projects"],
						},
					},
				],
			},
			{
				id: "tasks",
				title: t("nav.tasks"),
				icon: IconSquareCheck,
				items: [
					{
						label: t("nav.tasks"),
						href: "/tasks",
						icon: IconSquareCheck,
						access: {
							permissions: ["tasks.view", "view_own_tasks"],
						},
					},
				],
			},
			{
				id: "ovr",
				title: t("nav.ovr"),
				icon: IconAlertTriangle,
				items: [
					{
						label: t("nav.incidents"),
						href: "/ovr/incidents",
						icon: IconAlertTriangle,
						access: {
							permissions: [
								"ovr.view_own",
								"ovr.view_department",
								"ovr.view_all",
							],
						},
					},
					{
						label: t("ovr.report_incident"),
						href: "/ovr/incidents/new",
						icon: IconPlus,
						access: { permission: "ovr.create" },
					},
					{
						label: t("nav.statistics"),
						href: "/ovr/statistics",
						icon: IconChartBar,
						access: {
							allPermissions: ["ovr.view_statistics"],
							permissions: [
								"ovr.view_own",
								"ovr.view_department",
								"ovr.view_all",
							],
						},
					},
					{
						label: t("nav.ovr_settings"),
						href: "/ovr/settings",
						icon: IconSettings,
						access: { permission: "ovr.manage_types" },
					},
				],
			},
			{
				id: "risks",
				title: t("nav.risks"),
				icon: IconAlertOctagon,
				items: [
					{
						label: t("nav.risks_list"),
						href: "/risk-management/risks",
						icon: IconAlertOctagon,
						access: { permission: "risks.view" },
					},
					{
						label: t("nav.statistics"),
						href: "/risk-management/statistics",
						icon: IconChartBar,
						access: { permission: "risks.view" },
					},
				],
			},
			{
				id: "surveys",
				title: t("nav.surveys"),
				icon: IconClipboardList,
				items: [
					{
						label: t("nav.surveys"),
						href: "/surveys",
						icon: IconClipboardList,
						access: { permission: "surveys.view" },
					},
					{
						label: t("nav.statistics"),
						href: "/surveys/statistics",
						icon: IconChartBar,
						access: { permission: "surveys.view" },
					},
				],
			},
		];

		// Phase 9.3 freeze cleanup (2026-07-06): the admin rail was previously
		// gated on the legacy `manage_organization` transition-only string
		// (via `isAdmin()`). It resolves to `false` from Phase 9.3 onward, so
		// the rail would have vanished for every admin. We now gate on the
		// canonical `core.view_organizations` capability (the umbrella
		// org-read key the engine seeds for the org-admin role). The rail
		// still self-filters its child items via `filterNavItems` so users
		// who only hold the umbrella capability see only the entries their
		// own item-level access allows.
		if (canAccess({ permission: "core.view_organizations" })) {
			baseSections.push({
				id: "admin",
				title: t("nav.admin_section"),
				icon: IconSettings,
				items: [
					{
						label: t("admin.organizations.title"),
						href: "/admin/organizations",
						icon: IconBuilding,
					},
					{
						label: t("admin.departments.title"),
						href: "/admin/departments",
						icon: IconNetwork,
					},
					{
						label: t("hr.departments_statisticsTitle"),
						href: "/admin/departments/statistics",
						icon: IconChartBar,
					},
					{
						label: t("admin.users.title"),
						href: "/admin/users",
						icon: IconUsers,
					},
					{
						label: t("admin.roles.title"),
						href: "/admin/roles",
						icon: IconShield,
					},
					{
						label: t("admin.scopeTypes.title"),
						href: "/admin/scope-types",
						icon: IconTag,
					},
					{
						label: t("admin.incidentTypes.title"),
						href: "/admin/incident-types",
						icon: IconAlertTriangle,
					},
					{
						label: t("admin.activityLogs.title"),
						href: "/admin/activity-logs",
						icon: IconHistory,
					},
					{
						label: t("admin.scopedRolesAudit.title"),
						href: "/admin/scoped-roles/audit-logs",
						icon: IconShieldCheck,
					},
				],
			});
		}

		return baseSections;
	}, [canAccess, t]);

	const expandableItemHrefs = useMemo(
		() =>
			sections.flatMap((section) =>
				section.items
					.filter((item) => item.children?.length)
					.map((item) => item.href),
			),
		[sections],
	);

	useEffect(() => {
		const matchingParentHrefs = expandableItemHrefs.filter(
			(href) =>
				location.pathname === href ||
				location.pathname.startsWith(`${href}/`),
		);

		if (matchingParentHrefs.length === 0) return;

		setExpandedItems((current) => {
			const next = [...current];

			matchingParentHrefs.forEach((href) => {
				if (!next.includes(href)) next.push(href);
			});

			return next.length === current.length ? current : next;
		});
	}, [expandableItemHrefs, location.pathname]);

	const filteredSections = useMemo(() => {
		const filterNavItems = (items: NavItem[]): NavItem[] =>
			items
				.filter((item) => {
					if (!item.access) return true;
					return canAccess(item.access);
				})
				.map((item) =>
					item.children
						? { ...item, children: filterNavItems(item.children) }
						: item,
				);

		return sections
			.map((section) => ({
				...section,
				items: filterNavItems(section.items),
			}))
			.filter((section) => section.items.length > 0);
	}, [canAccess, sections]);

	const activeSection =
		filteredSections.find((section) => section.id === activeSectionId) ??
		filteredSections[0];
	const topRailSections = filteredSections.filter(
		(section) => section.id !== "admin",
	);
	const adminSection = filteredSections.find(
		(section) => section.id === "admin",
	);
	const brandName = systemSettings?.name || t("common.app_name", "إرادة");
	const helpLabel = t("nav.help", "الدعم والمساعدة");

	const toggleRailOpen = () => setRailOpen((current) => !current);

	const toggleMobileSection = (sectionId: SectionId) => {
		setMobileOpenSections((current) =>
			current.includes(sectionId)
				? current.filter((id) => id !== sectionId)
				: [...current, sectionId],
		);
	};

	const toggleExpandedItem = (href: string) => {
		setExpandedItems((current) =>
			current.includes(href)
				? current.filter((itemHref) => itemHref !== href)
				: [...current, href],
		);
	};

	const renderRailSectionButton = (section: NavSection) => {
		const isActive = section.id === activeSection?.id;
		const SectionIcon = section.icon;

		return (
			<button
				key={section.id}
				type="button"
				onClick={() => {
					setActiveSectionId(section.id);
					if (railOpen) setRailOpen(false);
				}}
				aria-current={isActive ? "true" : undefined}
				aria-label={section.title}
				className={cn(
					"group relative flex h-11 w-full items-center rounded-md text-[var(--text-tertiary)] transition-colors",
					railOpen
						? "justify-start gap-3 px-3"
						: "justify-center px-0",
					"hover:bg-[var(--surface-muted)] hover:text-[var(--text-secondary)]",
					isActive &&
						"bg-[var(--accent-subtle)] text-[var(--accent-hover)]",
				)}
			>
				<SectionIcon className="h-5 w-5 shrink-0" />
				{railOpen && (
					<span className="min-w-0 truncate text-sm font-medium">
						{section.title}
					</span>
				)}
				{!railOpen && (
					<span className="pointer-events-none absolute end-[calc(100%+0.75rem)] top-1/2 z-[60] hidden -translate-y-1/2 whitespace-nowrap rounded-md bg-[var(--text-primary)] px-2 py-1 text-xs font-medium text-[var(--text-inverse)] shadow-lg group-hover:block">
						{section.title}
					</span>
				)}
			</button>
		);
	};

	const renderPanelLink = (item: NavItem, closeOnClick = false) => {
		const ItemIcon = item.icon;

		if (item.children) {
			const isExpanded = expandedItems.includes(item.href);
			const isGroupActive =
				location.pathname === item.href ||
				location.pathname.startsWith(`${item.href}/`);

			return (
				<div key={item.href} className="space-y-1">
					<button
						type="button"
						onClick={() => toggleExpandedItem(item.href)}
						aria-expanded={isExpanded}
						className={cn(
							"flex min-h-10 items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors",
							"text-[var(--text-secondary)] hover:bg-[var(--surface-muted)] hover:text-[var(--text-primary)]",
							isGroupActive &&
								"bg-[var(--accent-subtle)] text-[var(--accent-hover)]",
						)}
					>
						<ItemIcon className="h-[18px] w-[18px] shrink-0 opacity-90" />
						<span className="min-w-0 flex-1 truncate">
							{item.label}
						</span>
						<IconChevronLeft
							className={cn(
								"h-4 w-4 shrink-0 text-[var(--text-tertiary)] transition-transform",
								isExpanded && "-rotate-90",
							)}
						/>
					</button>
					{isExpanded &&
						item.children.map((child) => {
							const ChildIcon = child.icon;

							return (
								<NavLink
									key={child.href}
									to={child.href}
									end={shouldUseExactMatch(child.href)}
									onClick={
										closeOnClick ? onToggle : undefined
									}
									className={({ isActive }) =>
										cn(
											"flex min-h-10 items-center gap-3 rounded-md py-2 pe-3 ps-9 text-sm font-medium transition-colors",
											"text-[var(--text-secondary)] hover:bg-[var(--surface-muted)] hover:text-[var(--text-primary)]",
											isActive &&
												"bg-[var(--accent-subtle)] text-[var(--accent-hover)]",
										)
									}
								>
									<ChildIcon className="h-[18px] w-[18px] shrink-0 opacity-90" />
									<span className="min-w-0 truncate">
										{child.label}
									</span>
								</NavLink>
							);
						})}
				</div>
			);
		}

		return (
			<NavLink
				key={item.href}
				to={item.href}
				end={shouldUseExactMatch(item.href)}
				onClick={closeOnClick ? onToggle : undefined}
				className={({ isActive }) =>
					cn(
						"flex min-h-10 items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors",
						"text-[var(--text-secondary)] hover:bg-[var(--surface-muted)] hover:text-[var(--text-primary)]",
						isActive &&
							"bg-[var(--accent-subtle)] text-[var(--accent-hover)]",
					)
				}
			>
				<ItemIcon className="h-[18px] w-[18px] shrink-0 opacity-90" />
				<span className="min-w-0 truncate">{item.label}</span>
			</NavLink>
		);
	};

	return (
		<>
			{isOpen && (
				<div
					className="fixed inset-0 z-40 bg-[var(--text-primary)]/60 lg:hidden"
					onClick={onToggle}
				/>
			)}

			<aside className="fixed bottom-0 start-0 top-0 z-50 hidden w-[264px] overflow-visible lg:flex">
				<div
					className={cn(
						"flex h-screen shrink-0 flex-col gap-1 overflow-visible border-e border-[var(--border-default)] bg-[var(--surface-base)] px-2 py-4 transition-[width] duration-300",
						railOpen ? "w-[264px]" : "w-16",
					)}
				>
					<div
						className={cn(
							"relative mb-4 flex h-10 items-center gap-3 px-1",
							railOpen ? "justify-start" : "justify-center",
						)}
					>
						<div data-testid="brand-mark" className="grid h-9 w-9 shrink-0 place-items-center rounded-lg overflow-hidden">
							<img src="/images/logo.png" alt={brandName} className="h-9 w-9 object-contain" />
						</div>
						{railOpen && (
							<span className="min-w-0 truncate text-base font-semibold text-[var(--text-primary)]">
								{brandName}
							</span>
						)}
						<button
							type="button"
							onClick={toggleRailOpen}
							aria-label={
								railOpen
									? "طي قائمة الأقسام"
									: "توسيع قائمة الأقسام"
							}
							className={cn(
								"grid h-8 w-8 shrink-0 place-items-center rounded-md text-[var(--text-tertiary)] transition-colors hover:bg-[var(--surface-muted)] hover:text-[var(--text-secondary)]",
								railOpen ? "ms-auto" : "absolute end-1 top-1",
							)}
						>
							{railOpen ? (
								<IconLayoutSidebarLeftCollapse
									className="sidebar-collapse-icon h-5 w-5"
									stroke={1.8}
									aria-hidden="true"
								/>
							) : (
								<IconLayoutSidebarLeftExpand
									className="sidebar-collapse-icon h-5 w-5"
									stroke={1.8}
									aria-hidden="true"
								/>
							)}
						</button>
					</div>

					<nav
						className="flex flex-1 flex-col gap-1"
						aria-label="Sidebar sections"
					>
						{topRailSections.map(renderRailSectionButton)}
						<div className="mt-auto" />
						{adminSection && renderRailSectionButton(adminSection)}
						<button
							type="button"
							className={cn(
								"group relative flex h-11 w-full items-center rounded-md text-[var(--text-tertiary)] transition-colors hover:bg-[var(--surface-muted)] hover:text-[var(--text-secondary)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--accent-default)] focus-visible:ring-offset-2",
								railOpen
									? "justify-start gap-3 px-3"
									: "justify-center px-0",
							)}
							aria-label={helpLabel}
						>
							<IconLifebuoy className="h-5 w-5 shrink-0" aria-hidden="true" />
							{railOpen && (
								<span className="min-w-0 truncate text-sm font-medium">
									{helpLabel}
								</span>
							)}
							{!railOpen && (
								<span className="pointer-events-none absolute end-[calc(100%+0.75rem)] top-1/2 z-[60] hidden -translate-y-1/2 whitespace-nowrap rounded-md bg-[var(--text-primary)] px-2 py-1 text-xs font-medium text-[var(--text-inverse)] shadow-lg group-hover:block">
									{helpLabel}
								</span>
							)}
						</button>
					</nav>
				</div>

				<div
					className={cn(
						"flex h-screen shrink-0 flex-col gap-6 overflow-hidden border-e border-[var(--border-default)] bg-[var(--surface-base)] px-4 py-6 transition-[width,padding,opacity] duration-300",
						railOpen
							? "w-0 border-e-0 px-0 opacity-0"
							: "w-[200px] opacity-100",
					)}
					aria-hidden={railOpen}
				>
					<header className="flex h-10 items-center gap-2 px-2">
						<h2 className="min-w-0 flex-1 truncate text-base font-semibold text-[var(--text-primary)]">
							{activeSection?.title}
						</h2>
						<button
							type="button"
							onClick={toggleRailOpen}
							aria-label="توسيع قائمة الأقسام"
							className="grid h-8 w-8 shrink-0 place-items-center rounded-md text-[var(--text-tertiary)] transition-colors hover:bg-[var(--surface-muted)] hover:text-[var(--text-secondary)]"
						>
							<IconChevronLeft className="h-4 w-4 rotate-180" />
						</button>
					</header>

					<nav className="flex flex-1 flex-col gap-1 overflow-y-auto">
						{activeSection?.items.map((item) =>
							renderPanelLink(item),
						)}
					</nav>
				</div>
			</aside>

			{isOpen && (
				<aside className="fixed bottom-0 start-0 top-0 z-50 flex w-full max-w-sm flex-col overflow-hidden border-e border-[var(--border-default)] bg-[var(--surface-base)] shadow-2xl lg:hidden">
					<header className="flex h-16 items-center gap-3 border-b border-[var(--border-default)] px-4">
						<div data-testid="brand-mark" className="grid h-9 w-9 shrink-0 place-items-center rounded-lg overflow-hidden">
							<img src="/images/logo.png" alt={brandName} className="h-9 w-9 object-contain" />
						</div>
						<div className="min-w-0 flex-1">
							<h1 className="truncate text-base font-semibold text-[var(--text-primary)]">
								{brandName}
							</h1>
							{systemSettings?.code && (
								<p className="truncate text-xs text-[var(--text-tertiary)]">
									{systemSettings.code}
								</p>
							)}
						</div>
						<button
							type="button"
							onClick={onToggle}
							aria-label="إغلاق القائمة"
							className="grid h-9 w-9 shrink-0 place-items-center rounded-md text-[var(--text-tertiary)] transition-colors hover:bg-[var(--surface-muted)] hover:text-[var(--text-secondary)]"
						>
							<IconChevronLeft className="h-5 w-5" />
						</button>
					</header>

					<nav className="flex-1 space-y-2 overflow-y-auto px-4 py-4">
						{filteredSections.map((section) => {
							const isExpanded = mobileOpenSections.includes(
								section.id,
							);
							const SectionIcon = section.icon;

							return (
								<section
									key={section.id}
									className="rounded-lg border border-[var(--border-default)] bg-[var(--surface-base)]"
								>
									<button
										type="button"
										onClick={() =>
											toggleMobileSection(section.id)
										}
										aria-expanded={isExpanded}
										className="flex w-full items-center gap-3 rounded-lg px-3 py-3 text-start text-sm font-semibold text-[var(--text-primary)] transition-colors hover:bg-[var(--surface-muted)]"
									>
										<SectionIcon className="h-5 w-5 shrink-0 text-[var(--text-tertiary)]" />
										<span className="min-w-0 flex-1 truncate">
											{section.title}
										</span>
										<IconChevronLeft
											className={cn(
												"h-4 w-4 shrink-0 text-[var(--text-tertiary)] transition-transform",
												isExpanded && "-rotate-90",
											)}
										/>
									</button>
									{isExpanded && (
										<div className="space-y-1 border-t border-[var(--border-default)] p-2">
											{section.items.map((item) =>
												renderPanelLink(item, true),
											)}
										</div>
									)}
								</section>
							);
						})}
					</nav>
				</aside>
			)}
		</>
	);
};

export default Sidebar;
