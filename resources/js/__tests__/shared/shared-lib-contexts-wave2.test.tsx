import React from 'react';
import { AxiosError } from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

const makeAxiosError = (status: number, data: unknown = {}) => {
  const error = new AxiosError('Request failed', 'ERR_BAD_RESPONSE');
  Object.assign(error, { response: { status, data } });
  return error;
};

describe('shared cache wave 2 coverage', () => {
  beforeEach(async () => {
    vi.useFakeTimers();
    const { cache } = await import('@shared/lib/cache');
    cache.clear();
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it('stores, expires, deduplicates, invalidates, and exposes cache keys', async () => {
    const { cache, CacheKeys, CacheTTL } = await import('@shared/lib/cache');
    expect(CacheKeys.projects.byId(7)).toBe('projects:7');
    expect(CacheKeys.tasks.byProject(3)).toBe('tasks:project:3');
    expect(CacheKeys.users.current()).toBe('users:current');
    expect(CacheKeys.departments.all()).toBe('departments:all');
    expect(CacheTTL.SHORT).toBe(30_000);

    cache.set('projects:one', { id: 1 }, 1000);
    expect(cache.get('projects:one')).toEqual({ id: 1 });
    expect(cache.has('projects:one')).toBe(true);
    expect(cache.size).toBe(1);
    expect(cache.keys()).toContain('projects:one');

    vi.advanceTimersByTime(1001);
    expect(cache.get('projects:one')).toBeNull();
    expect(cache.has('projects:one')).toBe(false);

    const fetcher = vi.fn().mockResolvedValue({ ok: true });
    const first = cache.getOrFetch('dedupe', fetcher, { ttl: 5000 });
    const second = cache.getOrFetch('dedupe', fetcher, { ttl: 5000 });
    await expect(Promise.all([first, second])).resolves.toEqual([{ ok: true }, { ok: true }]);
    expect(fetcher).toHaveBeenCalledTimes(1);

    await expect(cache.getOrFetch('dedupe', fetcher)).resolves.toEqual({ ok: true });
    expect(fetcher).toHaveBeenCalledTimes(1);

    cache.set('projects:two', 2);
    cache.set('tasks:one', 1);
    cache.invalidate('projects:');
    expect(cache.has('projects:two')).toBe(false);
    expect(cache.has('tasks:one')).toBe(true);
    cache.delete('tasks:one');
    expect(cache.size).toBe(1);

    const failing = vi.fn().mockRejectedValue(new Error('boom'));
    await expect(cache.getOrFetch('bad', failing)).rejects.toThrow('boom');
    await expect(cache.getOrFetch('bad', vi.fn().mockResolvedValue('recovered'))).resolves.toBe('recovered');

    cache.set('expired-cleanup', 'x', 10);
    vi.advanceTimersByTime(11);
    cache.cleanup();
    expect(cache.has('expired-cleanup')).toBe(false);
    cache.clear();
    expect(cache.size).toBe(0);
  });
});

describe('shared error handler wave 2 coverage', () => {
  it('parses every API error branch and invokes callbacks/toasts', async () => {
    const mod = await import('@shared/lib/errorHandler');
    expect(mod.parseApiError(makeAxiosError(422, { message: 'Invalid', errors: { name: ['Required'] } }))).toMatchObject({ code: 'VALIDATION_ERROR', errors: { name: ['Required'] } });
    expect(mod.parseApiError(makeAxiosError(401))).toMatchObject({ code: 'UNAUTHORIZED' });
    expect(mod.parseApiError(makeAxiosError(403))).toMatchObject({ code: 'FORBIDDEN' });
    expect(mod.parseApiError(makeAxiosError(404))).toMatchObject({ code: 'NOT_FOUND' });
    expect(mod.parseApiError(makeAxiosError(429))).toMatchObject({ code: 'RATE_LIMITED' });
    expect(mod.parseApiError(makeAxiosError(503))).toMatchObject({ code: 'SERVER_ERROR' });
    expect(mod.parseApiError(makeAxiosError(418, { message: 'Teapot' }))).toMatchObject({ code: 'UNKNOWN_ERROR', message: 'Teapot' });
    expect(mod.parseApiError(new Error('Network Error'))).toMatchObject({ code: 'NETWORK_ERROR', status: 0 });
    expect(mod.parseApiError(new Error('plain'))).toMatchObject({ code: 'UNKNOWN_ERROR', message: 'plain' });
    expect(mod.parseApiError('bad')).toMatchObject({ message: 'حدث خطأ غير متوقع' });

    expect(mod.getValidationErrorsString({ a: ['A'], b: ['B', 'C'] })).toBe('A\nB\nC');
    expect(mod.getValidationErrorsString()).toBe('');
    expect(mod.getFieldError({ email: ['Invalid'] }, 'email')).toBe('Invalid');
    expect(mod.getFieldError(undefined, 'email')).toBeUndefined();

    const callbacks = {
      onUnauthorized: vi.fn(), onForbidden: vi.fn(), onNotFound: vi.fn(), onValidationError: vi.fn(), onNetworkError: vi.fn(), onServerError: vi.fn(), showToast: vi.fn(),
    };
    expect(mod.handleApiError(makeAxiosError(401), callbacks).code).toBe('UNAUTHORIZED');
    expect(mod.handleApiError(makeAxiosError(403), callbacks).code).toBe('FORBIDDEN');
    expect(mod.handleApiError(makeAxiosError(404), callbacks).code).toBe('NOT_FOUND');
    expect(mod.handleApiError(makeAxiosError(422, { errors: { title: ['Missing'] } }), callbacks).code).toBe('VALIDATION_ERROR');
    expect(mod.handleApiError(new Error('Network Error'), callbacks).code).toBe('NETWORK_ERROR');
    expect(mod.handleApiError(makeAxiosError(500), callbacks).code).toBe('SERVER_ERROR');
    expect(callbacks.onUnauthorized).toHaveBeenCalled();
    expect(callbacks.onForbidden).toHaveBeenCalled();
    expect(callbacks.onNotFound).toHaveBeenCalled();
    expect(callbacks.onValidationError).toHaveBeenCalledWith({ title: ['Missing'] });
    expect(callbacks.onNetworkError).toHaveBeenCalled();
    expect(callbacks.onServerError).toHaveBeenCalled();
    expect(callbacks.showToast).toHaveBeenCalledWith('حدث خطأ في الخادم، يرجى المحاولة لاحقاً', 'error');

    const validation = mod.parseApiError(makeAxiosError(422, { errors: { name: ['Required'] } }));
    expect(mod.isValidationError(validation)).toBe(true);
    expect(mod.isUnauthorizedError(mod.parseApiError(makeAxiosError(401)))).toBe(true);
    expect(mod.isForbiddenError(mod.parseApiError(makeAxiosError(403)))).toBe(true);
    expect(mod.isNotFoundError(mod.parseApiError(makeAxiosError(404)))).toBe(true);
    expect(mod.isNetworkError(mod.parseApiError(new Error('Network Error')))).toBe(true);
    expect(mod.isServerError(mod.parseApiError(makeAxiosError(500)))).toBe(true);
  });
});

describe('theme and toast contexts wave 2 coverage', () => {
  beforeEach(() => {
    vi.useRealTimers();
    localStorage.clear();
    document.documentElement.className = '';
    window.matchMedia = vi.fn().mockReturnValue({ matches: true, addEventListener: vi.fn(), removeEventListener: vi.fn() });
  });

  it('applies stored/system theme, toggles theme, and enforces provider usage', async () => {
    const { ThemeProvider, useTheme } = await import('@shared/contexts/ThemeContext');
    const Probe = () => {
      const theme = useTheme();
      return <div>
        <button onClick={() => theme.toggleTheme()} data-theme={theme.theme} data-resolved={theme.resolvedTheme}>toggle</button>
        <button onClick={() => theme.setTheme('light')}>light</button>
      </div>;
    };
    render(<ThemeProvider><Probe /></ThemeProvider>);
    expect(screen.getByRole('button', { name: 'toggle' })).toHaveAttribute('data-theme', 'system');
    expect(document.documentElement.classList.contains('dark')).toBe(true);
    await userEvent.click(screen.getByRole('button', { name: 'light' }));
    expect(document.documentElement.classList.contains('dark')).toBe(false);

    const Thrower = () => { useTheme(); return null; };
    expect(() => render(<Thrower />)).toThrow('useTheme must be used within a ThemeProvider');
  });

  it('shows, closes, and keeps sticky toasts', async () => {
    const { ToastProvider, useToast } = await import('@shared/ui/Toast');
    const Probe = () => {
      const toast = useToast();
      return <div>
        <button onClick={() => toast.showToast('success', 'Saved', 'Success title')}>success</button>
        <button onClick={() => toast.showToast('error', 'Failed', 'Error title')}>error</button>
        <button onClick={() => toast.showToast('warning', 'Careful')}>warning</button>
        <button onClick={() => toast.showToast('info', 'FYI')}>info</button>
        <button onClick={() => toast.addToast({ variant: 'info', message: 'Sticky', duration: 0 })}>sticky</button>
        <span data-testid="count">{toast.toasts.length}</span>
      </div>;
    };
    render(<ToastProvider><Probe /></ToastProvider>);
    await userEvent.click(screen.getByText('success'));
    expect(screen.getByText('Success title')).toBeInTheDocument();
    await userEvent.click(screen.getByLabelText('Close'));
    expect(screen.queryByText('Success title')).not.toBeInTheDocument();

    await userEvent.click(screen.getByText('error'));
    await userEvent.click(screen.getByText('warning'));
    await userEvent.click(screen.getByText('info'));
    expect(screen.getByTestId('count')).toHaveTextContent('3');
    await userEvent.click(screen.getAllByLabelText('Close')[0]);
    await userEvent.click(screen.getAllByLabelText('Close')[0]);
    await userEvent.click(screen.getAllByLabelText('Close')[0]);
    expect(screen.getByTestId('count')).toHaveTextContent('0');

    await userEvent.click(screen.getByText('sticky'));
    expect(screen.getByText('Sticky')).toBeInTheDocument();

    const Thrower = () => { useToast(); return null; };
    expect(() => render(<Thrower />)).toThrow('useToast must be used within a ToastProvider');
  });
});
