/**
 * Admin organization-settings integration tests.
 *
 * Contract under test (drives `OrganizationSettingsPage`,
 * `adminApi.organizationSettings`, and the actor/auth-guard gate):
 *
 *  - GET  /api/organizations/{organizationId}/settings/    → { data: payload }
 *      first read on mount, loading→rendered transition.
 *  - PUT  /api/organizations/{organizationId}/settings/
 *      body = partial {locale_overrides?, branding_overrides?,
 *                      notification_templates?}
 *      header = Idempotency-Key: <uuid per submit>
 *      No `X-Organization-Id` header on either verb (org is path-bound).
 *  - Authorization gate renders the page only when the actor is
 *      super_admin OR actor.user.organization_id === path :organizationId.
 *  - States on the GET path:
 *      loading → first paint shows the loading string;
 *      error   → GET reject renders a danger Alert;
 *      success → GET resolve renders the three field sections.
 *  - States on the PUT path:
 *      saving      → submit button disabled, second submit is a no-op;
 *      success     → previous + new merged payload is rendered, success
 *                    Alert visible until the form is dirtied;
 *      error       → PUT reject renders a danger Alert with the backend
 *                    message and leaves the form dirty (so the user can
 *                    retry without re-typing).
 *
 * The test mocks `global.fetch` (the page uses a scoped fetch helper
 * rather than the shared `api` client, because the shared client
 * unconditionally injects `X-Organization-Id` from localStorage —
 * the settings endpoint MUST NOT carry that header; the org is bound
 * to the URL path).
 */

import React from 'react';
import userEvent from '@testing-library/user-event';
import { render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { User } from '@shared/types';
import i18n from '@shared/config/i18n';
import { adminApi } from '@admin/api/adminApi';

// Single hoisted mutable auth state. The hoisted object lives outside
// the test closures so each test can swap `currentActor` and every
// subsequent `useAuth()` call reads the latest value.
const authMockState: { currentActor: User | null } = { currentActor: null };

vi.mock('@shared/contexts/AuthContext', () => ({
  AuthProvider: ({ children }: { children: React.ReactNode }) => children,
  useAuth: () => ({
    user: authMockState.currentActor,
    isLoading: false,
    isAuthenticated: authMockState.currentActor !== null,
    logout: vi.fn(),
    refreshUser: vi.fn(),
  }),
}));

vi.mock('@shared/contexts/LocaleContext', () => ({
  LocaleProvider: ({ children }: { children: React.ReactNode }) => children,
  useLocale: () => ({ locale: 'ar', direction: 'rtl', setLocale: vi.fn() }),
}));
vi.mock('@shared/contexts/ThemeContext', () => ({
  ThemeProvider: ({ children }: { children: React.ReactNode }) => children,
  useTheme: () => ({ resolvedTheme: 'light', toggleTheme: vi.fn() }),
}));
vi.mock('@shared/contexts/SystemSettingsContext', () => ({
  SystemSettingsProvider: ({ children }: { children: React.ReactNode }) => children,
  useSystemSettings: () => ({ settings: { name: 'Erada Platform', name_en: 'Erada' } }),
}));
vi.mock('@shared/ui/Toast', () => ({
  ToastProvider: ({ children }: { children: React.ReactNode }) => children,
}));

const superAdmin: User = {
  id: 1,
  name: 'Control Admin',
  email: 'admin@example.test',
  department_id: null,
  organization_id: 7,
  phone: null,
  extension: null,
  job_title: null,
  is_active: true,
  is_super_admin: true,
  is_org_admin: false,
};

const orgAdminActorSameOrg: User = {
  id: 2,
  name: 'Org Steward',
  email: 'steward@example.test',
  department_id: null,
  organization_id: 7,
  phone: null,
  extension: null,
  job_title: null,
  is_active: true,
  is_super_admin: false,
  is_org_admin: true,
};

const orgAdminActorWrongOrg: User = {
  ...orgAdminActorSameOrg,
  organization_id: 99,
};

function setPath(path: string) {
  window.history.replaceState({ usr: null, key: 'org-settings-test', idx: 0 }, '', path);
}

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  });
}

async function loadAdminModule() {
  const mod = await import('@admin/app/AdminRouter');
  return mod.AdminRouter;
}

describe('admin organization settings integration', () => {
  let fetchSpy: ReturnType<typeof vi.spyOn>;

  beforeEach(() => {
    document.documentElement.dir = 'rtl';
    authMockState.currentActor = superAdmin;
    fetchSpy = vi.spyOn(globalThis, 'fetch').mockImplementation(() => Promise.resolve(jsonResponse({
      data: {
        locale_overrides: {},
        branding_overrides: {},
        notification_templates: {},
      },
    })));
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('adapters hit the canonical path-bound endpoints and never carry X-Organization-Id', async () => {
    authMockState.currentActor = superAdmin;
    fetchSpy
      .mockResolvedValueOnce(jsonResponse({
        data: {
          locale_overrides: {},
          branding_overrides: {},
          notification_templates: {},
        },
      }))
      .mockResolvedValueOnce(jsonResponse({
        data: {
          locale_overrides: { ar: 'ar-SA' },
          branding_overrides: {},
          notification_templates: {},
        },
      }));

    await adminApi.organizationSettings.get(7);
    await adminApi.organizationSettings.update(7, {
      locale_overrides: { ar: 'ar-SA' },
    }, { idempotencyKey: 'idem-org-7-ar-sa' });

    expect(fetchSpy).toHaveBeenNthCalledWith(
      1,
      '/api/organizations/7/settings/',
      expect.objectContaining({
        method: 'GET',
        credentials: 'include',
      }),
    );
    expect(fetchSpy).toHaveBeenNthCalledWith(
      2,
      '/api/organizations/7/settings/',
      expect.objectContaining({
        method: 'PUT',
        credentials: 'include',
        body: JSON.stringify({ locale_overrides: { ar: 'ar-SA' } }),
        headers: expect.objectContaining({
          'Content-Type': 'application/json',
          'Idempotency-Key': 'idem-org-7-ar-sa',
          'Accept': 'application/json',
        }),
      }),
    );
    // Explicit guarantee: NO X-Organization-Id on either call.
    for (const call of fetchSpy.mock.calls) {
      const [, init] = call as [string, RequestInit];
      const headerBag = init?.headers as Record<string, string> | undefined;
      expect(headerBag?.['X-Organization-Id']).toBeUndefined();
      expect(headerBag?.['x-organization-id']).toBeUndefined();
    }
  });

  it('renders the three settings sections with hydrated values on a successful GET', async () => {
    authMockState.currentActor = superAdmin;
    fetchSpy.mockResolvedValue(
      jsonResponse({
        data: {
          locale_overrides: { ar: 'ar-SA', en: 'en-US' },
          branding_overrides: { primary_color: '#1F7A8C', logo_path: null },
          notification_templates: { kpi_alert: 'A new KPI alert is in.' },
        },
      }),
    );

    setPath('/organizations/7/settings');
    const AdminRouter = await loadAdminModule();
    render(<AdminRouter />);

    expect(await screen.findByLabelText(i18n.t('admin.organizationSettings.fields.localeOverridesAr'))).toHaveValue('ar-SA');
    expect(screen.getByLabelText(i18n.t('admin.organizationSettings.fields.localeOverridesEn'))).toHaveValue('en-US');
    expect(screen.getByLabelText(i18n.t('admin.organizationSettings.fields.brandingOverridesPrimaryColor'))).toHaveValue('#1F7A8C');
    expect(screen.getByLabelText(i18n.t('admin.organizationSettings.fields.notificationTemplateKpiAlert'))).toHaveValue('A new KPI alert is in.');
    expect(fetchSpy).toHaveBeenCalledWith(
      '/api/organizations/7/settings/',
      expect.objectContaining({ method: 'GET' }),
    );
  });

  it('renders a danger Alert when the GET fails and surfaces a retry control', async () => {
    authMockState.currentActor = superAdmin;
    fetchSpy.mockResolvedValue(
      jsonResponse({ message: 'Unable to read settings' }, 503),
    );

    setPath('/organizations/7/settings');
    const AdminRouter = await loadAdminModule();
    const user = userEvent.setup();
    render(<AdminRouter />);

    expect(await screen.findByRole('alert')).toHaveTextContent('Unable to read settings');
    // Reset spy with a successful payload and confirm retry resolves.
    fetchSpy.mockResolvedValueOnce(jsonResponse({
      data: {
        locale_overrides: {},
        branding_overrides: {},
        notification_templates: {},
      },
    }));
    await user.click(screen.getByRole('button', { name: i18n.t('common.retry') }));
    expect(await screen.findByLabelText(i18n.t('admin.organizationSettings.fields.localeOverridesAr'))).toBeInTheDocument();
  });

  it('serializes a partial PUT with a fresh Idempotency-Key and renders the merged payload on success', async () => {
    authMockState.currentActor = superAdmin;
    fetchSpy
      // 1) GET on mount
      .mockResolvedValueOnce(jsonResponse({
        data: {
          locale_overrides: { ar: 'ar-SA' },
          branding_overrides: {},
          notification_templates: { kpi_alert: 'Old KPI alert.' },
        },
      }))
      // 2) PUT
      .mockResolvedValueOnce(jsonResponse({
        data: {
          locale_overrides: { ar: 'ar-SA', en: 'en-US' },
          branding_overrides: {},
          notification_templates: { kpi_alert: 'New alert copy for KPI dips.' },
        },
      }));

    setPath('/organizations/7/settings');
    const AdminRouter = await loadAdminModule();
    const user = userEvent.setup();
    render(<AdminRouter />);

    // Wait for hydrated form.
    await screen.findByLabelText(i18n.t('admin.organizationSettings.fields.localeOverridesEn'));

    await user.type(
      screen.getByLabelText(i18n.t('admin.organizationSettings.fields.localeOverridesEn')),
      'en-US',
    );
    await user.clear(screen.getByLabelText(i18n.t('admin.organizationSettings.fields.notificationTemplateKpiAlert')));
    await user.type(
      screen.getByLabelText(i18n.t('admin.organizationSettings.fields.notificationTemplateKpiAlert')),
      'New alert copy for KPI dips.',
    );

    const submit = screen.getByRole('button', { name: i18n.t('admin.organizationSettings.actions.save') });
    await user.click(submit);

    await waitFor(() => expect(fetchSpy).toHaveBeenCalledTimes(2));

    const putCall = fetchSpy.mock.calls[1];
    expect(putCall[0]).toBe('/api/organizations/7/settings/');
    const putInit = putCall[1] as RequestInit;
    const putHeaders = putInit.headers as Record<string, string>;
    expect(putHeaders['Idempotency-Key']).toMatch(/^[0-9a-f-]{8,}$/);
    expect(putInit.body).toBe(JSON.stringify({
      locale_overrides: { en: 'en-US' },
      notification_templates: { kpi_alert: 'New alert copy for KPI dips.' },
    }));

    // Success alert visible after PUT resolves.
    expect(await screen.findByRole('alert')).toHaveTextContent(/Settings for .* were updated\.|تم تحديث إعدادات/);
    // The fields reflect the merged payload returned by the server.
    expect(screen.getByLabelText(i18n.t('admin.organizationSettings.fields.notificationTemplateKpiAlert'))).toHaveValue('New alert copy for KPI dips.');
    expect(screen.getByLabelText(i18n.t('admin.organizationSettings.fields.localeOverridesEn'))).toHaveValue('en-US');
  });

  it('blocks when actor is neither super_admin nor in target organization (path mismatch)', async () => {
    authMockState.currentActor = orgAdminActorWrongOrg;

    setPath('/organizations/7/settings');
    const AdminRouter = await loadAdminModule();
    render(<AdminRouter />);

    // The page itself guards rendering and surfaces the Forbidden screen.
    expect(await screen.findByText(i18n.t('ovr.api.access_denied'))).toBeInTheDocument();
    expect(fetchSpy).not.toHaveBeenCalled();
  });

  it('shows validation errors from the backend and keeps form values dirty for retry', async () => {
    authMockState.currentActor = superAdmin;
    fetchSpy
      .mockResolvedValueOnce(jsonResponse({
        data: {
          locale_overrides: {},
          branding_overrides: {},
          notification_templates: {},
        },
      }))
      .mockResolvedValueOnce(
        jsonResponse(
          {
            message: 'Validation failed',
            errors: { 'branding_overrides.primary_color': ['Must be #RRGGBB.'] },
          },
          422,
        ),
      );

    setPath('/organizations/7/settings');
    const AdminRouter = await loadAdminModule();
    const user = userEvent.setup();
    render(<AdminRouter />);

    await screen.findByLabelText(i18n.t('admin.organizationSettings.fields.brandingOverridesPrimaryColor'));
    await user.type(
      screen.getByLabelText(i18n.t('admin.organizationSettings.fields.brandingOverridesPrimaryColor')),
      '#ABC',
    );

    await user.click(screen.getByRole('button', { name: i18n.t('admin.organizationSettings.actions.save') }));

    expect(await screen.findByRole('alert')).toHaveTextContent(/Must be #RRGGBB\.|يجب أن يكون/);
    // Submit click did NOT clear the dirty value.
    expect(screen.getByLabelText(i18n.t('admin.organizationSettings.fields.brandingOverridesPrimaryColor'))).toHaveValue('#ABC');
    expect(fetchSpy).toHaveBeenCalledTimes(2);
  });
});
