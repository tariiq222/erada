import { beforeEach, describe, expect, it, vi } from 'vitest';

const jsonResponse = (body: unknown, init: { status?: number } = {}) =>
  new Response(JSON.stringify(body), {
    headers: { 'Content-Type': 'application/json' },
    ...init,
  });

const emptyResponse = (init: { status?: number } = {}) =>
  new Response(null, { status: init.status ?? 204 });

/**
 * Build a fetch mock that returns a fresh Response instance per call.
 * Required because Response bodies are single-use — a shared instance
 * throws "Body is unusable" on the second .json()/.blob() read.
 */
function fetchReturning(body: unknown, status = 200) {
  return vi.fn().mockImplementation(() => Promise.resolve(jsonResponse(body, { status })));
}

async function freshApi() {
  vi.resetModules();
  return await import('@shared/api/client');
}

function findHeader(
  init: RequestInit | undefined,
  name: string,
): string | null {
  if (!init || !init.headers) return null;
  const headers = init.headers as Record<string, string>;
  for (const [k, v] of Object.entries(headers)) {
    if (k.toLowerCase() === name.toLowerCase()) return v;
  }
  return null;
}

describe('shared API client — X-Idempotency-Key (Task 12)', () => {
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

  it('attaches an auto-generated X-Idempotency-Key to POST mutations', async () => {
    const fetchMock = fetchReturning({ ok: true });
    vi.stubGlobal('fetch', fetchMock);
    const { api } = await freshApi();

    await api.post('/projects', { name: 'Project A' });

    expect(fetchMock).toHaveBeenCalledTimes(1);
    const init = fetchMock.mock.calls[0]?.[1] as RequestInit | undefined;
    const key = findHeader(init, 'X-Idempotency-Key');
    expect(key).not.toBeNull();
    expect(key).toMatch(/^[A-Za-z0-9_-]+$/);
    expect((key ?? '').length).toBeGreaterThanOrEqual(16);
  });

  it('attaches an X-Idempotency-Key to PUT, PATCH, and DELETE mutations', async () => {
    const fetchMock = fetchReturning({ ok: true });
    vi.stubGlobal('fetch', fetchMock);
    const { api } = await freshApi();

    await api.put('/projects/1', { name: 'Updated' });
    await api.patch('/projects/1', { name: 'Patched' });
    await api.delete('/projects/1');

    expect(fetchMock).toHaveBeenCalledTimes(3);
    for (let i = 0; i < 3; i += 1) {
      const init = fetchMock.mock.calls[i]?.[1] as RequestInit | undefined;
      const key = findHeader(init, 'X-Idempotency-Key');
      expect(key, `call #${i + 1} should carry an idempotency key`).not.toBeNull();
    }
  });

  it('does NOT attach X-Idempotency-Key to GET requests', async () => {
    const fetchMock = fetchReturning({ ok: true });
    vi.stubGlobal('fetch', fetchMock);
    const { api } = await freshApi();

    await api.get('/projects');
    await api.get('/projects/1');

    expect(fetchMock).toHaveBeenCalledTimes(2);
    for (let i = 0; i < 2; i += 1) {
      const init = fetchMock.mock.calls[i]?.[1] as RequestInit | undefined;
      expect(findHeader(init, 'X-Idempotency-Key')).toBeNull();
    }
  });

  it('reuses the SAME X-Idempotency-Key when a mutation is retried after a 419 CSRF refresh', async () => {
    const fetchMock = vi
      .fn()
      .mockImplementationOnce(() => Promise.resolve(jsonResponse({ message: 'CSRF mismatch' }, { status: 419 })))
      .mockImplementationOnce(() => Promise.resolve(emptyResponse({ status: 204 })))
      .mockImplementationOnce(() => Promise.resolve(jsonResponse({ ok: true, id: 9 }, { status: 200 })));
    vi.stubGlobal('fetch', fetchMock);
    const { api } = await freshApi();

    await expect(api.post('/projects', { name: 'Project' })).resolves.toEqual({ ok: true, id: 9 });

    expect(fetchMock).toHaveBeenCalledTimes(3);
    const firstInit = fetchMock.mock.calls[0]?.[1] as RequestInit | undefined;
    const thirdInit = fetchMock.mock.calls[2]?.[1] as RequestInit | undefined;
    const firstKey = findHeader(firstInit, 'X-Idempotency-Key');
    const thirdKey = findHeader(thirdInit, 'X-Idempotency-Key');
    expect(firstKey).not.toBeNull();
    expect(thirdKey).toBe(firstKey);
  });

  it('issues DIFFERENT X-Idempotency-Key values for separate mutation calls', async () => {
    const fetchMock = fetchReturning({ ok: true });
    vi.stubGlobal('fetch', fetchMock);
    const { api } = await freshApi();

    await api.post('/projects', { name: 'A' });
    await api.post('/projects', { name: 'B' });
    await api.post('/projects', { name: 'C' });

    expect(fetchMock).toHaveBeenCalledTimes(3);
    const keys = fetchMock.mock.calls.map(([, init]) =>
      findHeader(init as RequestInit | undefined, 'X-Idempotency-Key'),
    );
    expect(keys.every(k => k !== null)).toBe(true);
    expect(new Set(keys).size).toBe(keys.length);
  });

  it('skips X-Idempotency-Key for /login (auth flow — credential-bearing)', async () => {
    const fetchMock = fetchReturning({ ok: true });
    vi.stubGlobal('fetch', fetchMock);
    const { api } = await freshApi();

    await api.post('/login', { email: 'a@b.c', password: 'secret' });

    expect(fetchMock).toHaveBeenCalledTimes(1);
    const init = fetchMock.mock.calls[0]?.[1] as RequestInit | undefined;
    expect(findHeader(init, 'X-Idempotency-Key')).toBeNull();
  });

  it('skips X-Idempotency-Key for /register, /2fa/*, and /password/* (auth flows)', async () => {
    const fetchMock = fetchReturning({ ok: true });
    vi.stubGlobal('fetch', fetchMock);
    const { api } = await freshApi();

    await api.post('/register', { name: 'X', email: 'a@b.c', password: 'p' });
    await api.post('/2fa/verify', { code: '123456' });
    await api.post('/2fa/enable', { password: 'p' });
    await api.post('/password/forgot', { email: 'a@b.c' });
    await api.post('/password/reset', { token: 't', password: 'p' });

    expect(fetchMock).toHaveBeenCalledTimes(5);
    for (let i = 0; i < 5; i += 1) {
      const init = fetchMock.mock.calls[i]?.[1] as RequestInit | undefined;
      expect(findHeader(init, 'X-Idempotency-Key')).toBeNull();
    }
  });

  it('honors a caller-supplied X-Idempotency-Key passed via options', async () => {
    const fetchMock = fetchReturning({ ok: true });
    vi.stubGlobal('fetch', fetchMock);
    const { api } = await freshApi();

    await api.post('/projects', { name: 'X' }, { idempotencyKey: 'fixed-key-123' });

    expect(fetchMock).toHaveBeenCalledTimes(1);
    const init = fetchMock.mock.calls[0]?.[1] as RequestInit | undefined;
    expect(findHeader(init, 'X-Idempotency-Key')).toBe('fixed-key-123');
  });

  it('reuses a caller-supplied X-Idempotency-Key across 419 retries (stable intent)', async () => {
    const fetchMock = vi
      .fn()
      .mockImplementationOnce(() => Promise.resolve(jsonResponse({ message: 'CSRF mismatch' }, { status: 419 })))
      .mockImplementationOnce(() => Promise.resolve(emptyResponse({ status: 204 })))
      .mockImplementationOnce(() => Promise.resolve(jsonResponse({ ok: true }, { status: 200 })));
    vi.stubGlobal('fetch', fetchMock);
    const { api } = await freshApi();

    await api.post('/projects', { name: 'X' }, { idempotencyKey: 'user-intent-7' });

    expect(fetchMock).toHaveBeenCalledTimes(3);
    const first = findHeader(fetchMock.mock.calls[0]?.[1] as RequestInit | undefined, 'X-Idempotency-Key');
    const third = findHeader(fetchMock.mock.calls[2]?.[1] as RequestInit | undefined, 'X-Idempotency-Key');
    expect(first).toBe('user-intent-7');
    expect(third).toBe('user-intent-7');
  });
});
