import React from 'react';
import userEvent from '@testing-library/user-event';
import { render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { User } from '@shared/types';
import i18n from '@shared/config/i18n';
import { api } from '@shared/api/client';
import { AdminRouter } from '@admin/app/AdminRouter';
import { adminApi } from '@admin/api/adminApi';

const authState = {
  user: {
    id: 1,
    name: 'Control Admin',
    email: 'admin@example.test',
    department_id: null,
    phone: null,
    extension: null,
    job_title: null,
    is_active: true,
    roles: ['super_admin'],
  } satisfies User,
  isLoading: false,
  isAuthenticated: true,
};

vi.mock('@shared/contexts/AuthContext', () => ({
  AuthProvider: ({ children }: { children: React.ReactNode }) => children,
  useAuth: () => ({ ...authState, logout: vi.fn(), refreshUser: vi.fn() }),
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
vi.mock('@shared/api/client', () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn(), blob: vi.fn() },
}));

const apiGet = vi.mocked(api.get);
const apiBlob = vi.mocked(api.blob);

function setPath(path: string) {
  window.history.replaceState({ usr: null, key: 'activity-test', idx: 0 }, '', path);
}

describe('admin activity and audit contracts', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    document.documentElement.dir = 'rtl';
  });

  it('uses canonical activity, scoped-audit, and scope-type adapters', async () => {
    apiGet.mockResolvedValue({ data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } });
    apiBlob.mockResolvedValue(new Blob());

    await adminApi.activityLogs.list({ action: 'updated', page: 2 });
    await adminApi.activityLogs.export('csv', { action: 'updated' });
    await adminApi.scopedRoleAudit.list({ user_id: 9, page: 3 });
    await adminApi.scopeTypes.list({ search: 'department' });

    expect(apiGet).toHaveBeenNthCalledWith(1, '/admin/activity-logs?action=updated&page=2');
    expect(apiBlob).toHaveBeenCalledWith('/admin/activity-logs/export?format=csv&action=updated');
    expect(apiGet).toHaveBeenNthCalledWith(2, '/admin/scoped-roles/audit-logs?user_id=9&page=3');
    expect(apiGet).toHaveBeenNthCalledWith(3, '/admin/scope-types?search=department');
  });

  it('applies activity filters and reports export authorization failures', async () => {
    apiGet.mockResolvedValue({
      data: [{
        id: 22,
        user: { id: 1, name: 'Control Admin' },
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
    apiBlob.mockRejectedValue({ status: 403, message: 'Export forbidden' });
    const user = userEvent.setup();
    setPath('/activity-logs');

    render(<AdminRouter />);

    expect(await screen.findByText('Updated governance rule')).toBeInTheDocument();
    await user.type(screen.getByPlaceholderText(i18n.t('admin.activityLogs.searchPlaceholder')), 'governance');
    await user.selectOptions(screen.getByLabelText(i18n.t('admin.activityLogs.fields.action')), 'updated');
    await user.click(screen.getByRole('button', { name: i18n.t('common.search') }));

    await waitFor(() => expect(apiGet).toHaveBeenLastCalledWith('/admin/activity-logs?search=governance&action=updated&page=1&per_page=25'));
    await user.click(screen.getByRole('button', { name: /CSV/ }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Export forbidden');
  });

  it('downloads a successful activity export and releases the blob URL', async () => {
    apiGet.mockResolvedValue({ data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } });
    const blob = new Blob(['audit'], { type: 'text/csv' });
    apiBlob.mockResolvedValue(blob);
    const createObjectURL = vi.fn(() => 'blob:activity-export');
    const revokeObjectURL = vi.fn();
    Object.defineProperty(URL, 'createObjectURL', { configurable: true, value: createObjectURL });
    Object.defineProperty(URL, 'revokeObjectURL', { configurable: true, value: revokeObjectURL });
    let downloadedFilename = '';
    const click = vi.spyOn(window.HTMLAnchorElement.prototype, 'click').mockImplementation(function () {
      downloadedFilename = this.download;
    });
    const user = userEvent.setup();
    setPath('/activity-logs');

    render(<AdminRouter />);

    await screen.findByText(i18n.t('admin.activityLogs.empty'));
    await user.click(screen.getByRole('button', { name: 'CSV' }));

    await waitFor(() => expect(apiBlob).toHaveBeenCalledWith('/admin/activity-logs/export?format=csv'));
    expect(createObjectURL).toHaveBeenCalledWith(blob);
    expect(click).toHaveBeenCalledOnce();
    expect(downloadedFilename).toMatch(/^activity-log-\d{4}-\d{2}-\d{2}\.csv$/);
    expect(revokeObjectURL).toHaveBeenCalledWith('blob:activity-export');
    click.mockRestore();
  });

  it('filters scoped-role audit and paginates from backend metadata', async () => {
    apiGet
      .mockResolvedValueOnce({
        data: [{
          id: 44,
          user: { id: 1, name: 'Control Admin' },
          action: 'role_assigned',
          description: 'Assigned reviewer',
          target_user: { id: 9, name: 'Audit User' },
          scope_type: 'department',
          scope_id: 4,
          role: 'risk_reviewer',
          ip_address: '10.0.0.0/24',
          created_at: '2026-07-12T10:00:00+03:00',
        }],
        meta: { current_page: 1, last_page: 3, per_page: 25, total: 51 },
      })
      .mockResolvedValueOnce({
        data: [],
        meta: { current_page: 2, last_page: 3, per_page: 25, total: 51 },
      });
    const user = userEvent.setup();
    setPath('/scoped-roles/audit-logs');

    render(<AdminRouter />);

    expect(await screen.findByText('Assigned reviewer')).toBeInTheDocument();
    await user.type(screen.getByLabelText(i18n.t('admin.scopedRolesAudit.filters.userId')), '9');
    await user.selectOptions(screen.getByLabelText(i18n.t('admin.scopedRolesAudit.fields.action')), 'role_assigned');
    await user.click(screen.getByRole('button', { name: i18n.t('common.search') }));
    expect(apiGet).toHaveBeenLastCalledWith('/admin/scoped-roles/audit-logs?action=role_assigned&user_id=9&page=1&per_page=25');

    await user.click(screen.getByRole('button', { name: i18n.t('common.next') }));
    await waitFor(() => expect(apiGet).toHaveBeenLastCalledWith('/admin/scoped-roles/audit-logs?action=role_assigned&user_id=9&page=2&per_page=25'));
  });

  it('renders scope types as read-only with no phantom create action', async () => {
    apiGet.mockResolvedValue({
      data: [{ id: 2, key: 'department', label_ar: 'Department', label_en: 'Department', icon: null, color: null, sort_order: 2, is_active: true }],
      meta: { current_page: 1, last_page: 1, per_page: 50, total: 1 },
    });
    setPath('/scope-types');

    render(<AdminRouter />);

    expect(await screen.findByText('department')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: i18n.t('admin.scopeTypes.add') })).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: i18n.t('admin.scopeTypes.add') })).not.toBeInTheDocument();
  });
});
