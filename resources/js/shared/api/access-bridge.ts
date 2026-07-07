import type { AccessConfig } from '@shared/contexts/AuthContext';
import type { User } from '@shared/types';

/**
 * Central frontend compatibility bridge for the AuthZ unification migration.
 *
 * Phase 8 of the master plan wired a structured `user.access` payload
 * alongside the legacy `user.permissions[]` array. The single-source
 * backend decision path is `AccessDecision::can(User, Capability, ?Model)`;
 * this bridge is the equivalent decision path on the React side. Every
 * `AuthContext.hasPermission`, `hasAnyPermission`, `isAdmin`, and
 * `canAccess` call routes through these helpers, so a route guard that
 * still says `permission: 'view_projects'` will resolve access-first.
 *
 * Phase 9.3 cutover (2026-07-05): `user.permissions[]` is REMOVED from the
 * `/api/auth/me` payload. The bridge below is updated to consult ONLY
 * `user.access` (canonical capabilities) and the legacy dotted-to-canonical
 * mapping for legacy strings that already have a canonical equivalent.
 *
 * Rules:
 *   - `user.access` is the single source of truth.
 *   - `user.permissions[]` is no longer read (it is no longer in the payload).
 *   - Transition-only legacy permissions (manage_organization,
 *     view_own_*, ovr.view_own, etc.) have NO canonical capability and
 *     therefore resolve to `false` unless a future engine cutover introduces
 *     them. Their consumers (Header/AppLayout isAdmin, route guards) MUST be
 *     migrated to a canonical alternative before this becomes user-visible.
 *   - `super_admin` always wins — both here and in the engine.
 *   - Do NOT use these helpers for per-record decisions; read
 *     `element.abilities.*` from the resource payload instead.
 */
const DOTTED_CAPABILITY = /^[a-z_]+\.[a-z_]+$/;

export const LEGACY_PERMISSION_TO_CAPABILITY: Record<string, string> = {
	view_organizations: 'core.view_organizations',
	assign_roles: 'core.assign_roles',
	view_users: 'users.view',
	create_users: 'users.create',
	edit_users: 'users.edit',
	delete_users: 'users.delete',
	view_projects: 'projects.view',
	create_projects: 'projects.create',
	edit_projects: 'projects.edit',
	delete_projects: 'projects.delete',
	view_tasks: 'tasks.view',
	create_tasks: 'tasks.create',
	edit_tasks: 'tasks.edit',
	delete_tasks: 'tasks.delete',
	view_roles: 'roles.view',
	create_roles: 'roles.create',
	edit_roles: 'roles.edit',
	delete_roles: 'roles.delete',
	upload_attachments: 'attachments.upload',
	download_attachments: 'attachments.view',
	delete_attachments: 'attachments.delete',
	create_comments: 'comments.create',
	edit_comments: 'comments.edit',
	delete_comments: 'comments.delete',
	view_audit_logs: 'audit.view',
	export_audit_logs: 'audit.export',
	view_strategy: 'strategy.view',
	create_strategy: 'strategy.create',
	edit_strategy: 'strategy.edit',
	delete_strategy: 'strategy.delete',
	'view-meetings': 'meetings.view',
	'manage-meetings': 'meetings.edit',
	// Direction B migration (2026-07-06): the legacy `record-decisions`
	// Spatie string used to map to `meetings.record_decisions`, but the
	// engine never enforced that capability for recommendation lifecycle
	// transitions. Now that the engine exposes `recommendations.{approve,
	// reject, defer, accept, complete}` capabilities, we pivot the legacy
	// key onto the new ruling-side approve capability so stale sessions
	// still resolve a sensible default. Per-action gating in the UI must
	// always go through `useCan('recommendations.<action>')` (see
	// RecommendationCard / RecommendationView / RecommendationStatusActions).
	'record-decisions': 'recommendations.approve',
	'meetings.record_decisions': 'recommendations.approve',
	'recommendations.view': 'recommendations.view',
	'recommendations.create': 'recommendations.create',
	'recommendations.edit': 'recommendations.edit',
	'recommendations.delete': 'recommendations.delete',
	'recommendations.approve': 'recommendations.approve',
	'recommendations.reject': 'recommendations.reject',
	'recommendations.defer': 'recommendations.defer',
	'recommendations.accept': 'recommendations.accept',
	'recommendations.complete': 'recommendations.complete',
	// Phase 8-E: `view_survey_responses` historically pointed at
	// `surveys.view` (the survey-metadata capability), but the backend
	// Phase 8-D correction mapped it to `surveys.review_responses` because
	// the Spatie permission gated RESPONSE data (PII from survey_responses)
	// rather than survey metadata. The bridge now mirrors the backend
	// CapabilityAlias::map() so legacy route guards with
	// `permission: 'view_survey_responses'` resolve to the same canonical
	// capability that the engine's `authorize()` and engine_capability
	// middleware already enforce.
	view_survey_responses: 'surveys.review_responses',
	review_survey_responses: 'surveys.review_responses',
	view_surveys: 'surveys.view',
	// Phase 8-E: align with backend CapabilityAlias::map() — both
	// `review_data_imports` and `view_dashboard` have canonical capability
	// equivalents (`surveys.review_data_imports` and `dashboard.view`).
	// They were kept in TRANSITION_ONLY_PERMISSIONS before this fix, which
	// meant `permissionToCapability()` returned `null` for them and the
	// bridge denied access even when the user held the correct capability
	// in `user.access`. The frontend now mirrors the backend.
	review_data_imports: 'surveys.review_data_imports',
	view_dashboard: 'dashboard.view',
	create_surveys: 'surveys.create',
	edit_surveys: 'surveys.edit',
	delete_surveys: 'surveys.delete',
	view_departments: 'departments.view',
	create_departments: 'departments.create',
	edit_departments: 'departments.edit',
	delete_departments: 'departments.delete',
	view_hr: 'hr.view',
	manage_hr: 'hr.manage',
	view_risks: 'risks.view',
	create_risks: 'risks.create',
	edit_risks: 'risks.edit',
	delete_risks: 'risks.delete',
	reassess_risks: 'risks.reassess',
	change_risk_status: 'risks.change_status',
	view_risk_reports: 'risks.view_reports',
	'ovr.view_all': 'ovr.view_all',
	'ovr.confidential': 'ovr.confidential',
	'ovr.create': 'ovr.create',
	'ovr.edit': 'ovr.edit',
	'ovr.edit_all': 'ovr.edit',
	'ovr.change_status': 'ovr.change_status',
	'ovr.assign': 'ovr.assign',
	'ovr.comment': 'ovr.comment',
	'ovr.view_internal_comments': 'ovr.view_internal_comments',
	'ovr.export': 'ovr.export',
	'ovr.view_statistics': 'ovr.view_statistics',
	'ovr.manage_types': 'ovr.manage_types',
	'kpis.view': 'kpis.view',
	'kpis.create': 'kpis.create',
	'kpis.edit': 'kpis.edit',
	'kpis.delete': 'kpis.delete',
	'kpis.manage': 'kpis.manage',
	// Sunset map (Phase 9.3 freeze cleanup): legacy `projects.manage_members`
	// was the old member-management gate on the project. It was deprecated
	// (audit 2026-07-06) and replaced with the canonical `projects.assign_roles`
	// which is enforced by the unified engine and surfaced via the role assignment
	// UI. Anything still reading the legacy capability should be migrated;
	// the bridge keeps this 1-version mapping so legacy callers do not lock
	// users out during the cutover window. Remove in the release after
	// the FE Phase 9.3 freeze lands.
	'projects.manage_members': 'projects.assign_roles',
};

/**
 * Legacy permission names with no canonical dotted capability yet. They
 * keep flowing through `permissions[]` only until the Phase 9 cleanup
 * freeze decides an owner for each. The bridge refuses to map them so
 * the test suite is the only thing that needs to know about them.
 */
export const TRANSITION_ONLY_PERMISSIONS: ReadonlySet<string> = new Set([
	'manage_organization',
	'create_organizations',
	'edit_organizations',
	'delete_organizations',
	'view_reports',
	'export_reports',
	'edit_any_comment',
	'delete_any_comment',
	'view_own_projects',
	'view_own_tasks',
	'ovr.view_own',
	'ovr.view_department',
	'view_department_risks',
	'view_own_risks',
	'edit_department_risks',
	'edit_own_risks',
]);

export function hasStructuredCapability(
	user: User | null | undefined,
	capability: string,
): boolean {
	if (!user || !DOTTED_CAPABILITY.test(capability)) {
		return false;
	}
	const [moduleName, action] = capability.split('.', 2);
	return user.access?.[moduleName]?.[action] === true;
}

export function permissionToCapability(permission: string): string | null {
	if (!permission) {
		return null;
	}
	if (DOTTED_CAPABILITY.test(permission)) {
		return permission;
	}
	return LEGACY_PERMISSION_TO_CAPABILITY[permission] ?? null;
}

export function hasPermissionCompat(
	user: User | null | undefined,
	permission: string,
): boolean {
	if (!user) {
		return false;
	}
	if (user.roles?.includes('super_admin')) {
		return true;
	}
	const capability = permissionToCapability(permission);
	if (capability && hasStructuredCapability(user, capability)) {
		return true;
	}
	// Phase 9.3 cutover: `user.permissions[]` is no longer in the `/api/auth/me`
	// payload. Transition-only legacy strings (manage_organization, view_own_*,
	// ovr.view_own, etc.) resolve to `false` here until canonical capabilities
	// are introduced — owners MUST migrate their consumers before this becomes
	// user-visible (see docs/authz/deprecation-policy.md).
	return false;
}

export function canAccessCompat(
	user: User | null | undefined,
	config: AccessConfig,
): boolean {
	if (!user) {
		return false;
	}
	if (user.roles?.includes('super_admin')) {
		return true;
	}

	if (config.allPermissions?.length) {
		const hasAll = config.allPermissions.every((permission) =>
			hasPermissionCompat(user, permission),
		);
		if (!hasAll) {
			return false;
		}
	}

	const hasAnySelector = Boolean(
		config.roles?.length ||
			config.permission ||
			config.permissions?.length,
	);

	if (!hasAnySelector) {
		return Boolean(config.allPermissions?.length);
	}

	if (config.roles?.some((role) => user.roles?.includes(role))) {
		return true;
	}

	if (config.permission && hasPermissionCompat(user, config.permission)) {
		return true;
	}

	if (config.permissions?.some((permission) =>
		hasPermissionCompat(user, permission),
	)) {
		return true;
	}

	return false;
}
