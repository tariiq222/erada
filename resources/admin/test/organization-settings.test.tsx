/**
 * Focused tests for the OrganizationSuperAdmin settings UI.
 *
 * Scope (per the active task brief):
 *   - resources/admin only — no backend, no migrations.
 *   - Targets `GET /api/organizations/{organization}/settings` and
 *     `PUT /api/organizations/{organization}/settings`. The PUT is sent
 *     with an `X-Idempotency-Key` header and carries only the keys the
 *     actor edited.
 *   - The actor's `organization_id` (from `/api/user`) is the target
 *     organization by default. Platform super admin may also reach the
 *     page via `?organization=<id>` so they explicitly opt in to the
 *     tenant they want to manage; we never widen via `X-Organization-Id`.
 *   - Loading / error / empty / saving states are all covered.
 *
 * Wire-level assertions stay narrow: every test asserts the exact URL the
 * page targets (no legacy `/admin/...` route), and the idempotency header
 * is asserted on every successful PUT.
 */

import React from 'react';
import userEvent from '@testing-library/user-event';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { User } from '@shared/types';
// Side-effect import: boots the i18next instance used by useTranslation
// inside the page so key fallbacks resolve to the translated strings.
import '@shared/config/i18n';
import i18n from '@shared/config/i18n';
import { OrganizationSettingsPage } from '@admin/pages/organization-settings/OrganizationSettingsPage';

// The page reads its current user from useAuth(); type the loose
// organization-scoped fields the page cares about locally so the shared
// User type stays untouched (admin-only payload widening).
type OrgScopedUser = User & {
  organization_id?: number | null;
  is_super_admin?: boolean;
  is_organization_super_admin?: boolean;
};

interface AuthState {
  user: (OrgScopedUser & { roles: string[] }) | null;
  isLoading: boolean;
  isAuthenticated: boolean;
}

const authState: { current: AuthState } = {
  current: {
    user: {
      id: 42,
      name: 'Org Super',
      email: 'orgsuper@example.test',
      department_id: null,
      phone: null,
      extension: null,
      job_title: null,
      is_active: true,
      roles: ['organization_super_admin'],
      organization_id: 5,
      is_super_admin: false,
      is_organization_super_admin: true,
    },
    isLoading: false,
    isAuthenticated: true,
  },
};

vi.mock('@shared/contexts/AuthContext', () => ({
  AuthProvider: ({ children }: { children: React.ReactNode }) => children,
  useAuth: () => ({
    ...authState.current,
    logout: vi.fn(),
    refreshUser: vi.fn(),
    can: () => true,
  }),
}));

vi.mock('@shared/contexts/LocaleContext', () => ({
  useLocale: () => ({ locale: 'ar', direction: 'rtl', setLocale: vi.fn() }),
}));

vi.mock('@shared/contexts/ThemeContext', () => ({
  useTheme: () => ({ resolvedTheme: 'light', toggleTheme: vi.fn() }),
}));

vi.mock('@shared/contexts/SystemSettingsContext', () => ({
  useSystemSettings: () => ({ settings: { name: 'Erada Platform', name_en: 'Erada' } }),
}));

vi.mock('@shared/ui/Toast', () => {
  const addToast = vi.fn();
  return {
    ToastProvider: ({ children }: { children: React.ReactNode }) => children,
    useToast: () => ({ addToast, removeToast: vi.fn(), toasts: [] }),
  };
});

interface CapturedCall {
  url: string;
  method: string;
  headers: Record<string, string>;
  body: unknown;
}

const fetchMock = vi.fn();
let lastCall: CapturedCall | null = null;
let pendingResolver: ((response: Response) => void) | null = null;

function setFetchJsonResponse(body: unknown, status = 200) {
  fetchMock.mockImplementationOnce(async (input: string | URL, init?: RequestInit) => {
    const url = typeof input === 'string' ? input : (input as URL).toString();
    const headers = normaliseHeaders(init?.headers);
    lastCall = {
      url,
      method: String(init?.method ?? 'GET'),
      headers,
      body: parseJsonBody(init?.body),
    };
    return new Response(JSON.stringify({ data: body }), {
      status,
      headers: { 'Content-Type': 'application/json' },
    });
  });
}

/**
 * Install a fetch mock that records the call but does not resolve the
 * returned Promise until `releasePendingFetch()` is called. This lets a
 * test observe a transient `saving` state (aria-busy, disabled submit)
 * before the simulated PUT commits and React renders the saved badge.
 */
function setPendingFetch() {
  fetchMock.mockImplementationOnce(async (input: string | URL, init?: RequestInit) => {
    const url = typeof input === 'string' ? input : (input as URL).toString();
    const headers = normaliseHeaders(init?.headers);
    lastCall = {
      url,
      method: String(init?.method ?? 'GET'),
      headers,
      body: parseJsonBody(init?.body),
    };
    return new Promise<Response>((resolve) => {
      pendingResolver = resolve;
    });
  });
}

function releasePendingFetch(body: unknown, status = 200) {
  const resolve = pendingResolver;
  pendingResolver = null;
  if (!resolve) return;
  resolve(
    new Response(JSON.stringify({ data: body }), {
      status,
      headers: { 'Content-Type': 'application/json' },
    }),
  );
}

function setFetchError(message = 'network down') {
  fetchMock.mockImplementationOnce(async () => {
    throw new Error(message);
  });
}

function normaliseHeaders(raw: HeadersInit | undefined): Record<string, string> {
  const result: Record<string, string> = {};
  if (!raw) return result;
  if (raw instanceof Headers) {
    raw.forEach((value, key) => {
      result[key] = value;
    });
  } else if (Array.isArray(raw)) {
    for (const [key, value] of raw) {
      result[key] = String(value);
    }
  } else {
    Object.assign(result, raw as Record<string, string>);
  }
  return result;
}

function parseJsonBody(body: unknown): unknown {
  if (typeof body !== 'string') return undefined;
  try {
    return JSON.parse(body);
  } catch {
    return body;
  }
}

function renderPage(initialEntry: string = '/settings') {
  return render(
    <MemoryRouter initialEntries={[initialEntry]}>
      <Routes>
        <Route path="/settings" element={<OrganizationSettingsPage />} />
      </Routes>
    </MemoryRouter>,
  );
}

describe('OrganizationSuperAdmin settings page', () => {
  beforeEach(() => {
    // Reset the mutable auth fixture to the canonical OrgSuper actor so
    // each test starts from a known baseline regardless of what the
    // previous test mutated onto `authState.current`.
    authState.current = {
      user: {
        id: 42,
        name: 'Org Super',
        email: 'orgsuper@example.test',
        department_id: null,
        phone: null,
        extension: null,
        job_title: null,
        is_active: true,
        roles: ['organization_super_admin'],
        organization_id: 5,
        is_super_admin: false,
        is_organization_super_admin: true,
      },
      isLoading: false,
      isAuthenticated: true,
    };

    vi.stubGlobal('fetch', fetchMock);
    fetchMock.mockReset();
    lastCall = null;
    setFetchJsonResponse({
      locale_overrides: {},
      branding_overrides: {},
      notification_templates: {},
    });
    // Make sure no localStorage-driven `X-Organization-Id` pollutes calls.
    try {
      window.localStorage.removeItem('iradah:current_organization_id');
    } catch {
      // localStorage can be unavailable in jsdom edge cases; ignore.
    }
  });

  afterEach(() => {
    vi.unstubAllGlobals();
    vi.clearAllMocks();
  });

  it('targets GET /api/organizations/{actor.organization_id}/settings and omits X-Organization-Id', async () => {
    renderPage();

    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));

    expect(lastCall).not.toBeNull();
    expect(lastCall!.method).toBe('GET');
    expect(lastCall!.url).toBe('/api/organizations/5/settings');
    expect(lastCall!.headers['X-Organization-Id']).toBeUndefined();
    expect(lastCall!.headers['Accept']).toBe('application/json');
  });

  it('renders loading chrome while the initial GET is pending', () => {
    // Never resolve the GET so we stay in the loading state.
    fetchMock.mockImplementation(() => new Promise<Response>(() => undefined));

    renderPage();

    expect(screen.getByRole('status')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: i18n.t('admin.orgSettings.save') })).not.toBeInTheDocument();
  });

  it('renders the empty state when the GET returns an empty payload', async () => {
    setFetchJsonResponse({
      locale_overrides: {},
      branding_overrides: {},
      notification_templates: {},
    });

    renderPage();

    // The empty copy must be in the DOM, and the form fields must be
    // present but empty (no fallback to "no settings" alert).
    expect(
      await screen.findByText(i18n.t('admin.orgSettings.emptyTitle')),
    ).toBeInTheDocument();
    const colorInput = await screen.findByLabelText(
      i18n.t('admin.orgSettings.fields.primaryColor'),
    );
    expect(colorInput).toHaveValue('');
  });

  it('renders the error state when the GET fails and exposes retry', async () => {
    const user = userEvent.setup();
    fetchMock.mockReset();
    setFetchError('network down');

    renderPage();

    expect(await screen.findByRole('alert')).toHaveTextContent(/network down/i);

    // Retry path: the next GET must hit the same canonical URL.
    fetchMock.mockReset();
    setFetchJsonResponse({
      locale_overrides: {},
      branding_overrides: {},
      notification_templates: {},
    });
    await user.click(screen.getByRole('button', { name: i18n.t('admin.orgSettings.retry') }));

    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
    expect(lastCall!.url).toBe('/api/organizations/5/settings');
  });

  it('sends PUT with X-Idempotency-Key and only the edited overrides', async () => {
    const user = userEvent.setup();
    renderPage();

    const colorInput = await screen.findByLabelText(
      i18n.t('admin.orgSettings.fields.primaryColor'),
    );
    const logoInput = screen.getByLabelText(i18n.t('admin.orgSettings.fields.logoPath'));

    await user.clear(colorInput);
    await user.type(colorInput, '#1f3a8a');
    await user.type(logoInput, '/branding/north.svg');

    // Hold the PUT open so we can observe the saving state on the form
    // before the response commits and React re-renders the saved badge.
    fetchMock.mockReset();
    setPendingFetch();

    const save = await screen.findByRole('button', {
      name: i18n.t('admin.orgSettings.save'),
    });
    await user.click(save);

    const form = document.querySelector('form');
    expect(form).not.toBeNull();
    await waitFor(() => expect(form!).toHaveAttribute('aria-busy', 'true'));

    // Now release the PUT and let React settle into the saved state.
    releasePendingFetch({
      locale_overrides: {},
      branding_overrides: { primary_color: '#1f3a8a', logo_path: '/branding/north.svg' },
      notification_templates: {},
    });

    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
    expect(lastCall!.method).toBe('PUT');
    expect(lastCall!.url).toBe('/api/organizations/5/settings');

    const idempotency = lastCall!.headers['X-Idempotency-Key'];
    expect(typeof idempotency).toBe('string');
    expect((idempotency as string).length).toBeGreaterThanOrEqual(8);

    // Only the edited key is sent. Notification templates + locale overrides
    // were not touched, so the PUT stays narrow.
    expect(lastCall!.body).toEqual({
      branding_overrides: { primary_color: '#1f3a8a', logo_path: '/branding/north.svg' },
    });
    expect(lastCall!.headers['X-Organization-Id']).toBeUndefined();

    // Saved state: success alert visible, button re-enabled.
    expect(await screen.findByText(i18n.t('admin.orgSettings.saved'))).toBeInTheDocument();
  });

  it('mints a fresh X-Idempotency-Key per save attempt', async () => {
    const user = userEvent.setup();
    renderPage();
    const colorInput = await screen.findByLabelText(
      i18n.t('admin.orgSettings.fields.primaryColor'),
    );

    await user.clear(colorInput);
    await user.type(colorInput, '#000001');

    fetchMock.mockReset();
    setFetchJsonResponse({
      locale_overrides: {},
      branding_overrides: { primary_color: '#000001', logo_path: null },
      notification_templates: {},
    });
    await user.click(
      await screen.findByRole('button', { name: i18n.t('admin.orgSettings.save') }),
    );
    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
    const firstKey = lastCall!.headers['X-Idempotency-Key'];

    // Edit again — the next PUT must mint a fresh key, never replay.
    await screen.findByText(i18n.t('admin.orgSettings.saved'));
    await user.clear(colorInput);
    await user.type(colorInput, '#000002');
    fetchMock.mockReset();
    setFetchJsonResponse({
      locale_overrides: {},
      branding_overrides: { primary_color: '#000002', logo_path: null },
      notification_templates: {},
    });
    await user.click(screen.getByRole('button', { name: i18n.t('admin.orgSettings.save') }));
    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
    const secondKey = lastCall!.headers['X-Idempotency-Key'];

    expect(typeof firstKey).toBe('string');
    expect(typeof secondKey).toBe('string');
    expect(firstKey).not.toEqual(secondKey);
  });

  it('reaches the page for a platform super admin via ?organization=<id>', async () => {
    authState.current = {
      user: {
        id: 1,
        name: 'Platform Admin',
        email: 'platform@example.test',
        department_id: null,
        phone: null,
        extension: null,
        job_title: null,
        is_active: true,
        roles: ['super_admin'],
        organization_id: null,
        is_super_admin: true,
        is_organization_super_admin: false,
      },
      isLoading: false,
      isAuthenticated: true,
    };

    renderPage('/settings?organization=99');

    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
    expect(lastCall!.url).toBe('/api/organizations/99/settings');
    expect(lastCall!.headers['X-Organization-Id']).toBeUndefined();
  });

  it('blocks an OrgSuper actor from crossing tenants when actor.organization_id conflicts with ?organization', async () => {
    // OrgSuper must always operate on their own tenant. A mismatch with
    // a query-string override renders the empty/no-target state and never
    // hits the network on the wrong tenant.
    renderPage('/settings?organization=999');

    // The page surfaces the OrgSuper no-target copy. Match on a portion
    // of the body that is unique to the OrgSuper branch.
    const copy = await screen.findByText(/حسابك مرتبط/i);
    expect(copy).toBeInTheDocument();
    expect(fetchMock).not.toHaveBeenCalled();
  });
});
