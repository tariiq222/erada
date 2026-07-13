import { describe, expect, it, vi } from 'vitest';

describe('external admin origin URL contract', () => {
  it('builds an absolute admin URL from the configured origin', async () => {
    vi.stubEnv('VITE_ADMIN_URL', 'https://admin.erada.sa/');
    const { adminUrl } = await import('@shared/config/urls');

    expect(adminUrl('/overview')).toBe('https://admin.erada.sa/overview');
    expect(adminUrl('organizations')).toBe('https://admin.erada.sa/organizations');
  });

  it.each(['javascript:alert(1)', '//evil.example/path', 'data:text/html,attack'])('rejects unsafe admin path %s', async (unsafePath) => {
      vi.stubEnv('VITE_ADMIN_URL', 'https://admin.erada.sa');
      const { adminUrl } = await import('@shared/config/urls');

      expect(() => adminUrl(unsafePath)).toThrow();
    });
});
