import { beforeEach, describe, expect, it, vi } from 'vitest';

const jsonResponse = (body: unknown, init: { status?: number } = {}) =>
  new Response(JSON.stringify(body), {
    headers: { 'Content-Type': 'application/json' },
    ...init,
  });

async function freshApi() {
  vi.resetModules();
  return await import('@shared/api/client');
}

describe('shared API client resilience behavior', () => {
  beforeEach(() => {
    vi.useFakeTimers();
    vi.restoreAllMocks();
    document.head.innerHTML = '<meta name="csrf-token" content="csrf-token-one">';
    Object.defineProperty(document, 'cookie', {
      configurable: true,
      value: 'XSRF-TOKEN=xsrf-token-one',
    });
    window.history.replaceState({}, '', '/projects');
    Object.defineProperty(window, 'location', {
      configurable: true,
      value: { ...window.location, pathname: '/projects', replace: vi.fn() },
    });
  });

  it('deduplicates concurrent GET requests and clears pending request after completion', async () => {
    const fetchMock = vi.fn().mockImplementation(() => Promise.resolve(jsonResponse({ ok: true }, { status: 200 })));
    vi.stubGlobal('fetch', fetchMock);
    const { api } = await freshApi();

    const [first, second] = await Promise.all([api.get('/projects'), api.get('/projects')]);
    const third = await api.get('/projects');

    expect(first).toEqual({ ok: true });
    expect(second).toEqual({ ok: true });
    expect(third).toEqual({ ok: true });
    expect(fetchMock).toHaveBeenCalledTimes(2);
  });

  it('refreshes CSRF cookie on 419 and retries the original state-changing request', async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(jsonResponse({ message: 'CSRF mismatch' }, { status: 419 }))
      .mockResolvedValueOnce(new Response(null, { status: 204 }))
      .mockResolvedValueOnce(jsonResponse({ success: true, id: 9 }, { status: 200 }));
    vi.stubGlobal('fetch', fetchMock);
    const { api } = await freshApi();

    await expect(api.post('/projects', { name: 'Project' })).resolves.toEqual({ success: true, id: 9 });

    expect(fetchMock).toHaveBeenNthCalledWith(1, '/api/projects', expect.objectContaining({ method: 'POST' }));
    expect(fetchMock).toHaveBeenNthCalledWith(2, '/sanctum/csrf-cookie', expect.objectContaining({ method: 'GET' }));
    expect(fetchMock).toHaveBeenNthCalledWith(3, '/api/projects', expect.objectContaining({ method: 'POST' }));
  });

  it('throws session-expired error and redirects once when CSRF retry is still unauthorized', async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(jsonResponse({ message: 'CSRF mismatch' }, { status: 419 }))
      .mockResolvedValueOnce(new Response(null, { status: 204 }))
      .mockResolvedValueOnce(jsonResponse({ message: 'unauthorized' }, { status: 401 }));
    vi.stubGlobal('fetch', fetchMock);
    const { api } = await freshApi();
    api.setAuthenticated(true);

    await expect(api.put('/projects/1', { name: 'Updated' })).rejects.toMatchObject({
      status: 401,
      message: 'انتهت صلاحية الجلسة. يرجى تسجيل الدخول مرة أخرى.',
    });
    expect(api.isUserAuthenticated()).toBe(false);

    await vi.runAllTimersAsync();
    expect(window.location.replace).toHaveBeenCalledWith('/login');
  });

  it('normalizes rate limit errors with a default retry_after value', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse({ message: 'Too many attempts' }, { status: 429 })));
    const { api } = await freshApi();

    await expect(api.get('/projects')).rejects.toMatchObject({
      status: 429,
      message: 'Too many attempts',
      retry_after: 60,
    });
  });
});
