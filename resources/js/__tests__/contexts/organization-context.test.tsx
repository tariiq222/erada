import React from 'react';
import { act, renderHook, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const apiPost = vi.fn();
const apiGet = vi.fn();
const reloadStub = vi.fn();

vi.mock('@shared/api/client', () => ({
  api: {
    post: (...args: unknown[]) => apiPost(...args),
    get: (...args: unknown[]) => apiGet(...args),
  },
}));

vi.mock('@shared/api/auth', () => ({
  authApi: {
    getUser: () => apiGet('/user'),
  },
}));

const userFixture = {
  user: {
    id: 7,
    organization_id: 1,
    organizations: [
      { id: 1, name: 'A', code: 'A', is_active: true },
      { id: 5, name: 'B', code: 'B', is_active: true },
    ],
  },
};

beforeEach(() => {
  apiPost.mockReset();
  apiGet.mockReset();
  reloadStub.mockReset();

  // localStorage is already mocked in __tests__/setup.ts; ensure clean state.
  (window.localStorage.getItem as ReturnType<typeof vi.fn>).mockReset();
  (window.localStorage.setItem as ReturnType<typeof vi.fn>).mockReset();
  (window.localStorage.getItem as ReturnType<typeof vi.fn>).mockReturnValue(null);

  // Replace window.location.reload so we can assert on it without jsdom navigation.
  Object.defineProperty(window, 'location', {
    configurable: true,
    value: { ...window.location, reload: reloadStub },
  });

  apiGet.mockResolvedValue(userFixture);
});

afterEach(() => {
  vi.restoreAllMocks();
});

import { OrganizationProvider, useOrganization } from '@shared/contexts/OrganizationContext';

const wrapper: React.FC<{ children: React.ReactNode }> = ({ children }) => (
  <OrganizationProvider>{children}</OrganizationProvider>
);

describe('OrganizationContext.switchOrganization', () => {
  it('does not POST to the missing /auth/switch-org endpoint', async () => {
    const { result } = renderHook(() => useOrganization(), { wrapper });

    await waitFor(() => {
      expect(result.current.organizations.length).toBeGreaterThan(0);
    });

    await act(async () => {
      await result.current.switchOrganization(5);
    });

    const switchCalls = apiPost.mock.calls.filter(
      ([url]) => typeof url === 'string' && url.includes('/auth/switch-org'),
    );
    expect(switchCalls).toEqual([]);
  });

  it('does not POST to /api/auth/switch-org or any /auth/switch-org variant', async () => {
    const { result } = renderHook(() => useOrganization(), { wrapper });

    await waitFor(() => {
      expect(result.current.organizations.length).toBeGreaterThan(0);
    });

    await act(async () => {
      await result.current.switchOrganization(5);
    });

    // The backend never implemented this endpoint; the client must not depend on it.
    expect(apiPost).not.toHaveBeenCalled();
  });

  it('persists the chosen org id to localStorage under iradah:current_organization_id', async () => {
    const { result } = renderHook(() => useOrganization(), { wrapper });

    await waitFor(() => {
      expect(result.current.organizations.length).toBeGreaterThan(0);
    });

    await act(async () => {
      await result.current.switchOrganization(5);
    });

    expect(window.localStorage.setItem).toHaveBeenCalledWith(
      'iradah:current_organization_id',
      '5',
    );
  });

  it('updates currentOrganization state to the new org', async () => {
    const { result } = renderHook(() => useOrganization(), { wrapper });

    await waitFor(() => {
      expect(result.current.currentOrganization?.id).toBe(1);
    });

    await act(async () => {
      await result.current.switchOrganization(5);
    });

    expect(result.current.currentOrganization?.id).toBe(5);
  });

  it('triggers window.location.reload so the next request carries the new X-Organization-Id header and ApiClient caches reset', async () => {
    const { result } = renderHook(() => useOrganization(), { wrapper });

    await waitFor(() => {
      expect(result.current.currentOrganization?.id).toBe(1);
    });

    await act(async () => {
      await result.current.switchOrganization(5);
    });

    expect(reloadStub).toHaveBeenCalledTimes(1);
  });

  it('is a no-op when the requested org id is not in the available organizations list', async () => {
    const { result } = renderHook(() => useOrganization(), { wrapper });

    await waitFor(() => {
      expect(result.current.organizations.length).toBeGreaterThan(0);
    });

    await act(async () => {
      await result.current.switchOrganization(999);
    });

    // State unchanged.
    expect(result.current.currentOrganization?.id).toBe(1);
    // No backend traffic, no storage write, no reload.
    expect(apiPost).not.toHaveBeenCalled();
    expect(window.localStorage.setItem).not.toHaveBeenCalled();
    expect(reloadStub).not.toHaveBeenCalled();
  });
});