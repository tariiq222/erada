import React, { Suspense, lazy } from "react";
import { createRoot } from "react-dom/client";
import {
	BrowserRouter,
	Routes,
	Route,
	Navigate,
	Outlet,
} from "react-router-dom";
import { useTranslation } from "react-i18next";
import { AuthProvider } from "@shared/contexts/AuthContext";
import { SystemSettingsProvider } from "@shared/contexts/SystemSettingsContext";
import { LocaleProvider } from "@shared/contexts/LocaleContext";
import { ThemeProvider } from "@shared/contexts/ThemeContext";
import { OrganizationProvider } from "@shared/contexts/OrganizationContext";
import { ToastProvider } from "@shared/ui/Toast";
import { AppLayout } from "@widgets/app-shell";
import { AdminLayout } from "@widgets/admin-shell";
import {
	RequirePermission,
	RequireAdmin,
} from "@features/access-control";
import ErrorBoundary from "@shared/ui/ErrorBoundary";
import "@shared/config/i18n";
import "../css/app.css";
import { initSentry } from "@shared/lib/sentry";

initSentry();

// Lazy loaded pages - تحميل كسول للصفحات لتحسين الأداء
const Login = lazy(() => import("./pages/auth/Login"));
const RegisterPage = lazy(() => import("./pages/auth/RegisterPage"));
const ForgotPasswordPage = lazy(() => import("./pages/auth/ForgotPasswordPage"));
const Dashboard = lazy(() => import("./pages/dashboard/Dashboard"));
const ProjectsList = lazy(() => import("./pages/projects/ProjectsList"));
const ProjectView = lazy(() => import("./pages/projects/ProjectView"));
const ProjectForm = lazy(() => import("./pages/projects/ProjectForm"));
const ProjectSettings = lazy(
	() => import("./pages/projects/ProjectSettings"),
);
const ProjectStatistics = lazy(
	() => import("./pages/projects/ProjectStatistics"),
);
const TasksList = lazy(() => import("./pages/tasks/TasksList"));
const MyTasksList = lazy(() => import("./pages/tasks/MyTasksList"));
const TaskView = lazy(() => import("./pages/tasks/TaskView"));
const TaskForm = lazy(() => import("./pages/tasks/TaskForm"));
const UsersList = lazy(() => import("./pages/users/UsersList"));
const UserView = lazy(() => import("./pages/users/UserView"));
const UserForm = lazy(() => import("./pages/users/UserForm"));
const UserAccessSummary = lazy(() => import("./pages/admin/roles/UserAccessSummary"));
const AccessHub = lazy(() => import("./pages/admin/access/AccessHub"));
const Profile = lazy(() => import("./pages/profile/Profile"));
const DepartmentsList = lazy(() => import("./pages/hr/DepartmentsList"));
const DepartmentView = lazy(() => import("./pages/hr/DepartmentView"));
const DepartmentForm = lazy(() => import("./pages/hr/DepartmentForm"));
const EmployeesList = lazy(() => import("./pages/hr/EmployeesList"));
const EmployeeCreatePage = lazy(() => import("./pages/hr/EmployeeCreatePage"));
const EmployeeEditPage = lazy(() => import("./pages/hr/EmployeeEditPage"));
const IncidentsList = lazy(() => import("./pages/ovr/IncidentsList"));
const IncidentForm = lazy(() => import("./pages/ovr/IncidentForm"));
const IncidentView = lazy(() => import("./pages/ovr/IncidentView"));
const OVRDashboard = lazy(() => import("./pages/ovr/OVRDashboard"));
const OVRSettings = lazy(() => import("./pages/ovr/Settings"));
const PublicTrackReport = lazy(() => import("./pages/ovr/PublicTrackReport"));
const DesignSystem = lazy(() => import("./pages/DesignSystem"));
const TwoFactorVerification = lazy(
	() => import("./pages/auth/TwoFactorVerification"),
);

// Strategy Module (التخطيط التنفيذي)
// PMI Standard: Portfolio -> Program -> Project
const DirectionsList = lazy(
	() => import("./pages/strategy/portfolios/DirectionsList"),
);
const DirectionView = lazy(
	() => import("./pages/strategy/portfolios/DirectionView"),
);
const DirectionForm = lazy(
	() => import("./pages/strategy/portfolios/DirectionForm"),
);
const PortfolioStatistics = lazy(
	() => import("./pages/strategy/portfolios/PortfolioStatistics"),
);
const ProgramsList = lazy(
	() => import("./pages/strategy/programs/ProgramsList"),
);
const ProgramView = lazy(() => import("./pages/strategy/programs/ProgramView"));
const ProgramForm = lazy(() => import("./pages/strategy/programs/ProgramForm"));
const ProgramStatistics = lazy(
	() => import("./pages/strategy/programs/ProgramStatistics"),
);
const MeetingsList = lazy(
	() => import("./pages/strategy/meetings/MeetingsList"),
);
const MeetingView = lazy(
	() => import("./pages/strategy/meetings/MeetingView"),
);
const MeetingForm = lazy(
	() => import("./pages/strategy/meetings/MeetingForm"),
);
const MeetingSettings = lazy(
	() => import("./pages/strategy/meetings/MeetingSettings"),
);
const RecommendationsList = lazy(
	() => import("./pages/strategy/meetings/recommendations/RecommendationsList"),
);
const RecommendationView = lazy(
	() => import("./pages/strategy/meetings/recommendations/RecommendationView"),
);
const RecommendationForm = lazy(
	() => import("./pages/strategy/meetings/recommendations/RecommendationForm"),
);
const NotificationsList = lazy(
	() => import("./pages/strategy/meetings/notifications/NotificationsList"),
);
const ResolutionsPage = lazy(
	() => import("./pages/strategy/meetings/resolutions/ResolutionsPage"),
);
const PerformanceKPIsList = lazy(
	() => import("./pages/performance/KPIsList"),
);
const PerformanceKPIForm = lazy(
	() => import("./pages/performance/KPIForm"),
);
const PerformanceKPIDetail = lazy(
	() => import("./pages/performance/KPIDetail"),
);

// Surveys Module (الاستبيانات)
const SurveysList = lazy(() => import("./pages/surveys/SurveysList"));
const SurveyView = lazy(() => import("./pages/surveys/SurveyView"));
const SurveyForm = lazy(() => import("./pages/surveys/SurveyForm"));
const SurveyBuilder = lazy(() => import("./pages/surveys/SurveyBuilder"));
const SurveyResponses = lazy(() => import("./pages/surveys/SurveyResponses"));
const PublicSurveyPage = lazy(() => import("./pages/surveys/PublicSurveyPage"));

// RiskManagement Module (إدارة المخاطر المؤسسية)
const RisksListPage = lazy(() => import("./pages/risks/RisksListPage"));
const RiskDetailPage = lazy(() => import("./pages/risks/RiskDetailPage"));
const RiskCreatePage = lazy(() => import("./pages/risks/RiskCreatePage"));
const RiskSettingsPage = lazy(() => import("./pages/risks/RiskSettingsPage"));
const RiskStatistics = lazy(() => import("./pages/risks/RiskStatistics"));

const UserStatistics = lazy(() => import("./pages/users/UserStatistics"));
const SurveyStatistics = lazy(() => import("./pages/surveys/SurveyStatistics"));
const DepartmentStatistics = lazy(
	() => import("./pages/hr/DepartmentStatistics"),
);
// Admin Pages (Super Admin only)
// Note: the previous /admin/registrations page (admin approval queue for the
// old invite + admin-approval registration flow) was removed in the
// simplified-registration cutover. Self-registration is now a single-step
// POST /api/register that activates the user immediately — there is no
// pending-approval queue to render.
const OrganizationsList = lazy(
	() => import("./pages/admin/organizations/OrganizationsList"),
);
const OrganizationForm = lazy(
	() => import("./pages/admin/organizations/OrganizationForm"),
);
const ScopeTypesList = lazy(
	() => import("./pages/admin/scope-types/ScopeTypesList"),
);
const ActivityLogsList = lazy(
	() => import("./pages/admin/activity-logs/ActivityLogsList"),
);
// Phase 4A — the activity-log page is gated by AUDIT_VIEW
// (engine capability alias `audit.view`) NOT super-admin. The
// non-admin cluster_auditor role holds AUDIT_VIEW + CLUSTER_TREE_VIEW
// and must be able to reach this page.
const ScopedRoleAuditLogs = lazy(
	() => import("./pages/admin/scoped-roles/ScopedRoleAuditLogs"),
);
const RolesList = lazy(() => import("./pages/admin/roles/RolesList"));
const RoleForm = lazy(() => import("./pages/admin/roles/RoleForm"));
const GoverningDepartments = lazy(() => import("./pages/admin/roles/GoverningDepartments"));

// M1 — Super Admin System Governance Console (read-mostly)
const SuperAdminOverview = lazy(
	() => import("./pages/admin/overview/Overview"),
);
const SuperAdminSecurityAlerts = lazy(
	() => import("./pages/admin/security-alerts/SecurityAlerts"),
);
const SuperAdminAuditRecent = lazy(
	() => import("./pages/admin/audit-recent/AuditRecent"),
);

// مكون التحميل
const PageLoader: React.FC = () => {
	const { t } = useTranslation();
	return (
		<main className="flex items-center justify-center min-h-screen">
			<div className="flex flex-col items-center gap-4">
				<div
					className="w-12 h-12 border-4 border-primary border-t-transparent rounded-full animate-spin"
					role="status"
					aria-live="polite"
					aria-label={t("common.loading")}
				/>
				<p className="text-[var(--text-secondary)]" aria-hidden="true">
					{t("common.loading")}
				</p>
			</div>
		</main>
	);
};

const App: React.FC = () => {
	return (
		<ErrorBoundary>
			<ThemeProvider>
				<BrowserRouter>
					<AuthProvider>
						<OrganizationProvider>
							<SystemSettingsProvider>
								<LocaleProvider>
									<ToastProvider>
										<ErrorBoundary>
											<Suspense fallback={<PageLoader />}>
												<Routes>
													{/* Public Routes */}
													<Route
														path="/login"
														element={<Login />}
													/>
													<Route
														path="/register"
														element={<RegisterPage />}
													/>
													<Route
														path="/forgot-password"
														element={
															<ForgotPasswordPage />
														}
													/>
													<Route
														path="/verify-2fa"
														element={
															<TwoFactorVerification />
														}
													/>
													<Route
														path="/design-system"
														element={
															<DesignSystem />
														}
													/>
													<Route
														path="/s/:code"
														element={
															<PublicSurveyPage />
														}
													/>
													<Route
														path="/ovr/track"
														element={
															<PublicTrackReport />
														}
													/>
													<Route
														path="/ovr/track/:tracking_token"
														element={
															<PublicTrackReport />
														}
													/>

													{/* Protected Routes */}
													<Route
														element={<AppLayout />}
													>
														<Route
															path="/dashboard"
															element={
																<RequirePermission
																	config={{
																		permission:
																			"view_dashboard",
																	}}
																>
																	<Dashboard />
																</RequirePermission>
															}
														/>

														{/* Projects */}
														<Route
															path="/projects"
															element={
																<RequirePermission
																	config={{
																		permissions:
																			[
																				"projects.view",
																				"view_own_projects",
																			],
																	}}
																>
																	<ProjectsList />
																</RequirePermission>
															}
														/>
														<Route
															path="/projects/statistics"
															element={
																<RequirePermission
																	config={{
																		permissions:
																			[
																				"projects.view",
																				"view_own_projects",
																			],
																	}}
																>
																	<ProjectStatistics />
																</RequirePermission>
															}
														/>
														<Route
															path="/projects/create"
															element={
																<RequirePermission
																	config={{
																		permission:
																			"projects.create",
																	}}
																>
																	<ProjectForm />
																</RequirePermission>
															}
														/>
														<Route
															path="/projects/settings"
															element={
																<RequireAdmin>
																	<ProjectSettings />
																</RequireAdmin>
															}
														/>
														<Route
															path="/projects/:id"
															element={
																<RequirePermission
																	config={{
																		permissions:
																			[
																				"projects.view",
																				"view_own_projects",
																			],
																	}}
																>
																	<ProjectView />
																</RequirePermission>
															}
														/>
														<Route
															path="/projects/:id/edit"
															element={
																<RequirePermission
																	config={{
																		permissions:
																			[
																				"projects.edit",
																				"edit_own_projects",
																			],
																	}}
																>
																	<ProjectForm />
																</RequirePermission>
															}
														/>

														{/* Tasks */}
														<Route
															path="/tasks"
															element={
																<RequirePermission
																	config={{
																		permissions:
																			[
																				"tasks.view",
																				"view_own_tasks",
																			],
																	}}
																>
																	<TasksList />
																</RequirePermission>
															}
														/>
														<Route
															path="/my-tasks"
															element={
																<RequirePermission
																	config={{
																		permissions:
																			[
																				"tasks.view",
																				"view_own_tasks",
																			],
																	}}
																>
																	<MyTasksList />
																</RequirePermission>
															}
														/>
														<Route
															path="/tasks/create"
															element={
																<RequirePermission
																	config={{
																		permission:
																			"tasks.create",
																	}}
																>
																	<TaskForm />
																</RequirePermission>
															}
														/>
														<Route
															path="/tasks/:id"
															element={
																<RequirePermission
																	config={{
																		permissions:
																			[
																				"tasks.view",
																				"view_own_tasks",
																			],
																	}}
																>
																	<TaskView />
																</RequirePermission>
															}
														/>
														<Route
															path="/tasks/:id/edit"
															element={
																<RequirePermission
																	config={{
																		permissions:
																			[
																				"tasks.edit",
																				"edit_own_tasks",
																			],
																	}}
																>
																	<TaskForm />
																</RequirePermission>
															}
														/>

														{/* Model statistics pages */}
														<Route
															path="/risk-management/statistics"
															element={
																<RequirePermission
																	config={{
																		permission:
																			"risks.view",
																	}}
																>
																	<RiskStatistics />
																</RequirePermission>
															}
														/>
														<Route
															path="/ovr/statistics"
															element={
																<RequirePermission
																	config={{
																		allPermissions:
																			[
																				"ovr.view_statistics",
																			],
																		permissions:
																			[
																				"ovr.view_own",
																				"ovr.view_department",
																				"ovr.view_all",
																			],
																	}}
																>
																	<OVRDashboard />
																</RequirePermission>
															}
														/>
														<Route
															path="/users/statistics"
															element={
																<RequirePermission
																	config={{
																		permission:
																			"users.view",
																	}}
																>
																	<UserStatistics />
																</RequirePermission>
															}
														/>
														<Route
															path="/surveys/statistics"
															element={
																<RequirePermission
																	config={{
																		permission:
																			"surveys.view",
																	}}
																>
																	<SurveyStatistics />
																</RequirePermission>
															}
														/>
														<Route
															path="/hr/departments/statistics"
															element={
																<RequirePermission
																	config={{
																		permission:
																			"departments.view",
																	}}
																>
																	<DepartmentStatistics />
																</RequirePermission>
															}
														/>
														<Route
															path="/admin/departments/statistics"
															element={
																<Navigate
																	to="/hr/departments/statistics"
																	replace
																/>
															}
														/>
														{/* Users */}
														<Route
															path="/users"
															element={
																<RequirePermission
																	config={{
																		permission:
																			"users.view",
																	}}
																>
																	<UsersList />
																</RequirePermission>
															}
														/>
														<Route
															path="/users/create"
															element={
																<RequirePermission
																	config={{
																		permission:
																			"users.create",
																	}}
																>
																	<UserForm />
																</RequirePermission>
															}
														/>
														<Route
															path="/users/:id"
															element={
																<RequirePermission
																	config={{
																		permission:
																			"users.view",
																	}}
																>
																	<UserView />
																</RequirePermission>
															}
														/>
														<Route
															path="/users/:id/access"
															element={
																<RequirePermission
																	config={{
																		permission:
																			"users.view",
																	}}
																>
																	<UserAccessSummary />
																</RequirePermission>
															}
														/>
														<Route
															path="/users/:id/edit"
															element={
																<RequirePermission
																	config={{
																		permission:
																			"users.edit",
																	}}
																>
																	<UserForm />
																</RequirePermission>
															}
														/>

														{/* Profile */}
														<Route
															path="/profile"
															element={
																<Profile />
															}
														/>

														{/* Employees (HR) */}
														<Route
															path="/hr/employees"
															element={
																<RequirePermission
																	config={{
																		permission:
																			"hr.view",
																	}}
																>
																	<EmployeesList />
																</RequirePermission>
															}
														/>
														<Route
															path="/hr/employees/create"
															element={
																<RequirePermission
																	config={{
																		permission:
																			"manage_hr",
																	}}
																>
																	<EmployeeCreatePage />
																</RequirePermission>
															}
														/>
														<Route
															path="/hr/employees/:id/edit"
															element={
																<RequirePermission
																	config={{
																		permission:
																			"manage_hr",
																	}}
																>
																	<EmployeeEditPage />
																</RequirePermission>
															}
														/>

														{/* Departments */}
														<Route
															path="/hr/departments"
															element={
																<RequirePermission
																	config={{
																		permission:
																			"departments.view",
																	}}
																>
																	<DepartmentsList />
																</RequirePermission>
															}
														/>

														{/* Department Create */}
														<Route
															path="/hr/departments/new"
															element={
																<RequirePermission
																	config={{
																		permission:
																			"departments.create",
																	}}
																>
																	<DepartmentForm />
																</RequirePermission>
															}
														/>

														{/* Department Edit */}
														<Route
															path="/hr/departments/:id/edit"
															element={
																<RequirePermission
																	config={{
																		permission:
																			"departments.edit",
																	}}
																>
																	<DepartmentForm />
																</RequirePermission>
															}
														/>

														{/* Department Detail */}
														<Route
															path="/hr/departments/:id"
															element={
																<RequirePermission
																	config={{
																		permission:
																			"departments.view",
																	}}
																>
																	<DepartmentView />
																</RequirePermission>
															}
														/>

														{/* OVR Module */}
														<Route
															path="/ovr/incidents"
															element={
																<RequirePermission
																	config={{
																		permissions:
																			[
																				"ovr.view_own",
																				"ovr.view_department",
																				"ovr.view_all",
																			],
																	}}
																>
																	<IncidentsList />
																</RequirePermission>
															}
														/>
														<Route
															path="/ovr/incidents/new"
															element={
																<RequirePermission
																	config={{
																		permission:
																			"ovr.create",
																	}}
																>
																	<IncidentForm />
																</RequirePermission>
															}
														/>
														<Route
															path="/ovr/incidents/:tracking_token"
															element={
																<RequirePermission
																	config={{
																		permissions:
																			[
																				"ovr.view_own",
																				"ovr.view_department",
																				"ovr.view_all",
																			],
																	}}
																>
																	<IncidentView />
																</RequirePermission>
															}
														/>
														<Route
															path="/ovr/incidents/:reportNumber/edit"
															element={
																<RequirePermission
																	config={{
																		permissions:
																			[
																				"ovr.edit_own",
																				"ovr.edit_all",
																			],
																	}}
																>
																	<IncidentForm />
																</RequirePermission>
															}
														/>
														<Route
															path="/ovr/settings"
															element={
																<RequirePermission
																	config={{
																		permission:
																			"ovr.manage_types",
																	}}
																>
																	<OVRSettings />
																</RequirePermission>
															}
														/>

														{/* Strategy - التخطيط التنفيذي (PMI Standard) */}
														<Route
															path="/strategy"
															element={
																<Navigate
																	to="/strategy/portfolios"
																	replace
																/>
															}
														/>

														{/* Portfolios (الالتزامات التنفيذية) */}
														<Route
															path="/strategy/portfolios"
															element={
																<RequirePermission
																	config={{
																		permission:
																			"strategy.view",
																	}}
																>
																	<DirectionsList />
																</RequirePermission>
															}
														/>
														<Route
															path="/strategy/portfolios/new"
															element={
																<RequirePermission
																	config={{
																		permission:
																			"strategy.create",
																	}}
																>
																	<DirectionForm />
																</RequirePermission>
															}
														/>
														<Route
															path="/strategy/portfolios/statistics"
															element={
																<RequirePermission
																	config={{
																		permission:
																			"strategy.view",
																	}}
																>
																	<PortfolioStatistics />
																</RequirePermission>
															}
														/>
														<Route
															path="/strategy/portfolios/:id"
															element={
																<RequirePermission
																	config={{
																		permission:
																			"strategy.view",
																	}}
																>
																	<DirectionView />
																</RequirePermission>
															}
														/>
														<Route
															path="/strategy/portfolios/:id/edit"
															element={
																<RequirePermission
																	config={{
																		permission:
																			"strategy.edit",
																	}}
																>
																	<DirectionForm />
																</RequirePermission>
															}
														/>

												{/* Meetings (الاجتماعات) */}
												<Route
													path="/strategy/meetings"
													element={
														<RequirePermission
															config={{
																permission:
																	"meetings.view",
															}}
														>
															<MeetingsList />
														</RequirePermission>
													}
												/>

												<Route
														path="/strategy/meetings/new"
														element={
															<RequirePermission
																config={{
															permission:
																"meetings.create",
																}}
															>
																<MeetingForm mode="page" />
															</RequirePermission>
														}
													/>
													<Route
														path="/strategy/meetings/settings"
														element={
															<RequirePermission
																config={{
																	permission:
																		"meetings.edit",
																}}
															>
																<MeetingSettings />
															</RequirePermission>
														}
													/>
													<Route
														path="/strategy/meetings/:id"
														element={
															<RequirePermission
																config={{
																	permission:
																		"meetings.view",
																}}
															>
																<MeetingView />
															</RequirePermission>
														}
													/>
													<Route
														path="/strategy/meetings/:id/edit"
														element={
															<RequirePermission
																config={{
																	permission:
																		"meetings.edit",
																}}
															>
																<MeetingForm mode="page" />
															</RequirePermission>
														}
													/>

													{/* Recommendations (التوصيات) */}
													<Route
														path="/strategy/meetings/recommendations"
														element={
															<RequirePermission
																config={{
																permission: "recommendations.view",
																}}
															>
																<RecommendationsList />
															</RequirePermission>
														}
													/>
													<Route
														path="/strategy/meetings/recommendations/new"
														element={
															<RequirePermission
																config={{
																permission: "recommendations.create",
																}}
															>
																<RecommendationForm mode="page" />
															</RequirePermission>
														}
													/>
													<Route
														path="/strategy/meetings/recommendations/:id"
														element={
															<RequirePermission
																config={{
																permission: "recommendations.view",
																}}
															>
																<RecommendationView />
															</RequirePermission>
														}
													/>
													<Route
														path="/strategy/meetings/recommendations/:id/edit"
														element={
															<RequirePermission
																config={{
																permission: "recommendations.edit",
																}}
															>
																<RecommendationForm mode="page" />
															</RequirePermission>
														}
													/>
													<Route
														path="/strategy/meetings/notifications"
														element={
															<RequirePermission
																config={{
																	permission: "meetings.view",
																}}
															>
																<NotificationsList />
															</RequirePermission>
														}
													/>
													<Route
														path="/strategy/meetings/resolutions"
														element={
															<RequirePermission
																config={{
																	permission: "meeting_resolutions.view",
																}}
															>
																<ResolutionsPage />
															</RequirePermission>
														}
													/>

													{/* Programs (المبادرات) - PMI Standard */}
														<Route
															path="/strategy/programs"
															element={
																<RequirePermission
																	config={{
																		permission:
																			"strategy.view",
																	}}
																>
																	<ProgramsList />
																</RequirePermission>
															}
														/>
														<Route
															path="/strategy/programs/new"
															element={
																<RequirePermission
																	config={{
																		permission:
																			"strategy.create",
																	}}
																>
																	<ProgramForm />
																</RequirePermission>
															}
														/>
														<Route
															path="/strategy/programs/statistics"
															element={
																<RequirePermission
																	config={{
																		permission:
																			"strategy.view",
																	}}
																>
																	<ProgramStatistics />
																</RequirePermission>
															}
														/>
												<Route
													path="/strategy/programs/:id"
													element={
														<RequirePermission
															config={{
																permission:
																	"strategy.view",
															}}
														>
															<ProgramView />
														</RequirePermission>
													}
												/>
												<Route
													path="/strategy/programs/:id/edit"
													element={
														<RequirePermission
															config={{
																permission:
																	"strategy.edit",
															}}
														>
															<ProgramForm />
														</RequirePermission>
													}
												/>

												{/* Performance KPIs */}
												<Route
													path="/performance/kpis"
													element={
														<RequirePermission
															config={{
																permissions:
																	[
																		"strategy.view",
																		"view_reports",
																	],
															}}
														>
															<PerformanceKPIsList />
														</RequirePermission>
													}
												/>
												<Route
													path="/performance/kpis/new"
													element={
														<RequirePermission
															config={{
																permission:
																	"strategy.create",
															}}
														>
															<PerformanceKPIForm />
														</RequirePermission>
													}
												/>
												<Route
													path="/performance/kpis/:id"
													element={
														<RequirePermission
															config={{
																permissions:
																	[
																		"strategy.view",
																		"view_reports",
																	],
															}}
														>
															<PerformanceKPIDetail />
														</RequirePermission>
													}
												/>
												<Route
													path="/performance/kpis/:id/edit"
													element={
														<RequirePermission
															config={{
																permission:
																	"strategy.edit",
															}}
														>
															<PerformanceKPIForm />
														</RequirePermission>
													}
												/>

												{/* Surveys - الاستبيانات */}
														<Route
															path="/surveys"
															element={
																<RequirePermission
																	config={{
																		permission:
																			"surveys.view",
																	}}
																>
																	<SurveysList />
																</RequirePermission>
															}
														/>
														<Route
															path="/surveys/create"
															element={
																<RequirePermission
																	config={{
																		allPermissions:
																			[
																				"surveys.view",
																				"surveys.create",
																			],
																	}}
																>
																	<SurveyForm />
																</RequirePermission>
															}
														/>
														<Route
															path="/surveys/:id"
															element={
																<RequirePermission
																	config={{
																		permission:
																			"surveys.view",
																	}}
																>
																	<SurveyView />
																</RequirePermission>
															}
														/>
														<Route
															path="/surveys/:id/edit"
															element={
																<RequirePermission
																	config={{
																		allPermissions:
																			[
																				"surveys.view",
																				"surveys.edit",
																			],
																	}}
																>
																	<SurveyForm />
																</RequirePermission>
															}
														/>
														<Route
															path="/surveys/:id/builder"
															element={
																<RequirePermission
																	config={{
																		allPermissions:
																			[
																				"surveys.view",
																				"surveys.edit",
																			],
																	}}
																>
																	<SurveyBuilder />
																</RequirePermission>
															}
														/>
														<Route
															path="/surveys/:id/responses"
															element={
																<RequirePermission
																	config={{
																		allPermissions:
																			[
																				"surveys.view",
																				"view_survey_responses",
																			],
																	}}
																>
																	<SurveyResponses />
																</RequirePermission>
															}
														/>
													</Route>

													{/* Super Admin Control Plane — distinct shell for /admin/* */}
													<Route element={<AdminLayout />}>
														<Route
															path="/admin"
															element={
																<Navigate
																	to="/admin/overview"
																	replace
																/>
															}
														/>
														{/* Admin Pages (Super Admin only) */}
														<Route
															path="/admin/organizations"
															element={
																<RequireAdmin>
																	<OrganizationsList />
																</RequireAdmin>
															}
														/>
														<Route
															path="/admin/organizations/new"
															element={
																<RequireAdmin>
																	<OrganizationForm />
																</RequireAdmin>
															}
														/>
														<Route
															path="/admin/organizations/:id"
															element={
																<RequireAdmin>
																	<OrganizationForm />
																</RequireAdmin>
															}
														/>
														<Route
															path="/admin/scope-types"
															element={
																<RequireAdmin>
																	<ScopeTypesList />
																</RequireAdmin>
															}
														/>
														<Route
															path="/admin/activity-logs"
															element={
																<RequirePermission
																	config={{
																		permissions: [
																			"audit.view",
																			"audit.export",
																		],
																	}}
																>
																	<ActivityLogsList />
																</RequirePermission>
															}
														/>
														{/* M1 Super Admin System Governance Console */}
														<Route
															path="/admin/overview"
															element={
																<RequireAdmin>
																	<SuperAdminOverview />
																</RequireAdmin>
															}
														/>
														<Route
															path="/admin/security/alerts"
															element={
																<RequireAdmin>
																	<SuperAdminSecurityAlerts />
																</RequireAdmin>
															}
														/>
														<Route
															path="/admin/audit/recent"
															element={
																<RequireAdmin>
																	<SuperAdminAuditRecent />
																</RequireAdmin>
															}
														/>
														<Route
															path="/admin/scoped-roles/audit-logs"
															element={
																<RequireAdmin>
																	<ScopedRoleAuditLogs />
																</RequireAdmin>
															}
														/>
														<Route
															path="/admin/access"
															element={
																<RequireAdmin>
																	<AccessHub />
																</RequireAdmin>
															}
														/>
														<Route
															path="/admin/roles"
															element={
																<RequireAdmin>
																	<RolesList />
																</RequireAdmin>
															}
														/>
														<Route
															path="/admin/roles/governing-departments"
															element={
																<RequireAdmin>
																	<GoverningDepartments />
																</RequireAdmin>
															}
														/>
														<Route
															path="/admin/roles/new"
															element={
																<RequireAdmin>
																	<RoleForm />
																</RequireAdmin>
															}
														/>
														<Route
															path="/admin/roles/:id"
															element={
																<RequireAdmin>
																	<RoleForm />
																</RequireAdmin>
															}
														/>
														<Route
															path="/admin/users"
															element={
																<RequireAdmin>
																	<UsersList />
																</RequireAdmin>
															}
														/>
														<Route
															path="/admin/users/create"
															element={
																<RequireAdmin>
																	<UserForm />
																</RequireAdmin>
															}
														/>
														<Route
															path="/admin/users/:id"
															element={
																<RequireAdmin>
																	<UserView />
																</RequireAdmin>
															}
														/>
														<Route
															path="/admin/users/:id/edit"
															element={
																<RequireAdmin>
																	<UserForm />
																</RequireAdmin>
															}
														/>
														<Route
															path="/admin/departments"
															element={
																<RequireAdmin>
																	<DepartmentsList />
																</RequireAdmin>
															}
														/>
														<Route
															path="/admin/incident-types"
															element={
																<RequireAdmin>
																	<OVRSettings />
																</RequireAdmin>
															}
														/>
													</Route>

													<Route element={<AppLayout />}>
														{/* RiskManagement Module (إدارة المخاطر المؤسسية) */}
														<Route
															path="/risk-management"
															element={
																<RequirePermission
																	config={{
																		permission:
																			"risks.view",
																	}}
																>
																	<Outlet />
																</RequirePermission>
															}
														>
															<Route
																index
																element={
																	<Navigate
																		to="/risk-management/risks"
																		replace
																	/>
																}
															/>
															<Route
																path="risks"
																element={
																	<RequirePermission
																		config={{
																			permission:
																				"risks.view",
																		}}
																	>
																		<RisksListPage />
																	</RequirePermission>
																}
															/>
															<Route
																path="create"
																element={
																	<RequirePermission
																		config={{
																			permission:
																				"risks.create",
																		}}
																	>
																		<RiskCreatePage />
																	</RequirePermission>
																}
															/>
															<Route
																path="settings"
																element={
																	<RequireAdmin>
																		<RiskSettingsPage />
																	</RequireAdmin>
																}
															/>
															<Route
																path="risks/:id"
																element={
																	<RequirePermission
																		config={{
																			permission:
																				"risks.view",
																		}}
																	>
																		<RiskDetailPage />
																	</RequirePermission>
																}
															/>
														</Route>

														{/* Redirect root to dashboard */}
														<Route
															path="/"
															element={
																<Navigate
																	to="/dashboard"
																	replace
																/>
															}
														/>
													</Route>

													{/* Catch all - redirect to dashboard */}
													<Route
														path="*"
														element={
															<Navigate
																to="/dashboard"
																replace
															/>
														}
													/>
												</Routes>
											</Suspense>
										</ErrorBoundary>
									</ToastProvider>
								</LocaleProvider>
							</SystemSettingsProvider>
						</OrganizationProvider>
					</AuthProvider>
				</BrowserRouter>
			</ThemeProvider>
		</ErrorBoundary>
	);
};

const container = document.getElementById("app");
if (container) {
	const root = createRoot(container);
	// إزالة StrictMode مؤقتاً لتجنب double-rendering في التطوير
	root.render(<App />);
}
