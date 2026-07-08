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

		it('maps legacy view_survey_responses to surveys.review_responses (Phase 8-E parity)', () => {
			// Phase 8-E frontend fix: the backend CapabilityAlias::map()
			// (Phase 8-D correction) resolves `view_survey_responses` to
			// `surveys.review_responses` because the Spatie permission gates
			// RESPONSE data, not survey metadata. Before this fix the
			// frontend bridge returned `surveys.view`, which silently denied
			// access for users who held only `surveys.review_responses` in
			// `user.access`.
			expect(permissionToCapability('view_survey_responses')).toBe(
				'surveys.review_responses',
			);
			// Defensive: the wrong target must not be returned.
			expect(permissionToCapability('view_survey_responses')).not.toBe(
				'surveys.view',
			);
		});

		it('maps legacy review_survey_responses to surveys.review_responses', () => {
			expect(permissionToCapability('review_survey_responses')).toBe(
				'surveys.review_responses',
			);
		});

		it('maps legacy review_data_imports to surveys.review_data_imports (Phase 8-E parity)', () => {
			// Phase 8-E: align with backend CapabilityAlias::map() —
			// `review_data_imports` was demoted to TRANSITION_ONLY_PERMISSIONS
			// before this fix, which made permissionToCapability() return null
			// and silently denied route guards like
			// `permission: 'review_data_imports'` even when the user held
			// `surveys.review_data_imports` in `user.access`.
			expect(permissionToCapability('review_data_imports')).toBe(
				'surveys.review_data_imports',
			);
		});

		it('maps legacy view_dashboard to dashboard.view (Phase 8-E parity)', () => {
			// Phase 8-E: align with backend CapabilityAlias::map() —
			// `view_dashboard` was demoted to TRANSITION_ONLY_PERMISSIONS
			// before this fix. The backend has resolved it to
			// `dashboard.view` since Phase 8-C; the bridge now mirrors that.
			expect(permissionToCapability('view_dashboard')).toBe('dashboard.view');
		});

		it('maps legacy record-decisions (hyphenated) to meeting_resolutions.create (Direction R)', () => {
			// Direction R (2026-07-07) repointed the legacy `record-decisions` and
			// `meetings.record_decisions` strings at the new
			// `meeting_resolutions.create` capability. The Direction B
			// `recommendations.approve` target is gone: there is no approve /
			// reject / adopt / deliberate lifecycle on the new MeetingResolutions
			// model, so the most permissive "you can record a meeting output"
			// gate is `meeting_resolutions.create`. Per-action gating in the UI
			// must go through `useCan('meeting_resolutions.<action>')` on
			// ResolutionCard / ResolutionsSection.
			expect(permissionToCapability('record-decisions')).toBe(
				'meeting_resolutions.create',
			);
		});

		it('returns an already-dotted capability unchanged (e.g. ovr.create)', () => {
			expect(permissionToCapability('ovr.create')).toBe('ovr.create');
		});

		it('returns null for unknown transition-only legacy strings', () => {
			expect(permissionToCapability('manage_organization')).toBeNull();
			expect(permissionToCapability('view_own_projects')).toBeNull();
			expect(permissionToCapability('view_reports')).toBeNull();
			expect(permissionToCapability('export_reports')).toBeNull();
		});

		it('returns null for a made-up permission that is not in the map', () => {
			expect(permissionToCapability('not_a_real_permission')).toBeNull();
		});
	});

	describe('Phase 8-E parity: hasPermissionCompat against backend CapabilityAlias', () => {
		// Pins the corrected mapping end-to-end: a user whose `user.access`
		// carries only the canonical backend capability must satisfy a legacy
		// route guard that still references the legacy flat string. Before
		// Phase 8-E these checks returned `false` because the bridge refused
		// to map the legacy string to its canonical equivalent.

		it('grants legacy view_survey_responses through surveys.review_responses', () => {
			const user = makeUser({
				access: { surveys: { review_responses: true } },
				permissions: [],
			});
			expect(hasPermissionCompat(user, 'view_survey_responses')).toBe(true);
			// users with only surveys.view (metadata) must NOT pass the
			// view_survey_responses guard — that was the Phase 8-D bug.
			const metadataOnly = makeUser({
				access: { surveys: { view: true } },
				permissions: [],
			});
			expect(hasPermissionCompat(metadataOnly, 'view_survey_responses')).toBe(
				false,
			);
		});

		it('grants legacy review_data_imports through surveys.review_data_imports', () => {
			const user = makeUser({
				access: { surveys: { review_data_imports: true } },
				permissions: [],
			});
			expect(hasPermissionCompat(user, 'review_data_imports')).toBe(true);
		});

		it('grants legacy view_dashboard through dashboard.view', () => {
			const user = makeUser({
				access: { dashboard: { view: true } },
				permissions: [],
			});
			expect(hasPermissionCompat(user, 'view_dashboard')).toBe(true);
		});
	});

	describe('hasPermissionCompat', () => {
		it('grants legacy view_projects through projects.view from user.access', () => {
			const user = makeUser({ access: { projects: { view: true } } });
			expect(hasPermissionCompat(user, 'view_projects')).toBe(true);
		});

		it('grants record-decisions through meeting_resolutions.create from user.access', () => {
			// Direction R canonical contract: a user must hold the
			// `meeting_resolutions.create` key in user.access to satisfy a
			// `record-decisions` route guard. The legacy
			// `recommendations.approve` key is no longer the bridge target —
			// Direction R removed the approve / reject / adopt / deliberate
			// lifecycle and folded everything under the new typed outputs
			// model. Stale sessions that still hold `recommendations.approve`
			// in their access map will not pass a `record-decisions` guard
			// until the role seed is re-cut to the new key.
			const user = makeUser({
				access: { meeting_resolutions: { create: true } },
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
