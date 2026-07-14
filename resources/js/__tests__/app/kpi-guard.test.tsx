import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { cwd } from 'node:process';

/**
 * Task 13 (OrgAdmin plan): SPA — operational route guards unification.
 *
 * The `/performance/kpis*` route guards must use the canonical `kpis.*`
 * capabilities exported by `App\Modules\Core\Authorization\Capability`
 * (`KPIS_VIEW`, `KPIS_MANAGE`, `KPIS_EDIT`) instead of the legacy
 * `strategy.*` capabilities. Likewise, `/risk-management/statistics`
 * must use `RISKS_VIEW_REPORTS` (`risks.view_reports`) — the existing
 * `risks.view` guard does not match what the backend reports route
 * actually enforces.
 *
 * These tests deliberately read `resources/js/app.tsx` as text so they
 * fail loudly on the mis-wired guard strings BEFORE the production code
 * is corrected (RED). After the corrections, every block must reference
 * the canonical capability and must not keep the legacy string.
 */

const APP_TSX_SOURCE = readFileSync(
  resolve(cwd(), 'resources/js/app.tsx'),
  'utf8',
);

/**
 * Extract the self-closing route declaration rooted at `path="…"` (the first
 * <Route> with that exact path attribute). The permission requirement appears
 * before the first nested self-closing component in its `element` prop.
 */
function extractRouteBlock(source: string, pathValue: string): string | null {
  const openingTag = new RegExp(
    `<Route\\s+path="${pathValue.replace(/[/]/g, '\\/')}"`,
    'm',
  );
  const match = openingTag.exec(source);
  if (!match) return null;

  const end = source.indexOf('/>', match.index);
  if (end === -1) return null;

  return source.slice(match.index, end + 2);
}

describe('KPI route guards (Task 13)', () => {
  it('/performance/kpis list route references kpis.view or kpis.manage', () => {
    const block = extractRouteBlock(APP_TSX_SOURCE, '/performance/kpis');
    expect(block, 'Route block must exist').not.toBeNull();
    expect(block!).toMatch(/kpis\.(view|manage)/);
    expect(block!).not.toMatch(/strategy\.(view|create|edit|manage)/);
  });

  it('/performance/kpis/new route requires kpis.manage', () => {
    const block = extractRouteBlock(APP_TSX_SOURCE, '/performance/kpis/new');
    expect(block, 'Route block must exist').not.toBeNull();
    expect(block!).toMatch(/kpis\.manage/);
    expect(block!).not.toMatch(/strategy\.create/);
  });

  it('/performance/kpis/:id/edit route requires kpis.edit', () => {
    const block = extractRouteBlock(APP_TSX_SOURCE, '/performance/kpis/:id/edit');
    expect(block, 'Route block must exist').not.toBeNull();
    expect(block!).toMatch(/kpis\.edit/);
    expect(block!).not.toMatch(/strategy\.edit/);
  });

  it('/performance/kpis/:id detail route references kpis.view or kpis.manage', () => {
    const block = extractRouteBlock(APP_TSX_SOURCE, '/performance/kpis/:id');
    expect(block, 'Route block must exist').not.toBeNull();
    expect(block!).toMatch(/kpis\.(view|manage)/);
    expect(block!).not.toMatch(/strategy\.view/);
  });
});

describe('Risk Statistics guard (Task 13)', () => {
  it('/risk-management/statistics route uses risks.view_reports', () => {
    const block = extractRouteBlock(APP_TSX_SOURCE, '/risk-management/statistics');
    expect(block, 'Route block must exist').not.toBeNull();
    expect(block!).toMatch(/risks\.view_reports/);
    // The legacy `risks.view` capability is not the right gate for the
    // statistics view; it must not be referenced as the standalone
    // capability in this block.
    expect(block!).not.toMatch(/"risks\.view"/);
  });
});
