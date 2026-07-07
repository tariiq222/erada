import { describe, expect, it } from 'vitest';

/**
 * Contract test: /api/surveys
 *
 * See `ProjectsContract.test.ts` for the rationale on snapshot-based
 * contract tests vs real HTTP.
 *
 * Captured 2026-06-29 against `http://localhost:8000`. The index
 * endpoint uses Laravel's paginator with a `meta` wrapper; the show
 * endpoint returns the bare Survey object.
 */

import surveysIndex from './fixtures/surveys-index.json';
import surveysShow from './fixtures/surveys-show.json';
import type { Survey } from '@entities/survey/model/survey';

describe('contract: /api/surveys', () => {
  describe('index envelope', () => {
    it('returns a paginated Laravel envelope (data + links + meta)', () => {
      expect(surveysIndex).toEqual(
        expect.objectContaining({
          data: expect.any(Array),
          links: expect.any(Object),
          meta: expect.any(Object),
        }),
      );
      expect((surveysIndex as any).meta).toEqual(
        expect.objectContaining({
          current_page: expect.any(Number),
          per_page: expect.any(Number),
          last_page: expect.any(Number),
          total: expect.any(Number),
        }),
      );
    });

    it('each item carries the Survey interface fields the FE types declare', () => {
      const requiredKeys: Array<keyof Survey> = [
        'id',
        'code',
        'title',
        'description',
        'type',
        'status',
        'is_public',
        'requires_auth',
        'allow_multiple_responses',
        'allow_edit_response',
        'accepting_responses',
        'responses_count',
        'fields_count',
        'published_at',
        'created_at',
        'public_url',
      ];
      const items = (surveysIndex as any).data as any[];
      expect(items.length).toBeGreaterThan(0);

      for (const item of items) {
        for (const key of requiredKeys) {
          // Nullable fields may be null — assert presence of the key.
          expect(key in item).toBe(true);
        }
      }
    });
  });

  describe('show envelope', () => {
    it('returns the bare Survey object (no { data: ... } wrapper)', () => {
      // The controller returns the resource directly, not wrapped.
      expect((surveysShow as any).data).toBeUndefined();
      expect(typeof (surveysShow as any).id).toBe('number');
      expect(typeof (surveysShow as any).title).toBe('string');
      expect(typeof (surveysShow as any).code).toBe('string');
    });

    it('returns the same Survey fields as the index item', () => {
      const showItem = surveysShow as any;
      expect('id' in showItem).toBe(true);
      expect('code' in showItem).toBe(true);
      expect('title' in showItem).toBe(true);
      expect('status' in showItem).toBe(true);
      expect('type' in showItem).toBe(true);
      expect('is_public' in showItem).toBe(true);
    });
  });
});