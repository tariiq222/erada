import { describe, expect, it } from 'vitest';

/**
 * Contract test: /api/ovr/incidents + /api/risk-management/risks
 *
 * See `ProjectsContract.test.ts` for the rationale on snapshot-based
 * contract tests vs real HTTP.
 *
 * Captured 2026-06-29 against `http://localhost:8000`. Both endpoints
 * return the SAME Laravel paginator shape (`LengthAwarePaginator`)
 * with `data`, `current_page`, `per_page`, `links`, etc. — unlike
 * `/api/surveys` which is non-paginated. We assert that explicitly.
 */

import ovrIndex from './fixtures/ovr-index.json';
import risksIndex from './fixtures/risks-index.json';

describe('contract: GET /api/ovr/incidents', () => {
  it('returns the Laravel paginator envelope with success flag', () => {
    expect(ovrIndex).toEqual(
      expect.objectContaining({
        data: expect.any(Array),
        links: expect.any(Object),
        meta: expect.any(Object),
        success: true,
      }),
    );
    // The FE (pages/ovr list) reads meta.current_page + meta.per_page.
    expect((ovrIndex as any).meta).toEqual(
      expect.objectContaining({
        current_page: expect.any(Number),
        per_page: expect.any(Number),
        last_page: expect.any(Number),
        total: expect.any(Number),
      }),
    );
    // Links object has first/last/prev/next URLs.
    expect((ovrIndex as any).links).toEqual(
      expect.objectContaining({
        first: expect.any(String),
        last: expect.any(String),
      }),
    );
  });

  it('returns no top-level current_page (it is nested under meta)', () => {
    // Common refactor mistake: a controller that returns paginate()
    // for one endpoint and a plain data array for another. Asserting
    // the wrapper shape here catches accidental swap.
    expect((ovrIndex as any).current_page).toBeUndefined();
  });
});

describe('contract: GET /api/risk-management/risks', () => {
  it('returns the Laravel paginator envelope (flat, not nested under meta)', () => {
    // /api/risk-management/risks uses the older LengthAwarePaginator
    // shape: current_page/per_page are TOP-LEVEL, not nested under meta.
    // The FE (entities/risk list) reads current_page directly.
    expect(risksIndex).toEqual(
      expect.objectContaining({
        data: expect.any(Array),
        current_page: expect.any(Number),
        per_page: expect.any(Number),
        last_page: expect.any(Number),
        total: expect.any(Number),
        links: expect.any(Array),
      }),
    );
    // No `meta` wrapper, no `success` flag for this endpoint.
    expect((risksIndex as any).meta).toBeUndefined();
    expect((risksIndex as any).success).toBeUndefined();
  });
});