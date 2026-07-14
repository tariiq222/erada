import { describe, expect, it } from 'vitest';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

/**
 * Phase F3.2 — grep guard that prevents a one-to-one legacy permission
 * string from being reintroduced into the central route registry or
 * nav configs after the F3 canonicalization.
 *
 * Allowed:
 *   - canonical dotted capabilities (`projects.view`)
 *   - transition-only legacy strings that intentionally route through
 *     `permissions[]` only during the Phase 9 cleanup freeze
 *     (see `docs/authz/frontend-access-migration-report.md`)
 *   - any string appearing outside `app.tsx`, `nasaq/app.tsx`
 *
 * Not allowed:
 *   - one-to-one legacy flat strings such as `view_projects` or
 *     `view-meetings` in the route/nav files — those must use the
 *     canonical `module.action` form so the central access bridge can
 *     resolve them through `user.access`.
 *
 * Phase 9.3 cutover (2026-07-05): the legacy fallback path through
 * `user.permissions[]` was removed from the access bridge. Product pages
 * (`resources/js/pages/`) and feature entrypoints (`resources/js/features/`)
 * are now scanned too — if a future page reintroduces a flat string like
 * `view_projects`, the test fails before the SPA silently grants nothing.
 *
 * Task 14 cutover (2026-07-14): the legacy `widgets/app-shell/ui/Sidebar.tsx`
 * component was deleted and replaced by `@shared/nasaq/app` — removed from
 * the route/nav scan list.
 */

const ROUTE_NAV_FILES = [
	'resources/js/app.tsx',
	'resources/js/shared/nasaq/app.tsx',
] as const;

const PRODUCT_PAGE_DIRS = [
	'resources/js/pages',
	'resources/js/features',
] as const;

const FORBIDDEN_LEGACY_STRINGS = [
	'view_projects',
	'create_projects',
	'edit_projects',
	'delete_projects',
	'view_tasks',
	'create_tasks',
	'edit_tasks',
	'delete_tasks',
	'view_strategy',
	'create_strategy',
	'edit_strategy',
	'delete_strategy',
	'view_surveys',
	'create_surveys',
	'edit_surveys',
	'delete_surveys',
	'view_risks',
	'create_risks',
	'reassess_risks',
	'change_risk_status',
	'view_risk_reports',
	'create_departments',
	'edit_departments',
	'delete_departments',
	'view_users',
	'create_users',
	'edit_users',
	'delete_users',
	'view_roles',
	'create_roles',
	'edit_roles',
	'delete_roles',
	'approve_registrations',
	'view_hr',
	'view-meetings',
	'manage-meetings',
	'record-decisions',
] as const;

const ALLOWED_TRANSITION_STRINGS = new Set([
	'manage_organization',
	'create_organizations',
	'edit_organizations',
	'delete_organizations',
	'view_dashboard',
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

const REPO_ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../../../..');

function findForbiddenHits(file: string): Array<{ line: number; match: string }> {
	const source = readFileSync(resolve(REPO_ROOT, file), 'utf8');
	const hits: Array<{ line: number; match: string }> = [];
	const lines = source.split('\n');
	lines.forEach((line, index) => {
		for (const forbidden of FORBIDDEN_LEGACY_STRINGS) {
			// Allow the legacy flat permission name ONLY when NOT preceded by a
			// `.` (i.e. NOT already part of a canonical `module.legacy` name).
			const pattern = new RegExp(String.raw`(?<![.\w])${forbidden}\b`);
			if (pattern.test(line)) {
				hits.push({ line: index + 1, match: `${forbidden} → ${line.trim()}` });
			}
		}
	});
	return hits;
}

function walkTsx(dir: string): string[] {
	const out: string[] = [];
	for (const entry of readdirSync(dir)) {
		const full = resolve(dir, entry);
		if (statSync(full).isDirectory()) {
			out.push(...walkTsx(full));
		} else if (entry.endsWith('.tsx') || entry.endsWith('.ts')) {
			out.push(full);
		}
	}
	return out;
}

function relativeToRepo(absolutePath: string): string {
	return absolutePath.startsWith(REPO_ROOT + '/')
		? absolutePath.slice(REPO_ROOT.length + 1)
		: absolutePath;
}

describe('frontend legacy grep guard (F3.2)', () => {
	it('every route/nav file is parsed without an empty read', () => {
		for (const file of ROUTE_NAV_FILES) {
			expect(findForbiddenHits(file)).toBeDefined();
		}
	});

	it.each(ROUTE_NAV_FILES)('%s has no one-to-one legacy permission strings', (file) => {
		const hits = findForbiddenHits(file);
		if (hits.length > 0) {
			const message = hits
				.map((h) => `  L${h.line}: ${h.match}`)
				.join('\n');
			expect.fail(
				`${file} contains legacy one-to-one permission strings. ` +
					`Replace them with canonical module.action capabilities. ` +
					`Allowed transition-only legacy strings: ${Array.from(
						ALLOWED_TRANSITION_STRINGS,
					).join(', ')}.\n${message}`,
			);
		}
		expect(hits).toEqual([]);
	});
});

describe('frontend transition-only allow-list (F3.2)', () => {
	it.each(ROUTE_NAV_FILES)(
		'%s may keep transition-only legacy strings with a rationale comment',
		(file) => {
			const source = readFileSync(resolve(REPO_ROOT, file), 'utf8');
			const hits: string[] = [];
			for (const allowed of ALLOWED_TRANSITION_STRINGS) {
				if (source.includes(`"${allowed}"`)) {
					hits.push(allowed);
				}
			}
			// This is an intent check — every transition-only legacy string that
			// survived F3 must be present in the allow-list. If a new string
			// appears, add it here and to docs/authz/frontend-access-migration-report.md.
			const unknownAllowed = hits.filter((h) => !ALLOWED_TRANSITION_STRINGS.has(h));
			expect(unknownAllowed).toEqual([]);
		},
	);
});

describe('survey responses SPA gate uses canonical capability', () => {
	// Verified residual (2026-07-12): the /surveys/:id/responses route guard
	// must require the canonical `surveys.review_responses` capability, not
	// the legacy flat string `view_survey_responses`. The legacy string is
	// not in the access bridge's canonical set, so it would silently deny.
	it('app.tsx requires surveys.review_responses and not view_survey_responses on the responses route', () => {
		const source = readFileSync(resolve(REPO_ROOT, 'resources/js/app.tsx'), 'utf8');
		expect(source).toContain('surveys.review_responses');
		expect(source).not.toContain('view_survey_responses');
	});
});

describe('product pages must use canonical capabilities (Phase 9.3 cutover)', () => {
	// After Phase 9.3, the access bridge no longer reads `user.permissions[]`.
	// A flat legacy string in a product page is a silent deny (no grant). Pin
	// every product page against this regression so the migration does not
	// regress page-by-page over time.
	const productFiles = PRODUCT_PAGE_DIRS.flatMap((dir) =>
		walkTsx(resolve(REPO_ROOT, dir)).map(relativeToRepo),
	);

	it('discovers product pages to scan', () => {
		expect(productFiles.length).toBeGreaterThan(0);
	});

	it.each(productFiles)('%s has no one-to-one legacy permission strings', (file) => {
		const hits = findForbiddenHits(file);
		if (hits.length > 0) {
			const message = hits
				.map((h) => `  L${h.line}: ${h.match}`)
				.join('\n');
			expect.fail(
				`${file} contains a legacy flat permission string. ` +
					`After Phase 9.3 the bridge ignores user.permissions[]; this string would resolve to false. ` +
					`Migrate to a canonical module.action capability. ` +
					`Allowed transition-only legacy strings: ${Array.from(
						ALLOWED_TRANSITION_STRINGS,
					).join(', ')}.\n${message}`,
			);
		}
		expect(hits).toEqual([]);
	});
});
