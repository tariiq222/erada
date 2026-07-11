import React from 'react';
import userEvent from '@testing-library/user-event';
import { render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import i18n from '@shared/config/i18n';
import { api } from '@shared/api/client';
import { adminApi } from '@admin/api/adminApi';
import { Overview } from '@admin/pages/overview/Overview';
import { SecurityAlerts } from '@admin/pages/security-alerts/SecurityAlerts';
import { AuditRecent } from '@admin/pages/audit-recent/AuditRecent';

vi.mock('@shared/api/client', () => ({
  api: { get: vi.fn() },
}));

const apiGet = vi.mocked(api.get);

const overviewResponse = {
  data: {
    organizations: { active: 8, total: 10 },
    users: {
      active: 24,
      total: 30,
      two_factor_coverage: { enabled: 18, active_users: 24, percent: 75 },
    },
    login_attempts: {
      last_24h: { successful: 44, failed: 3, total: 47 },
    },
    generated_at: '2026-07-12T09:30:00+03:00',
  },
};

const emptySecurityResponse = {
  data: {
    windows: {
      minutes: 60,
      cutoff: '2026-07-12T08:30:00+03:00',
      repeated_failure_threshold: 3,
    },
    failed_logins_repeated: [],
    access_denied_events: [],
    generated_at: '2026-07-12T09:30:00+03:00',
  },
};

const auditResponse = {
  data: [
    {
      id: 71,
      action: 'role_assigned',
      description: 'Role assigned',
      actor: { id: 4, name: 'Governance Admin' },
      target_user: { id: 9, name: 'Target User' },
      scope_type: 'organization',
      scope_id: 2,
      role: 'organization_admin',
      ip_address: '127.0.0.1',
      created_at: '2026-07-12T09:20:00+03:00',
    },
  ],
  meta: {
    current_page: 1,
    last_page: 3,
    per_page: 1,
    total: 3,
    limit: 50,
    returned: 1,
  },
};

function deferred<T>() {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>((done) => {
    resolve = done;
  });
  return { promise, resolve };
}

function renderPage(page: React.ReactElement) {
  return render(<MemoryRouter>{page}</MemoryRouter>);
}

describe('admin-owned governance API', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('uses only canonical admin paths through the shared client', async () => {
    apiGet
      .mockResolvedValueOnce(overviewResponse)
      .mockResolvedValueOnce(emptySecurityResponse)
      .mockResolvedValueOnce(auditResponse);

    await adminApi.overview();
    await adminApi.securityAlerts();
    await adminApi.auditRecent({ page: 2, per_page: 25 });

    expect(apiGet).toHaveBeenNthCalledWith(1, '/admin/overview');
    expect(apiGet).toHaveBeenNthCalledWith(2, '/admin/security/alerts');
    expect(apiGet).toHaveBeenNthCalledWith(
      3,
      '/admin/audit/recent?page=2&per_page=25',
    );
  });
});

describe('admin-owned governance pages', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders a real overview loading state, data, and refresh action', async () => {
    const first = deferred<typeof overviewResponse>();
    apiGet.mockReturnValueOnce(first.promise).mockResolvedValueOnce(overviewResponse);
    const user = userEvent.setup();

    renderPage(<Overview />);

    expect(screen.getByText(i18n.t('common.loading'))).toBeInTheDocument();
    first.resolve(overviewResponse);
    expect(await screen.findByText('75%')).toBeInTheDocument();
    expect(screen.getByText('8')).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: i18n.t('common.refresh') }));
    await waitFor(() => expect(apiGet).toHaveBeenCalledTimes(2));
  });

  it('renders 403/500-style API errors and can recover via refresh', async () => {
    apiGet
      .mockRejectedValueOnce({ status: 403, message: 'Forbidden governance data' })
      .mockResolvedValueOnce(overviewResponse);
    const user = userEvent.setup();

    renderPage(<Overview />);

    expect(await screen.findByRole('alert')).toHaveTextContent('Forbidden governance data');
    await user.click(screen.getByRole('button', { name: i18n.t('common.refresh') }));
    expect(await screen.findByText('75%')).toBeInTheDocument();
  });

  it('renders the security empty state and surfaces a subsequent server error', async () => {
    apiGet
      .mockResolvedValueOnce(emptySecurityResponse)
      .mockRejectedValueOnce({ status: 500, message: 'Security service unavailable' });
    const user = userEvent.setup();

    renderPage(<SecurityAlerts />);

    expect(
      await screen.findByText(i18n.t('admin.security_alerts.empty.title')),
    ).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: i18n.t('common.refresh') }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Security service unavailable');
  });

  it('uses backend last_page for audit navigation and never renders actor email', async () => {
    apiGet
      .mockResolvedValueOnce(auditResponse)
      .mockResolvedValueOnce({
        data: [],
        meta: { ...auditResponse.meta, current_page: 2, returned: 0 },
      });
    const user = userEvent.setup();

    renderPage(<AuditRecent />);

    const row = await screen.findByTestId('audit-recent-row');
    expect(within(row).getByText('Governance Admin')).toBeInTheDocument();
    expect(row).not.toHaveTextContent('admin@example.test');
    expect(document.body).not.toHaveTextContent(/@example\.test/);

    expect(screen.getByRole('button', { name: /3/ })).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: /2/ }));

    await waitFor(() => {
      expect(apiGet).toHaveBeenLastCalledWith('/admin/audit/recent?page=2&per_page=1');
    });
    expect(
      await screen.findByText(i18n.t('admin.audit_recent.empty.title')),
    ).toBeInTheDocument();
  });
});
