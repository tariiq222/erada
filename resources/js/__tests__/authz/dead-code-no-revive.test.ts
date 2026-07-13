/**
 * Task 14 — SPA dead-code anti-revival guard.
 *
 * The following modules were confirmed dead code in
 * `docs/superpowers/plans/2026-07-13-orgadmin-and-shipped-admin-spa.md`
 * (Task 14) and removed:
 *
 *   - `resources/js/shared/lib/errorHandler.ts`
 *       Old Axios-style error parser. The canonical `ApiClient` already
 *       throws typed `ApiError`s; nothing else imported these helpers.
 *   - `resources/js/widgets/app-shell/ui/Sidebar.tsx`
 *       Legacy static sidebar. Replaced by `@shared/nasaq/app`'s Sidebar.
 *   - `OrganizationProvider` / `useOrganization` alias exports from
 *       `resources/js/shared/contexts/SystemSettingsContext.tsx`
 *       The canonical provider/hook live in
 *       `resources/js/shared/contexts/OrganizationContext.tsx`; every
 *       consumer imports from there.
 *
 * This test enforces the deletions so the dead surface cannot quietly
 * come back. Source-of-truth searches are restricted to `resources/js/**`
 * (this test file is excluded so its own pattern definitions don't
 * trigger the assertions).
 */

import { describe, expect, it } from 'vitest';
import { existsSync, readFileSync, statSync } from 'node:fs';
import { readdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const REPO_ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../../../..');
const RESOURCES_JS = resolve(REPO_ROOT, 'resources/js');
const SELF_PATH = resolve(REPO_ROOT, 'resources/js/__tests__/authz/dead-code-no-revive.test.ts');

const DELETED_FILES = [
	'resources/js/shared/lib/errorHandler.ts',
	'resources/js/widgets/app-shell/ui/Sidebar.tsx',
] as const;

// Match the deleted modules when they appear as a module specifier in
// an `import ... from '...'` statement or a `require('...')` call.
// Docstrings that mention the path in comments must not trigger the
// assertion, so we anchor on the `from`/`require` shape.
const DEAD_IMPORT_PATTERNS: RegExp[] = [
	/from\s*['"][^'"]*shared\/lib\/errorHandler[^'"]*['"]/,
	/require\s*\(\s*['"][^'"]*shared\/lib\/errorHandler[^'"]*['"]\s*\)/,
	/from\s*['"][^'"]*widgets\/app-shell\/ui\/Sidebar[^'"]*['"]/,
	/require\s*\(\s*['"][^'"]*widgets\/app-shell\/ui\/Sidebar[^'"]*['"]\s*\)/,
];

// A single import statement that pulls OrganizationProvider or
// useOrganization from SystemSettingsContext. Both the binder and the
// source must appear in the same statement; the `from` clause is what
// tells the reader where the symbol comes from. Real consumers always
// import from `@shared/contexts/OrganizationContext`.
const DEAD_ALIAS_IMPORT_STATEMENT = /import\s*\{[^}]*\b(OrganizationProvider|useOrganization)\b[^}]*\}\s*from\s*['"][^'"]*SystemSettingsContext[^'"]*['"]/;

function walkTs(dir: string): string[] {
	const out: string[] = [];
	for (const entry of readdirSync(dir)) {
		const full = resolve(dir, entry);
		if (statSync(full).isDirectory()) {
			out.push(...walkTs(full));
		} else if (entry.endsWith('.ts') || entry.endsWith('.tsx')) {
			out.push(full);
		}
	}
	return out;
}

describe('Task 14 dead-code anti-revival', () => {
	it.each(DELETED_FILES)('%s is deleted from the tree', (relativePath) => {
		const absolutePath = resolve(REPO_ROOT, relativePath);
		expect(existsSync(absolutePath)).toBe(false);
	});

	it('no source file under resources/js imports the deleted errorHandler or legacy Sidebar module', () => {
		const files = walkTs(RESOURCES_JS).filter((f) => f !== SELF_PATH);
		const offenders: string[] = [];
		for (const file of files) {
			const source = readFileSync(file, 'utf8');
			for (const pattern of DEAD_IMPORT_PATTERNS) {
				if (pattern.test(source)) {
					offenders.push(file);
					break;
				}
			}
		}
		expect(offenders).toEqual([]);
	});

	it('SystemSettingsContext no longer re-exports OrganizationProvider/useOrganization aliases', () => {
		const source = readFileSync(
			resolve(REPO_ROOT, 'resources/js/shared/contexts/SystemSettingsContext.tsx'),
			'utf8',
		);
		expect(source).not.toMatch(/export\s+const\s+OrganizationProvider\b/);
		expect(source).not.toMatch(/export\s+const\s+useOrganization\b/);
	});

	it('no source file imports OrganizationProvider or useOrganization from SystemSettingsContext', () => {
		const files = walkTs(RESOURCES_JS).filter((f) => f !== SELF_PATH);
		const offenders: string[] = [];
		for (const file of files) {
			const source = readFileSync(file, 'utf8');
			if (DEAD_ALIAS_IMPORT_STATEMENT.test(source)) {
				offenders.push(file);
			}
		}
		expect(offenders).toEqual([]);
	});

	it('app-shell barrel no longer exports Sidebar', () => {
		const source = readFileSync(
			resolve(REPO_ROOT, 'resources/js/widgets/app-shell/index.ts'),
			'utf8',
		);
		expect(source).not.toMatch(/Sidebar/);
	});
});