/**
 * Phase Task-13 — Operational guards alignment.
 *
 * Pins three small but high-blast-radius contracts that the SPA relies on:
 *
 *  1. KPI routes in `resources/js/app.tsx` are gated on the canonical
 *     `kpis.view` / `kpis.create` / `kpis.edit` capabilities (NOT the
 *     legacy `strategy.*` capabilities which the Capability class
 *     does not list under Performance — see
 *     `App\Modules\Core\Authorization\Capability::KPIS_*`).
 *  2. The risk-statistics route uses `risks.view_reports` (the canonical
 *     reports capability in the Capability class), not the broader
 *     `risks.view` list-read capability.
 *  3. The authenticated OVR incident-detail route resolves by
 *     `report_number` (matching `IncidentReport::getRouteKeyName()`),
 *     not by the public `tracking_token`. The public track URL
 *     `/ovr/track/:tracking_token` MUST keep its `:tracking_token`
 *     param because that endpoint is token-keyed, not enum-keyed.
 *
 * Phase Task-13 follow-up — KPI sidebar parity:
 *   The route guards above cover the route registry, but the NASAQ
 *   sidebar tree (`resources/js/shared/nasaq/app.tsx`) is the second
 *   user-visible entry point. The sidebar items for the KPI module
 *   MUST mirror the canonical `kpis.*` capability family so the
 *   sidebar doesn't silently hide KPI access from users who only hold
 *   the canonical KPI grant — `strategy.*` does not authorize KPI
 *   routes (the Capability class only lists KPI capabilities under
 *   `Capability::KPIS_*`, and the AccessDecision engine resolves them
 *   via User::canonicalCapabilityNames()), so a `strategy.*` gate
 *   would be wrong on both the route AND the sidebar.
 *
 * These are static-source pins (no DOM rendering) — they live alongside
 * `frontend-legacy-grep.test.ts` because the canonical route registry is
 * a string-only contract and the cheapest reliable test is to read the
 * registry and assert the substring it must (and must not) carry.
 */

import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const REPO_ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../../../..');

function readAppSource(): string {
  return readFileSync(resolve(REPO_ROOT, 'resources/js/app.tsx'), 'utf8');
}

function readNasaqSource(): string {
  return readFileSync(
    resolve(REPO_ROOT, 'resources/js/shared/nasaq/app.tsx'),
    'utf8',
  );
}

function readIncidentViewSource(): string {
  return readFileSync(
    resolve(REPO_ROOT, 'resources/js/pages/ovr/IncidentView.tsx'),
    'utf8',
  );
}

/**
 * Find the `<RequirePermission ...>` element block that wraps a given
 * route `path=` declaration in app.tsx. Returns the slice of source
 * between (and including) the opening and closing `<RequirePermission>`
 * tags for the FIRST RequirePermission that follows the route's `path`
 * attribute, or null when the route is not found.
 *
 * The registry is structured as repeated JSX blocks; the function keys on
 * the `<Route path="/..." element={<RequirePermission ...>` opener and
 * returns the source up to the matching `</RequirePermission>`. This is
 * good enough for a string-pin test — it avoids false positives from
 * unrelated uses of the same capability elsewhere in the file.
 *
 * Note: we search FORWARD from `path=` (not backward) because in JSX the
 * `<RequirePermission>` element follows the path attribute inside the
 * same Route element; a backward search would pick up the previous
 * Route's RequirePermission and incorrectly span multiple guards.
 */
function sliceForRoute(source: string, routePath: string): string | null {
  const idx = source.indexOf(`path="${routePath}"`);
  if (idx === -1) {
    return null;
  }
  // Walk forwards to the first <RequirePermission opening tag that
  // appears AFTER the path attribute, then to its matching close. This
  // keeps the slice scoped to the guard that actually wraps the route.
  const open = source.indexOf('<RequirePermission', idx);
  const close = source.indexOf('</RequirePermission>', open === -1 ? idx : open);
  if (open === -1 || close === -1) {
    return null;
  }
  return source.slice(open, close + '</RequirePermission>'.length);
}

/**
 * Find the NASAQ_NAV_TREE module entry whose `key` matches the given
 * identifier and return its full object literal — from the opening `{`
 * on the same line as `key: "<key>",` to the matching closing `},`.
 * Returns null when no module with that key exists in the tree.
 *
 * Anchoring detail: a module's `key: "..."` line is preceded (after a
 * comma) by the module's opening `{`, but it can also be followed by
 * inner `{` literals (e.g. `access: { ... }`, `children: [ { ... } ]`).
 * We anchor on the LAST `{` BEFORE the `key:` match — that brace is
 * the module's outer opener. From there a balanced brace scan (string-
 * literal aware) walks to the matching closer, which is the matching
 * outer `},` of this module entry.
 *
 * The NASAQ_NAV_TREE constant co-exists with a smaller legacy
 * `NASAQ_NAV` array in the same file. Both arrays can carry the same
 * `key: "<key>"` literal for the same module (the legacy array only
 * mirrors path/icon, no `access`). We MUST resolve the slice against
 * the NASAQ_NAV_TREE entry specifically — so the helper searches
 * only AFTER the `export const NASAQ_NAV_TREE` declaration. Otherwise
 * the slice would lock onto the legacy NASAQ_NAV row and miss the
 * actual `access:` / `children:` shape we are testing.
 *
 * The slice is intentionally generous (it includes children, comments,
 * and trailing whitespace) because the assertion surface is "does this
 * subtree mention capability X" / "does it mention legacy capability Y"
 * — those checks do not need a tight span, only a reliably bounded one.
 */
function sliceForNasaqModule(source: string, moduleKey: string): string | null {
  const treeMarker = source.indexOf('export const NASAQ_NAV_TREE');
  if (treeMarker === -1) {
    return null;
  }
  const scopedSource = source.slice(treeMarker);
  const keyRe = new RegExp(`\\bkey:\\s*["']${moduleKey}["']`);
  const match = keyRe.exec(scopedSource);
  if (!match) {
    return null;
  }
  // The outer opener `{` is somewhere before `match.index`. Search
  // backward for the most recent `{`; that is the module's opening
  // brace (every nested `{` that follows is inside the module body).
  const start = scopedSource.lastIndexOf('{', match.index - 1);
  if (start === -1) {
    return null;
  }

  let depth = 0;
  for (let i = start; i < scopedSource.length; i += 1) {
    const ch = scopedSource[i];
    if (ch === '{') {
      depth += 1;
    } else if (ch === '}') {
      depth -= 1;
      if (depth === 0) {
        // Include the closing brace so the slice is stable against
        // unrelated siblings.
        return scopedSource.slice(start, i + 1);
      }
    } else if (ch === '"' || ch === "'" || ch === '`') {
      // Skip string literals so a `{` or `}` inside a JS string does
      // not corrupt the brace balance. Track the opening quote and step
      // past the matching close (handling \" / \\ escapes).
      const quote = ch;
      i += 1;
      while (i < scopedSource.length) {
        const c = scopedSource[i];
        if (c === '\\') {
          i += 2;
          continue;
        }
        if (c === quote) {
          break;
        }
        i += 1;
      }
    }
  }
  return null;
}

describe('KPI route guards use canonical kpis.* capabilities', () => {
  const source = readAppSource();

  it('every /performance/kpis route block is present in the route registry', () => {
    for (const path of [
      '/performance/kpis',
      '/performance/kpis/new',
      '/performance/kpis/:id',
      '/performance/kpis/:id/edit',
    ]) {
      expect(sliceForRoute(source, path)).not.toBeNull();
    }
  });

  it.each([
    ['/performance/kpis', 'kpis.view'],
    ['/performance/kpis/new', 'kpis.create'],
    ['/performance/kpis/:id', 'kpis.view'],
    ['/performance/kpis/:id/edit', 'kpis.edit'],
  ] as const)(
    'route %s requires canonical capability %s',
    (path, capability) => {
      const slice = sliceForRoute(source, path);
      expect(slice, `route ${path} not found in app.tsx`).not.toBeNull();
      expect(slice).toContain(`"${capability}"`);
    },
  );

  it.each([
    '/performance/kpis',
    '/performance/kpis/new',
    '/performance/kpis/:id',
    '/performance/kpis/:id/edit',
  ] as const)(
    'route %s does not reference legacy strategy.* capabilities',
    (path) => {
      const slice = sliceForRoute(source, path);
      expect(slice, `route ${path} not found in app.tsx`).not.toBeNull();
      expect(slice).not.toMatch(/["']strategy\./);
    },
  );
});

describe('KPI NASAQ sidebar module uses canonical kpis.* capabilities', () => {
  // The NASAQ sidebar tree in `resources/js/shared/nasaq/app.tsx` is the
  // user-visible navigation surface. Prior to the Task-13 follow-up
  // this module still gated on the legacy `strategy.view` / `strategy.create`
  // capability strings, which meant a user holding only the canonical
  // `kpis.view` grant on actor.org could open `/performance/kpis` directly
  // (route guard let them through) but the sidebar would silently hide the
  // KPI module from them. Both gates must agree.
  const source = readNasaqSource();
  const sidebar = sliceForNasaqModule(source, 'performance_kpis');

  it('the performance_kpis module is present in NASAQ_NAV_TREE', () => {
    expect(sidebar, 'performance_kpis module not found in nasaq/app.tsx').not.toBeNull();
  });

  it('gates the KPI list module on the canonical kpis.view capability', () => {
    expect(sidebar).not.toBeNull();
    expect(sidebar).toContain('"kpis.view"');
  });

  it('gates the KPI create child on the canonical kpis.create capability', () => {
    // The route guard at /performance/kpis/new uses `kpis.create` (see the
    // route-guard describe block above). The sidebar's "create new" entry
    // is a navigation shortcut into that same route, so it must mirror the
    // same capability string — otherwise users who only hold `kpis.create`
    // would see the menu item but get blocked by the route guard.
    expect(sidebar).not.toBeNull();
    expect(sidebar).toContain('"kpis.create"');
  });

  it('does not reference legacy strategy.* capabilities anywhere in the KPI module', () => {
    expect(sidebar).not.toBeNull();
    expect(sidebar).not.toMatch(/["']strategy\./);
  });

  it('does not use a duplicated anyCapabilities list for the KPI list gate', () => {
    // The previous shape was `anyCapabilities: ["strategy.view", "strategy.view"]`
    // — a single capability duplicated, which is a smell that pointed at a
    // copy-paste from the broken task.* / ovr.* patterns. The KPI list gate
    // should use the single-capability `capability:` form so it reads as
    // "view the KPI list" rather than "match either of two identical caps".
    expect(sidebar).not.toBeNull();
    expect(sidebar).not.toMatch(/anyCapabilities:\s*\[\s*["']kpis\.view["']\s*,\s*["']kpis\.view["']\s*\]/);
  });
});

describe('KPI route guards and sidebar share the same kpis.* capability family', () => {
  // Regression pin: any future divergence between the sidebar's KPI module
  // and the route guards at /performance/kpis* is a contract bug. The
  // sidebar's list gate MUST be a kpis.* capability, and the create child
  // MUST be a kpis.* capability. This is the exact "same capability family"
  // contract the Task-13 follow-up was opened to enforce.
  const appSource = readAppSource();
  const nasaqSource = readNasaqSource();
  const sidebar = sliceForNasaqModule(nasaqSource, 'performance_kpis');

  it('sidebar list gate uses the same capability family as the route guard at /performance/kpis', () => {
    const routeSlice = sliceForRoute(appSource, '/performance/kpis');
    expect(routeSlice).not.toBeNull();
    const routeMatch = routeSlice!.match(/"(kpis\.[a-z_]+)"/);
    expect(routeMatch, 'no kpis.* capability found on /performance/kpis route guard').not.toBeNull();
    expect(sidebar).toContain(`"${routeMatch![1]}"`);
  });

  it('sidebar create child uses the same capability family as the route guard at /performance/kpis/new', () => {
    const routeSlice = sliceForRoute(appSource, '/performance/kpis/new');
    expect(routeSlice).not.toBeNull();
    const routeMatch = routeSlice!.match(/"(kpis\.[a-z_]+)"/);
    expect(routeMatch, 'no kpis.* capability found on /performance/kpis/new route guard').not.toBeNull();
    expect(sidebar).toContain(`"${routeMatch![1]}"`);
  });
});

describe('risk statistics route uses risks.view_reports', () => {
  const source = readAppSource();

  it('the /risk-management/statistics route block is present', () => {
    const slice = sliceForRoute(source, '/risk-management/statistics');
    expect(slice).not.toBeNull();
  });

  it('the /risk-management/statistics route requires risks.view_reports', () => {
    const slice = sliceForRoute(source, '/risk-management/statistics');
    expect(slice).not.toBeNull();
    expect(slice).toContain('"risks.view_reports"');
  });

  it('the /risk-management/statistics route does not fall back to risks.view alone', () => {
    const slice = sliceForRoute(source, '/risk-management/statistics');
    expect(slice).not.toBeNull();
    // The "risks.view" bare string should not appear as a gate on the
    // statistics route — only the reports capability is appropriate.
    expect(slice).not.toMatch(/"risks\.view"/);
  });
});

describe('OVR authenticated incident-detail route resolves by report_number', () => {
  const source = readAppSource();

  it('the authenticated detail route uses the :reportNumber URL param', () => {
    // The authenticated detail path is /ovr/incidents/:reportNumber,
    // matching IncidentReport::getRouteKeyName() (which returns
    // "report_number"). The previous shape /ovr/incidents/:tracking_token
    // was wrong — Laravel route-model binding would call
    // ::resolveRouteBinding($token, ...) which does not match any row
    // because every IncidentReport row's report_number is the enumerable
    // OVR-YYYY-NNNN identifier, not the random tracking_token.
    expect(source).toMatch(/path=["']\/ovr\/incidents\/:reportNumber["']/);
  });

  it('does not re-introduce the legacy /ovr/incidents/:tracking_token route', () => {
    // Strip the public tracking route, which legitimately uses
    // :tracking_token under /ovr/track/:tracking_token.
    const withoutPublicTrack = source.replace(
      /<Route\s+path=["']\/ovr\/track[^>]*\/>/g,
      '',
    );
    expect(withoutPublicTrack).not.toMatch(
      /path=["']\/ovr\/incidents\/:tracking_token["']/,
    );
  });

  it('keeps the public track route keyed on :tracking_token', () => {
    expect(source).toMatch(
      /path=["']\/ovr\/track\/:tracking_token["']/,
    );
  });
});

describe('IncidentView page reads the reportNumber URL param', () => {
  const source = readIncidentViewSource();

  it('destructures useParams() with the reportNumber key', () => {
    expect(source).toMatch(
      /useParams<\s*{\s*reportNumber\s*:\s*string\s*}\s*>\(\)/,
    );
  });

  it('no longer destructures useParams() with the tracking_token key', () => {
    expect(source).not.toMatch(
      /useParams<\s*{\s*tracking_token\s*:\s*string\s*}\s*>\(\)/,
    );
  });
});