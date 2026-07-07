import React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { buildNasaqGroups } from '@shared/nasaq/app';
import { canAccessCompat } from '@shared/api/access-bridge';
import type { AccessConfig } from '@shared/contexts/AuthContext';
import type { User } from '@shared/types';

// Phase F2.2 — prove that route/nav configs that still use legacy flat
// permission names resolve correctly via the access bridge when the user
// has only `user.access` and no `user.permissions[]`.

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

const t = (key: string, fallback?: string) => fallback || key;

describe('nasaq nav access-only payload', () => {
	it('buildNasaqGroups exposes project/task/meeting/OVR items via access map alone', () => {
		const user = makeUser({
			access: {
				projects: { view: true },
				tasks: { view: true },
				meetings: { view: true },
				ovr: { view_all: true, create: true },
			},
		});

		const canAccess = (config: NavAccessShim) => canAccessCompat(user, config);
		const groups = buildNasaqGroups(t, canAccess, false);

		type NavGroup = { id: string; items: Array<{ key: string; children?: Array<{ key: string }> }> };
		const findItem = (key: string) => {
			for (const g of groups as NavGroup[]) {
				const direct = g.items.find((it) => it.key === key);
				if (direct) return direct;
				const child = g.items
					.flatMap((it) => it.children ?? [])
					.find((c) => c.key === key);
				if (child) return child;
			}
			return null;
		};

		expect(findItem('projects')).not.toBeNull();
		expect(findItem('tasks')).not.toBeNull();
		expect(findItem('meetings')).not.toBeNull();
		expect(findItem('ovr-new')).not.toBeNull();
	});

	it('buildNasaqGroups hides OVR create when access map lacks ovr.create', () => {
		const user = makeUser({
			access: {
				ovr: { view_all: true },
			},
		});
		const canAccess = (config: NavAccessShim) => canAccessCompat(user, config);
		const groups = buildNasaqGroups(t, canAccess, false);

		const ovrNew = groups
			.flatMap((g) => g.items)
			.flatMap((it) => it.children ?? [])
			.find((c) => c.key === 'ovr-new');
		expect(ovrNew).toBeUndefined();
	});

	it('buildNasaqGroups still hides projects when neither access nor legacy grants view', () => {
		const user = makeUser({
			access: { tasks: { view: true } },
		});
		const canAccess = (config: NavAccessShim) => canAccessCompat(user, config);
		const groups = buildNasaqGroups(t, canAccess, false);

		const projects = groups
			.flatMap((g) => g.items)
			.find((it) => it.key === 'projects');
		expect(projects).toBeUndefined();
	});

	it('buildNasaqGroups ignores transition-only legacy permissions[] after the Phase 9.3 cutover', () => {
		const user = makeUser({
			permissions: ['view_own_projects', 'view_own_tasks'],
			access: {},
		});
		const canAccess = (config: NavAccessShim) => canAccessCompat(user, config);
		const groups = buildNasaqGroups(t, canAccess, false);

		const projects = groups
			.flatMap((g) => g.items)
			.find((it) => it.key === 'projects');
		expect(projects).toBeUndefined();
	});

	it('keeps the operational Nasaq sidebar free of admin routes even for super_admin', () => {
		const user = makeUser({ roles: ['super_admin'] });
		const canAccess = (config: NavAccessShim) => canAccessCompat(user, config);
		const groups = buildNasaqGroups(t, canAccess, true);

		const ids = groups.map((g) => g.id);
		expect(ids).not.toContain('admin');
		expect(ids).toContain('planning');
		expect(ids).toContain('quality');

		type FlatNavItem = {
			key: string;
			path: string;
			children?: FlatNavItem[];
		};
		const flatten = (items: FlatNavItem[]): FlatNavItem[] =>
			items.flatMap((item) => [item, ...flatten(item.children ?? [])]);
		const adminPaths = groups
			.flatMap((group) => flatten(group.items as FlatNavItem[]))
			.map((item) => item.path)
			.filter((path) => path.startsWith('/admin'));

		expect(adminPaths).toEqual([]);
	});
});

// Sidebar smoke render — make sure the access-bridged canAccess lets the
// panel render without crashing. A heavy assertion here would re-implement
// Sidebar, so we only assert the brand chrome appears and that the test
// harness exits cleanly.
describe('Sidebar access-only payload (smoke)', () => {
	beforeEach(() => {
		vi.clearAllMocks();
	});

	vi.mock('@shared/contexts/AuthContext', () => ({
		useAuth: () => ({
			user: {
				id: 1,
				name: 'Test',
				roles: [],
				permissions: [],
				access: {
					projects: { view: true },
					tasks: { view: true },
				},
			},
			isAdmin: () => false,
			canAccess: (cfg: any) => canAccessCompat(makeUser({ access: { projects: { view: true }, tasks: { view: true } } }), cfg),
		}),
	}));
	vi.mock('@shared/contexts/SystemSettingsContext', () => ({
		useSystemSettings: () => ({ settings: { name: 'Erada', code: 'ER' } }),
	}));
	vi.mock('react-i18next', () => ({
		useTranslation: () => ({ t: (k: string, f?: string) => f || k }),
	}));

	it('renders the brand chrome without crashing on access-only payload', async () => {
		const Sidebar = (await import('@widgets/app-shell/ui/Sidebar')).default;
		const { getAllByTestId } = render(
			<MemoryRouter initialEntries={['/projects']}>
				<Sidebar isOpen onToggle={() => {}} />
			</MemoryRouter>,
		);
		expect(getAllByTestId('brand-mark').length).toBeGreaterThan(0);
	});
});

type NavAccessShim = AccessConfig;
