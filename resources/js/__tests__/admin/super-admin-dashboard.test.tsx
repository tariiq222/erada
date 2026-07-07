import React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

const navigateMock = vi.fn();

const superAdminDashboardApiMock = {
  overview: vi.fn(),
  securityAlerts: vi.fn(),
  auditRecent: vi.fn(),
};

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string, vars?: Record<string, unknown>) => {
    if (vars && typeof vars === 'object') {
      const parts: string[] = [];
      const keys = Object.keys(vars);
      for (let i = 0; i < keys.length; i++) {
        parts.push(`{${keys[i]}}`);
      }
      return key + ':' + parts.join('|');
    }
    return key;
  } }),
}));

vi.mock('react-router-dom', async () => {
  const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom');
  return {
    ...actual,
    useNavigate: () => navigateMock,
  };
});

vi.mock('@entities/admin', () => ({
  superAdminDashboardApi: superAdminDashboardApiMock,
}));

beforeEach(() => {
  navigateMock.mockReset();
  superAdminDashboardApiMock.overview.mockReset();
  superAdminDashboardApiMock.securityAlerts.mockReset();
  superAdminDashboardApiMock.auditRecent.mockReset();
  Element.prototype.scrollIntoView = vi.fn();
});

describe('super admin overview page', () => {
  it('renders KPI strip, summary fields, and quick links from loaded overview', async () => {
    superAdminDashboardApiMock.overview.mockResolvedValue({
      data: {
        organizations: { active: 3, total: 4 },
        users: {
          active: 22,
          total: 25,
          two_factor_coverage: { enabled: 11, active_users: 22, percent: 50 },
        },
        login_attempts: { last_24h: { successful: 100, failed: 5, total: 105 } },
        registrations: { pending: 2, avg_pending_age_days: 1.2 },
        generated_at: '2026-07-03T08:00:00Z',
      },
    });
    const { default: Overview } = await import('@pages/admin/overview/Overview');

    render(<Overview />);

    expect(await screen.findByTestId('overview-stat-strip')).toBeInTheDocument();
    expect(screen.getByTestId('overview-summary-card')).toBeInTheDocument();
    expect(screen.getByTestId('overview-quick-links')).toBeInTheDocument();
    expect(
      screen.getByTestId('overview-quick-link-/admin/organizations'),
    ).toBeInTheDocument();

    // 50% rendered through formatNumber – assert the label key survived.
    expect(
      screen.getAllByText('admin.overview.kpi.two_factor_coverage').length,
    ).toBeGreaterThanOrEqual(1);
  });

  it('navigates when a quick link is clicked', async () => {
    const user = userEvent.setup();
    superAdminDashboardApiMock.overview.mockResolvedValue({
      data: {
        organizations: { active: 1, total: 1 },
        users: { active: 1, total: 1, two_factor_coverage: { enabled: 0, active_users: 1, percent: 0 } },
        login_attempts: { last_24h: { successful: 0, failed: 0, total: 0 } },
        registrations: { pending: 0, avg_pending_age_days: null },
        generated_at: '2026-07-03T08:00:00Z',
      },
    });
    const { default: Overview } = await import('@pages/admin/overview/Overview');

    render(<Overview />);

    const link = await screen.findByTestId('overview-quick-link-/admin/roles');
    await user.click(link);

    expect(navigateMock).toHaveBeenCalledWith('/admin/roles');
  });

  it('renders an error alert when the overview request fails', async () => {
    superAdminDashboardApiMock.overview.mockReset();
    superAdminDashboardApiMock.overview.mockRejectedValue(
      new Error('overview endpoint down'),
    );
    const { default: Overview } = await import('@pages/admin/overview/Overview');

    render(<Overview />);

    expect(await screen.findByText('overview endpoint down')).toBeInTheDocument();
  });

  it('re-fetches when the refresh button is clicked', async () => {
    superAdminDashboardApiMock.overview.mockReset();
    superAdminDashboardApiMock.overview.mockResolvedValue({
      data: {
        organizations: { active: 1, total: 1 },
        users: { active: 1, total: 1, two_factor_coverage: { enabled: 0, active_users: 1, percent: 0 } },
        login_attempts: { last_24h: { successful: 0, failed: 0, total: 0 } },
        registrations: { pending: 0, avg_pending_age_days: null },
        generated_at: '2026-07-03T08:00:00Z',
      },
    });
    const { default: Overview } = await import('@pages/admin/overview/Overview');

    render(<Overview />);

    expect(await screen.findByTestId('overview-stat-strip')).toBeInTheDocument();
    const beforeCalls = superAdminDashboardApiMock.overview.mock.calls.length;

    const user = userEvent.setup();
    await user.click(screen.getByTestId('overview-refresh'));

    await waitFor(() =>
      expect(superAdminDashboardApiMock.overview.mock.calls.length).toBeGreaterThan(beforeCalls),
    );
  });
});

describe('super admin security alerts page', () => {
  it('renders empty state when there are no repeated failures and no denied events', async () => {
    superAdminDashboardApiMock.securityAlerts.mockResolvedValue({
      data: {
        windows: { minutes: 60, cutoff: '2026-07-03T07:00:00Z', repeated_failure_threshold: 3 },
        failed_logins_repeated: [],
        access_denied_events: [],
        generated_at: '2026-07-03T08:00:00Z',
      },
    });
    const { default: SecurityAlerts } = await import(
      '@pages/admin/security-alerts/SecurityAlerts'
    );

    render(<SecurityAlerts />);

    expect(
      await screen.findByText('admin.security_alerts.empty.title'),
    ).toBeInTheDocument();
    expect(screen.getByText('admin.security_alerts.no_repeated_logins')).toBeInTheDocument();
    expect(screen.getByText('admin.security_alerts.no_denied')).toBeInTheDocument();
  });

  it('renders repeated-failure buckets and denied-event rows', async () => {
    superAdminDashboardApiMock.securityAlerts.mockResolvedValue({
      data: {
        windows: { minutes: 60, cutoff: '2026-07-03T07:00:00Z', repeated_failure_threshold: 3 },
        failed_logins_repeated: [
          {
            email: 'attacker@example.test',
            attempts: 6,
            first_attempted_at: '2026-07-03T07:10:00Z',
            last_attempted_at: '2026-07-03T07:30:00Z',
          },
          {
            ip_address: '203.0.113.5',
            attempts: 5,
            distinct_emails: 4,
            first_attempted_at: '2026-07-03T07:00:00Z',
            last_attempted_at: '2026-07-03T07:25:00Z',
          },
        ],
        access_denied_events: [
          {
            id: 91,
            user_id: 7,
            action: 'access_denied',
            route: 'GET /api/projects',
            ip_address: '198.51.100.10',
            created_at: '2026-07-03T07:55:00Z',
          },
        ],
        generated_at: '2026-07-03T08:00:00Z',
      },
    });
    const { default: SecurityAlerts } = await import(
      '@pages/admin/security-alerts/SecurityAlerts'
    );

    render(<SecurityAlerts />);

    await screen.findByTestId('security-alerts-failed-logins');
    const buckets = screen.getAllByTestId('security-alerts-bucket-row');
    expect(buckets).toHaveLength(2);
    expect(
      screen.getByText('admin.security_alerts.bucket.email:{email}|{attempts}'),
    ).toBeInTheDocument();
    expect(
      screen.getByText('admin.security_alerts.bucket.ip:{ip}|{attempts}|{distinct}'),
    ).toBeInTheDocument();

    const deniedRows = screen.getAllByTestId('security-alerts-denied-row');
    expect(deniedRows).toHaveLength(1);
    expect(screen.getByText('GET /api/projects')).toBeInTheDocument();
  });

  it('shows an error alert when the alerts call fails', async () => {
    superAdminDashboardApiMock.securityAlerts.mockRejectedValueOnce(
      new Error('alerts endpoint down'),
    );
    const { default: SecurityAlerts } = await import(
      '@pages/admin/security-alerts/SecurityAlerts'
    );

    render(<SecurityAlerts />);

    expect(await screen.findByText('alerts endpoint down')).toBeInTheDocument();
  });
});

describe('super admin audit recent page', () => {
  it('caps at 50 events, renders rows, and supports pagination', async () => {
    const user = userEvent.setup();
    superAdminDashboardApiMock.auditRecent.mockImplementation(async (params) => {
      // First call returns full 50 rows so the Next button is enabled. After the
      // click, page=2 is requested and a short response is returned so the
      // Next button then disables (returned < per_page).
      const requestedPage = params?.page ?? 1;
      if (requestedPage === 1) {
        return {
          data: Array.from({ length: 50 }, (_, i) => ({
            id: 1000 + i,
            action: 'login',
            description: `Event ${i}`,
            actor: { id: 1, name: 'Admin', email: 'a@example.test' },
            target_user: null,
            scope_type: null,
            scope_id: null,
            role: null,
            ip_address: '127.0.0.1',
            created_at: '2026-07-03T07:5' + (i % 6) + ':00Z',
          })),
          meta: { current_page: 1, per_page: 50, limit: 50, returned: 50 },
        };
      }
      return {
        data: Array.from({ length: 5 }, (_, i) => ({
          id: 2000 + i,
          action: 'role_assigned',
          description: `Page 2 event ${i}`,
          actor: { id: 1, name: 'Admin', email: 'a@example.test' },
          target_user: { id: 2, name: 'Bob' },
          scope_type: 'project',
          scope_id: 9,
          role: 'member',
          ip_address: '127.0.0.1',
          created_at: '2026-07-03T06:5' + (i % 6) + ':00Z',
        })),
        meta: { current_page: 2, per_page: 50, limit: 50, returned: 5 },
      };
    });

    const { default: AuditRecent } = await import(
      '@pages/admin/audit-recent/AuditRecent'
    );

    render(<AuditRecent />);

    await screen.findByTestId('audit-recent-card');
    expect(await screen.findAllByTestId('audit-recent-row')).toHaveLength(50);

    // Wait until the API was definitely called once (initial mount settled).
    await waitFor(() =>
      expect(superAdminDashboardApiMock.auditRecent.mock.calls.length).toBeGreaterThan(0),
    );

    await user.click(screen.getByTestId('audit-recent-next'));

    // After the click, the component must re-render with the shorter list and
    // pass page=2 to the API. We assert by waiting for the row count to drop.
    await waitFor(() =>
      expect(screen.getAllByTestId('audit-recent-row')).toHaveLength(5),
    );

    // And confirm the API was at least once invoked with page=2.
    const pageTwoCalls = superAdminDashboardApiMock.auditRecent.mock.calls.filter(
      (args) => (args?.[0] as { page?: number })?.page === 2,
    );
    expect(pageTwoCalls.length).toBeGreaterThan(0);
  });

  it('renders empty state when there are no events', async () => {
    superAdminDashboardApiMock.auditRecent.mockResolvedValue({
      data: [],
      meta: { current_page: 1, per_page: 50, limit: 50, returned: 0 },
    });
    const { default: AuditRecent } = await import(
      '@pages/admin/audit-recent/AuditRecent'
    );

    render(<AuditRecent />);

    expect(
      await screen.findByText('admin.audit_recent.empty.title'),
    ).toBeInTheDocument();
  });

  it('shows an error alert when the request fails', async () => {
    superAdminDashboardApiMock.auditRecent.mockReset();
    superAdminDashboardApiMock.auditRecent.mockRejectedValue(new Error('audit fetch failed'));
    const { default: AuditRecent } = await import(
      '@pages/admin/audit-recent/AuditRecent'
    );

    render(<AuditRecent />);

    expect(await screen.findByText('audit fetch failed')).toBeInTheDocument();
  });
});
