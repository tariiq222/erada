import { describe, expect, it } from 'vitest';
import {
	canAccessCompat,
	hasPermissionCompat,
	hasStructuredCapability,
	permissionToCapability,
} from '@shared/api/access-bridge';
import type { AccessConfig } from '@shared/contexts/AuthContext';
import type { User } from '@shared/types';

function makeUser(overrides: Partial<User> = {}): User {
	return {
		id: 1,
		name: 'Test',
		email: 't@e.com',
		department_id: null,
		phone: null,
		extension: null,
		job_title: null,
		is_active: true,
		roles: [],
		permissions: [],
		access: undefined,
		...overrides,
	};
}

describe('access-bridge', () => {
	describe('hasStructuredCapability', () => {
		it('canonical capability succeeds from user.access with empty permissions[]', () => {
			const user = makeUser({
				permissions: [],
				access: { projects: { view: true } },
			});
			expect(hasStructuredCapability(user, 'projects.view')).toBe(true);
		});

		it('denies a canonical capability that is not in the access map', () => {
			const user = makeUser({ access: { projects: { view: true } } });
			expect(hasStructuredCapability(user, 'projects.create')).toBe(false);
		});

		it('returns false for malformed capability strings', () => {
			const user = makeUser({ access: { projects: { view: true } } });
			expect(hasStructuredCapability(user, 'view_projects')).toBe(false);
			expect(hasStructuredCapability(user, '')).toBe(false);
			expect(hasStructuredCapability(user, 'projects')).toBe(false);
			expect(hasStructuredCapability(user, 'projects.')).toBe(false);
			expect(hasStructuredCapability(user, '.view')).toBe(false);
		});

		it('returns false for null or undefined user', () => {
			expect(hasStructuredCapability(null, 'projects.view')).toBe(false);
			expect(hasStructuredCapability(undefined, 'projects.view')).toBe(false);
		});
	});

	describe('permissionToCapability', () => {
		it('maps legacy view_projects to projects.view', () => {
			expect(permissionToCapability('view_projects')).toBe('projects.view');
		});

		it('maps legacy record-decisions (hyphenated) to recommendations.approve', () => {
			// Direction B (2026-07-06) retired the single `meetings.record_decisions`
			// capability in favor of nine canonical `recommendations.*` capabilities.
			// The bridge still recognizes the legacy hyphenated string but resolves
			// it to the action-item approve gate so role seeds don't break for one
			// release. Users whose roles need any other `recommendations.*` action
			// must have that key granted explicitly in their scoped-role definition.
			expect(permissionToCapability('record-decisions')).toBe(
				'recommendations.approve',
			);
		});

		it('returns an already-dotted capability unchanged (e.g. ovr.create)', () => {
			expect(permissionToCapability('ovr.create')).toBe('ovr.create');
		});

		it('returns null for unknown transition-only legacy strings', () => {
			expect(permissionToCapability('manage_organization')).toBeNull();
			expect(permissionToCapability('view_own_projects')).toBeNull();
		});

		it('returns null for a made-up permission that is not in the map', () => {
			expect(permissionToCapability('not_a_real_permission')).toBeNull();
		});
	});

	describe('hasPermissionCompat', () => {
		it('grants legacy view_projects through projects.view from user.access', () => {
			const user = makeUser({ access: { projects: { view: true } } });
			expect(hasPermissionCompat(user, 'view_projects')).toBe(true);
		});

		it('grants record-decisions through recommendations.approve from user.access', () => {
			// Direction B canonical contract: a user must have the explicit
			// `recommendations.*` key in user.access to satisfy any matching
			// `record-decisions` permission check. The legacy `meetings.record_decisions`
			// user.access key is no longer auto-pivoted by the bridge; if a role's
			// backend seed still emits it, the auth controller should populate the
			// corresponding `recommendations.*` keys in `AuthController::projectAccessMap`.
			const user = makeUser({
				access: { recommendations: { approve: true } },
			});
			expect(hasPermissionCompat(user, 'record-decisions')).toBe(true);
		});

		it('grants ovr.create as an already-dotted canonical capability', () => {
			const user = makeUser({ access: { ovr: { create: true } } });
			expect(hasPermissionCompat(user, 'ovr.create')).toBe(true);
		});

		it('does not grant transition-only manage_organization from unrelated access', () => {
			const user = makeUser({
				access: { projects: { view: true } },
				permissions: [],
			});
			expect(hasPermissionCompat(user, 'manage_organization')).toBe(false);
		});

		it('no longer grants transition-only manage_organization from legacy permissions[] (Phase 9.3 cutover)', () => {
			// Phase 9.3: `user.permissions[]` was removed from the `/api/auth/me`
			// payload. Transition-only strings without a canonical capability
			// resolve to `false` until their owners introduce canonical equivalents
			// (see docs/authz/deprecation-policy.md).
			const user = makeUser({
				access: { projects: { view: true } },
				permissions: ['manage_organization'],
			});
			expect(hasPermissionCompat(user, 'manage_organization')).toBe(false);
		});

		it('ignores user.permissions[] entirely (Phase 9.3 cutover invariant)', () => {
			// The strongest possible pin: NO legacy flat Spatie string in
			// user.permissions grants access unless it ALSO has a canonical
			// capability that the bridge can resolve to user.access.
			// This guards against a future regression that re-introduces the
			// fallback path (e.g. "for compatibility") after the cutover.
			const legacyOnly = makeUser({
				permissions: [
					'view_projects',
					'edit_projects',
					'delete_projects',
					'create_tasks',
					'manage_organization',
					'view_own_risks',
				],
				access: undefined,
			});
			expect(hasPermissionCompat(legacyOnly, 'view_projects')).toBe(false);
			expect(hasPermissionCompat(legacyOnly, 'edit_projects')).toBe(false);
			expect(hasPermissionCompat(legacyOnly, 'manage_organization')).toBe(false);

			// Same check via the public AccessConfig (canAccessCompat).
			const config: AccessConfig = { permission: 'view_projects' };
			expect(canAccessCompat(legacyOnly, config)).toBe(false);
		});

		it('grants legacy create_strategy through strategy.create from user.access', () => {
			const user = makeUser({
				access: { strategy: { create: true } },
				permissions: [],
			});
			expect(hasPermissionCompat(user, 'create_strategy')).toBe(true);
		});

		it('super_admin bypasses every permission check regardless of access', () => {
			const user = makeUser({
				roles: ['super_admin'],
				permissions: [],
				access: undefined,
			});
			expect(hasPermissionCompat(user, 'manage_organization')).toBe(true);
			expect(hasPermissionCompat(user, 'projects.view')).toBe(true);
		});

		it('returns false for an unknown permission with empty payload', () => {
			const user = makeUser();
			expect(hasPermissionCompat(user, 'not_a_real_permission')).toBe(false);
		});
	});

	describe('canAccessCompat', () => {
		const superAdmin = makeUser({ roles: ['super_admin'], permissions: [] });

		it('super_admin bypasses every config', () => {
			const emptyConfig: AccessConfig = {};
			expect(canAccessCompat(superAdmin, emptyConfig)).toBe(true);

			const deniedConfig: AccessConfig = {
				permission: 'manage_organization',
			};
			expect(canAccessCompat(superAdmin, deniedConfig)).toBe(true);
		});

		it('allPermissions is an AND gate', () => {
			const user = makeUser({
				access: {
					projects: { view: true },
					tasks: { create: true },
				},
			});
			const ok: AccessConfig = {
				allPermissions: ['view_projects', 'create_tasks'],
			};
			expect(canAccessCompat(user, ok)).toBe(true);

			const partial: AccessConfig = {
				allPermissions: ['view_projects', 'delete_projects'],
			};
			expect(canAccessCompat(user, partial)).toBe(false);
		});

		it('permissions is an OR gate', () => {
			const user = makeUser({ access: { projects: { view: true } } });
			const config: AccessConfig = {
				permissions: ['view_projects', 'delete_projects'],
			};
			expect(canAccessCompat(user, config)).toBe(true);
		});

		it('roles are honored from user.roles', () => {
			const user = makeUser({
				roles: ['admin'],
				permissions: [],
			});
			const config: AccessConfig = { roles: ['admin'] };
			expect(canAccessCompat(user, config)).toBe(true);
		});

		it('missing user denies regardless of config', () => {
			const config: AccessConfig = { permission: 'projects.view' };
			expect(canAccessCompat(null, config)).toBe(false);
			expect(canAccessCompat(undefined, config)).toBe(false);
		});

		it('allPermissions alone is an AND gate that only passes when every entry is granted', () => {
			const user = makeUser({
				access: { projects: { view: true } },
				permissions: [],
			});
			expect(
				canAccessCompat(user, { allPermissions: ['view_projects'] }),
			).toBe(true);
			expect(
				canAccessCompat(user, {
					allPermissions: ['view_projects', 'manage_organization'],
				}),
			).toBe(false);
		});
	});
});
