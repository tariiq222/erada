import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { User } from '@shared/types';
import i18n from '@shared/config/i18n';
import { api } from '@shared/api/client';
import { AdminRouter } from '@admin/app/AdminRouter';

const authState: { user: User | null } = { user: null };

vi.mock('@shared/contexts/AuthContext', () => ({
  AuthProvider: ({ children }: { children: React.ReactNode }) => children,
  useAuth: () => ({ ...authState, isLoading: false, isAuthenticated: authState.user !== null, logout: vi.fn(), refreshUser: vi.fn() }),
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
vi.mock('@shared/ui/Toast', () => ({ ToastProvider: ({ children }: { children: React.ReactNode }) => children }));
vi.mock('@shared/api/client', () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn(), blob: vi.fn() },
}));

const apiGet = vi.mocked(api.get);
const apiBlob = vi.mocked(api.blob);

function buildActor(overrides: Partial<User> = {}) {
  return {
    id: 7,
    name: 'Org Super',
    email: 'orgsuper@example.test',
    department_id: null,
    phone: null,
    extension: null,
    job_title: null,
    is_active: true,
    is_super_admin: false,
    is_org_admin: false,
    is_organization_super_admin: true,
    organization_id: 42,
    ...overrides,
  } as unknown as User;
}

function setPath(path: string) {
  window.history.replaceState({ usr: null, key: 'activity-orgsuper-test', idx: 0 }, '', path);
}

describe('OrgSuper activity-logs slice', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    document.documentElement.dir = 'rtl';
    authState.user = buildActor();
  });

  it('hides CSV/JSON export buttons when the actor is OrgSuper', async () => {
    apiGet.mockResolvedValue({
      data: [{
        id: 22,
        user: { id: 1, name: 'Audit User' },
        action: 'updated',
        description: 'Updated governance rule',
        loggable_type: 'GovernanceRule',
        loggable_id: 5,
        ip_address: '10.0.0.0/24',
        user_agent: 'Chrome',
        created_at: '2026-07-12T10:00:00+03:00',
        updated_at: '2026-07-12T10:00:00+03:00',
      }],
      meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
    });
    setPath('/activity-logs');

    render(<AdminRouter />);

    expect(await screen.findByText('Updated governance rule')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'CSV' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'JSON' })).not.toBeInTheDocument();
  });

  it('never calls the platform audit endpoint from an OrgSuper session', async () => {
    apiGet.mockResolvedValue({
      data: [],
      meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 },
    });
    setPath('/activity-logs');

    render(<AdminRouter />);

    await screen.findByText(i18n.t('admin.activityLogs.empty'));

    const platformAuditCalls = apiGet.mock.calls
      .map(([path]) => path)
      .filter((path): path is string => typeof path === 'string')
      .filter((path) => path.includes('/admin/audit/recent'));
    expect(platformAuditCalls).toEqual([]);

    // Only the actor-scoped activity-logs endpoint must be exercised.
    expect(apiGet).toHaveBeenCalledWith('/activity-logs?page=1&per_page=25');
    expect(apiBlob).not.toHaveBeenCalled();
  });

  it('preserves the super-admin export surface', async () => {
    authState.user = buildActor({ is_super_admin: true, is_organization_super_admin: false });
    apiGet.mockResolvedValue({
      data: [],
      meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 },
    });
    setPath('/activity-logs');

    render(<AdminRouter />);

    expect(await screen.findByRole('button', { name: 'CSV' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'JSON' })).toBeInTheDocument();
  });

  it('blocks the export adapter at the API layer when called from a non-super actor (defense in depth)', async () => {
    // The component hides the buttons, but a programmatic caller could still
    // hit adminApi.activityLogs.export. The component guards that path
    // through `showExport` before calling the adapter; verify the component
    // does not invoke the adapter from an OrgSuper session even when the
    // filter form is submitted repeatedly.
    apiGet.mockResolvedValue({
      data: [],
      meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 },
    });
    setPath('/activity-logs');

    render(<AdminRouter />);

    const search = await screen.findByPlaceholderText(i18n.t('admin.activityLogs.searchPlaceholder'));
    const user = userEvent.setup();
    await user.type(search, 'governance');
    await user.click(screen.getByRole('button', { name: i18n.t('common.search') }));

    await waitFor(() =>
      expect(apiGet).toHaveBeenLastCalledWith('/activity-logs?search=governance&page=1&per_page=25'),
    );
    expect(apiBlob).not.toHaveBeenCalled();
  });

  it('keeps the OrgSuper list path on the actor-scoped endpoint even with filters', async () => {
    apiGet.mockResolvedValue({
      data: [],
      meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 },
    });
    setPath('/activity-logs');

    render(<AdminRouter />);

    const user = userEvent.setup();
    await user.selectOptions(screen.getByLabelText(i18n.t('admin.activityLogs.fields.action')), 'updated');
    await user.click(screen.getByRole('button', { name: i18n.t('common.search') }));

    await waitFor(() =>
      expect(apiGet).toHaveBeenLastCalledWith('/activity-logs?action=updated&page=1&per_page=25'),
    );
    const allCalls = apiGet.mock.calls.map(([path]) => path).filter((p): p is string => typeof p === 'string');
    expect(allCalls.every((path) => !path.includes('/admin/audit/recent'))).toBe(true);
  });
});
