import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import {
  IconShieldLock,
  IconBuildingCommunity,
  IconUsers,
  IconLogin,
  IconAlertTriangle,
  IconUserPlus,
  IconKey,
} from '@tabler/icons-react';
import { PageHeader, Card, CardContent, CardTitle, StatStrip, Button, Alert } from '@shared/ui';
import type { StatStripItem } from '@shared/ui';
import { superAdminDashboardApi, type OverviewCounts } from '@entities/admin';
import { formatNumber, formatDateTime } from '@shared/lib/utils';
import { useNavigate } from 'react-router-dom';

const QUICK_LINK_KEYS = [
  {
    href: '/admin/organizations',
    icon: IconBuildingCommunity,
    titleKey: 'admin.overview.quick_links.organizations.title',
    subtitleKey: 'admin.overview.quick_links.organizations.subtitle',
  },
  {
    href: '/admin/access',
    icon: IconKey,
    titleKey: 'admin.overview.quick_links.access.title',
    subtitleKey: 'admin.overview.quick_links.access.subtitle',
  },
  {
    href: '/admin/roles',
    icon: IconShieldLock,
    titleKey: 'admin.overview.quick_links.roles.title',
    subtitleKey: 'admin.overview.quick_links.roles.subtitle',
  },
  {
    href: '/admin/users',
    icon: IconUsers,
    titleKey: 'admin.overview.quick_links.users.title',
    subtitleKey: 'admin.overview.quick_links.users.subtitle',
  },
  {
    href: '/admin/security/alerts',
    icon: IconAlertTriangle,
    titleKey: 'admin.overview.quick_links.security.title',
    subtitleKey: 'admin.overview.quick_links.security.subtitle',
  },
  {
    href: '/admin/audit/recent',
    icon: IconLogin,
    titleKey: 'admin.overview.quick_links.audit.title',
    subtitleKey: 'admin.overview.quick_links.audit.subtitle',
  },
  {
    href: '/admin/scoped-roles/audit-logs',
    icon: IconUserPlus,
    titleKey: 'admin.overview.quick_links.audit_logs.title',
    subtitleKey: 'admin.overview.quick_links.audit_logs.subtitle',
  },
];

const OverviewPage: React.FC = () => {
  const { t } = useTranslation();
  const navigate = useNavigate();

  const [data, setData] = useState<OverviewCounts | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const fetchData = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await superAdminDashboardApi.overview();
      setData(res.data);
    } catch (err) {
      const message =
        (err as { message?: string })?.message || t('admin.overview.load_failed');
      setError(message);
    } finally {
      setLoading(false);
    }
  }, [t]);

  useEffect(() => {
    void fetchData();
  }, [fetchData]);

  const items: StatStripItem[] = useMemo(() => {
    if (!data) {
      return [
        { label: t('admin.overview.kpi.organizations_active'), value: '–', tone: 'accent' },
        { label: t('admin.overview.kpi.users_active'), value: '–', tone: 'success' },
        {
          label: t('admin.overview.kpi.login_successful_24h'),
          value: '–',
          tone: 'neutral',
        },
        {
          label: t('admin.overview.kpi.login_failed_24h'),
          value: '–',
          tone: 'warning',
        },
        {
          label: t('admin.overview.kpi.two_factor_coverage'),
          value: '–',
          tone: 'success',
        },
      ];
    }
    return [
      {
        label: t('admin.overview.kpi.organizations_active'),
        value: formatNumber(data.organizations.active),
        tone: 'accent',
      },
      {
        label: t('admin.overview.kpi.users_active'),
        value: formatNumber(data.users.active),
        tone: 'success',
      },
      {
        label: t('admin.overview.kpi.login_successful_24h'),
        value: formatNumber(data.login_attempts.last_24h.successful),
        tone: 'neutral',
      },
      {
        label: t('admin.overview.kpi.login_failed_24h'),
        value: formatNumber(data.login_attempts.last_24h.failed),
        tone: data.login_attempts.last_24h.failed > 0 ? 'warning' : 'neutral',
      },
      {
        label: t('admin.overview.kpi.two_factor_coverage'),
        value: `${formatNumber(data.users.two_factor_coverage.percent)}%`,
        tone: 'success',
      },
    ];
  }, [data, t]);

  return (
    <div className="p-6 space-y-6">
      <PageHeader
        title={t('admin.overview.title')}
        subtitle={t('admin.overview.subtitle')}
        icon={IconShieldLock}
        iconTone="admin"
        actions={
          <Button
            variant="secondary"
            onClick={() => void fetchData()}
            disabled={loading}
            data-testid="overview-refresh"
          >
            {t('common.refresh')}
          </Button>
        }
      />

      {error && <Alert variant="danger">{error}</Alert>}

      <StatStrip items={items} data-testid="overview-stat-strip" />

      <Card data-testid="overview-summary-card">
        <CardContent>
          <CardTitle>{t('admin.overview.summary_title')}</CardTitle>
          <dl className="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
            <div className="flex justify-between gap-2 border-b border-[var(--border-default)] pb-2">
              <dt className="text-[var(--text-tertiary)]">
                {t('admin.overview.fields.generated_at')}
              </dt>
              <dd className="font-medium text-[var(--text-primary)]">
                {data?.generated_at ? formatDateTime(data.generated_at) : '–'}
              </dd>
            </div>
            <div className="flex justify-between gap-2 border-b border-[var(--border-default)] pb-2">
              <dt className="text-[var(--text-tertiary)]">
                {t('admin.overview.fields.organizations_total')}
              </dt>
              <dd className="font-medium text-[var(--text-primary)]">
                {data ? formatNumber(data.organizations.total) : '–'}
              </dd>
            </div>
            <div className="flex justify-between gap-2 border-b border-[var(--border-default)] pb-2">
              <dt className="text-[var(--text-tertiary)]">
                {t('admin.overview.fields.users_total')}
              </dt>
              <dd className="font-medium text-[var(--text-primary)]">
                {data ? formatNumber(data.users.total) : '–'}
              </dd>
            </div>
            <div className="flex justify-between gap-2 border-b border-[var(--border-default)] pb-2">
              <dt className="text-[var(--text-tertiary)]">
                {t('admin.overview.fields.two_factor_enabled')}
              </dt>
              <dd className="font-medium text-[var(--text-primary)]">
                {data ? formatNumber(data.users.two_factor_coverage.enabled) : '–'}
              </dd>
            </div>
          </dl>
        </CardContent>
      </Card>

      <section
        aria-labelledby="overview-quicklinks-heading"
        data-testid="overview-quick-links"
      >
        <h2
          id="overview-quicklinks-heading"
          className="text-base font-semibold text-[var(--text-primary)] mb-3"
        >
          {t('admin.overview.quick_links.title')}
        </h2>
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
          {QUICK_LINK_KEYS.map((link) => {
            const Icon = link.icon;
            return (
              <button
                key={link.href}
                type="button"
                onClick={() => navigate(link.href)}
                className="flex items-start gap-3 rounded-lg border border-[var(--border-default)] bg-[var(--surface-base)] p-3 text-start transition-colors hover:bg-[var(--surface-muted)]"
                data-testid={`overview-quick-link-${link.href}`}
              >
                <span className="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-[var(--accent-default)] text-[var(--text-inverse)]">
                  <Icon className="h-4 w-4" />
                </span>
                <span className="min-w-0">
                  <span className="block text-sm font-semibold text-[var(--text-primary)]">
                    {t(link.titleKey)}
                  </span>
                  <span className="block text-xs text-[var(--text-tertiary)] mt-0.5">
                    {t(link.subtitleKey)}
                  </span>
                </span>
              </button>
            );
          })}
        </div>
      </section>
    </div>
  );
};

export default OverviewPage;
