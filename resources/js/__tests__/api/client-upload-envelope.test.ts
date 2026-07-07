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

describe('shared API client upload, envelope, and download contracts', () => {
  beforeEach(() => {
    vi.restoreAllMocks();
    document.head.innerHTML = '<meta name="csrf-token" content="csrf-meta-token">';
    Object.defineProperty(document, 'cookie', {
      configurable: true,
      value: 'XSRF-TOKEN=xsrf-cookie-token',
    });
  });

  it('posts FormData with credentials and CSRF/XSRF headers without JSON Content-Type', async () => {
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse({ success: true, data: { id: 1 } }, { status: 200 }));
    vi.stubGlobal('fetch', fetchMock);
    const { api } = await freshApi();
    const formData = new FormData();
    formData.append('attachment', new File(['content'], 'report.pdf', { type: 'application/pdf' }));

    await api.post('/comments', formData);

    expect(fetchMock).toHaveBeenCalledWith('/api/comments', expect.objectContaining({
      method: 'POST',
      credentials: 'include',
      body: formData,
    }));
    const [, config] = fetchMock.mock.calls[0] as [string, RequestInit];
    const headers = config.headers as Record<string, string>;
    expect(headers.Accept).toBe('application/json');
    expect(headers['X-Requested-With']).toBe('XMLHttpRequest');
    expect(headers['X-CSRF-TOKEN']).toBe('csrf-meta-token');
    expect(headers['X-XSRF-TOKEN']).toBe('xsrf-cookie-token');
    expect(headers['Content-Type']).toBeUndefined();
  });

  it('parses FormData failures through the shared ApiError shape', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse({
      success: false,
      message: 'الملف غير صالح',
      errors: { attachment: ['نوع الملف غير مدعوم'] },
    }, { status: 422 })));
    const { api } = await freshApi();
    const formData = new FormData();
    formData.append('attachment', new File(['bad'], 'bad.exe'));

    await expect(api.post('/comments', formData)).rejects.toMatchObject({
      status: 422,
      message: 'الملف غير صالح',
      errors: { attachment: ['نوع الملف غير مدعوم'] },
    });
  });

  it('preserves additive v1.2 envelope keys without unwrapping or relocating data', async () => {
    const envelope = {
      success: true,
      data: { id: 7, title: 'نموذج' },
      message: 'تم',
      download_url: '/api/files/7/download',
      meta: { current_page: 1 },
      links: { next: null },
      version_hash: 'v1.2-hash',
    };
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse(envelope, { status: 200 })));
    const { api } = await freshApi();

    await expect(api.get('/surveys/public/abc')).resolves.toEqual(envelope);
  });

  it('returns Blob responses without calling json for binary downloads', async () => {
    const expectedBlob = new Blob(['private-file'], { type: 'application/pdf' });
    const response = {
      ok: true,
      status: 200,
      blob: vi.fn().mockResolvedValue(expectedBlob),
      json: vi.fn(),
    } as unknown as Response;
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(response));
    const { api } = await freshApi();

    const result = await api.blob('/attachments/1/download');

    expect(result).toBe(expectedBlob);
    expect(response.blob).toHaveBeenCalledTimes(1);
    expect(response.json).not.toHaveBeenCalled();
  });
});
