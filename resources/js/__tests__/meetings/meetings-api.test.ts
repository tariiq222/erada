import { describe, expect, it, vi } from 'vitest';

const mocks = vi.hoisted(() => ({ post: vi.fn(), put: vi.fn() }));

vi.mock('@shared/api/client', () => ({
  api: {
    get: vi.fn(),
    post: mocks.post,
    put: mocks.put,
    delete: vi.fn(),
  },
}));

import { meetingsApi, recommendationsApi } from '@features/meetings/api';

describe('Meetings API envelopes', () => {
  it('unwraps a meeting create response', async () => {
    mocks.post.mockResolvedValueOnce({ message: 'created', meeting: { id: 99, title: 'اجتماع' } });

    await expect(meetingsApi.create({ title: 'اجتماع' } as never)).resolves.toEqual({ id: 99, title: 'اجتماع' });
  });

  it('unwraps a recommendation create response', async () => {
    mocks.post.mockResolvedValueOnce({ message: 'created', recommendation: { id: 45, title: 'توصية' } });

    await expect(recommendationsApi.create({} as never)).resolves.toEqual({ id: 45, title: 'توصية' });
  });
});
