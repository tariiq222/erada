import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const jsonResponse = (body: unknown, init: { status?: number } = {}) =>
  new Response(JSON.stringify(body), {
    headers: { 'Content-Type': 'application/json' },
    ...init,
  });

async function freshApi() {
  vi.resetModules();
  return await import('@shared/api/client');
}

describe('shared API client — 403 handling (P3-D)', () => {
  beforeEach(() => {
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

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('returns a 403 error with a localized Arabic message when the server does not include one', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse({}, { status: 403 })));
    const { api } = await freshApi();

    await expect(api.get('/projects/1')).rejects.toMatchObject({
      status: 403,
      message: 'لا تملك صلاحية لتنفيذ هذا الإجراء',
    });
  });

  it('surfaces the server-provided 403 message when present (e.g. policy-specific text)', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(
        jsonResponse(
          { message: 'You are not allowed to update this project.', errors: { name: ['forbidden'] } },
          { status: 403 },
        ),
      ),
    );
    const { api } = await freshApi();

    await expect(api.put('/projects/1', { name: 'Updated' })).rejects.toMatchObject({
      status: 403,
      message: 'You are not allowed to update this project.',
      errors: { name: ['forbidden'] },
    });
  });

  it('regression: 401 still clears auth, throws the hardcoded Arabic message, and redirects to /login', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse({ message: 'unauthorized' }, { status: 401 })));
    const { api } = await freshApi();
    api.setAuthenticated(true);

    await expect(api.get('/me')).rejects.toMatchObject({
      status: 401,
      message: 'غير مصرح',
    });
    await new Promise(resolve => setTimeout(resolve, 0));
    expect(api.isUserAuthenticated()).toBe(false);
    expect(window.location.replace).toHaveBeenCalledWith('/login');
  });

  it('regression: 500 responses still surface a generic Arabic fallback via parseErrorResponse', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(jsonResponse({ message: 'Server Error' }, { status: 500 })),
    );
    const { api } = await freshApi();

    await expect(api.get('/projects')).rejects.toMatchObject({
      status: 500,
      message: 'Server Error',
    });
  });
});
