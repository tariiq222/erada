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