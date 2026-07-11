import { useCallback, useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { IconShieldLock } from '@tabler/icons-react';
import { adminApi } from '@admin/api/adminApi';
import type { OverviewCounts } from '@admin/model/admin';
import { Alert } from '@shared/ui/Alert';
import { Button } from '@shared/ui/Button';
import { Card, CardContent, CardTitle } from '@shared/ui/Card';
import StatStrip from '@shared/ui/StatStrip';
import type { StatStripItem } from '@shared/ui/StatStrip';
import { formatDateTime, formatNumber } from '@shared/lib/utils';

export function Overview() {
  const { t } = useTranslation();
  const [data, setData] = useState<OverviewCounts | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const response = await adminApi.overview();
      setData(response.data);
    } catch (caught) {
      setError((caught as { message?: string }).message ?? t('admin.overview.load_failed'));
    } finally {
      setLoading(false);
    }
  }, [t]);

  useEffect(() => {
    void load();
  }, [load]);

  const items = useMemo<StatStripItem[]>(() => [
    {
      label: t('admin.overview.kpi.organizations_active'),
      value: data ? formatNumber(data.organizations.active) : '–',
      tone: 'accent',
    },
    {
      label: t('admin.overview.kpi.users_active'),
      value: data ? formatNumber(data.users.active) : '–',
      tone: 'success',
    },
    {
      label: t('admin.overview.kpi.login_successful_24h'),
      value: data ? formatNumber(data.login_attempts.last_24h.successful) : '–',
      tone: 'neutral',
    },
    {
      label: t('admin.overview.kpi.login_failed_24h'),
      value: data ? formatNumber(data.login_attempts.last_24h.failed) : '–',
      tone: data && data.login_attempts.last_24h.failed > 0 ? 'warning' : 'neutral',
    },
    {
      label: t('admin.overview.kpi.two_factor_coverage'),
      value: data ? `${formatNumber(data.users.two_factor_coverage.percent)}%` : '–',
      tone: 'success',
    },
  ], [data, t]);

  return (
    <div className="space-y-6 p-6" data-testid="admin-protected-page">
      <header className="flex flex-wrap items-start justify-between gap-4">
        <div className="flex items-start gap-3">
          <IconShieldLock className="mt-1 h-6 w-6 text-[var(--accent-default)]" aria-hidden="true" />
          <div><h1 className="text-2xl font-bold text-[var(--text-primary)]">{t('admin.overview.title')}</h1><p className="text-sm text-[var(--text-tertiary)]">{t('admin.overview.subtitle')}</p></div>
        </div>
        <Button variant="secondary" disabled={loading} onClick={() => void load()}>{t('common.refresh')}</Button>
      </header>
      {loading && !data && <p className="text-sm text-[var(--text-tertiary)]">{t('common.loading')}</p>}
      {error && <Alert variant="danger">{error}</Alert>}
      {!loading && !error && data && (
        <>
          <StatStrip items={items} />
          <Card>
            <CardContent>
              <CardTitle>{t('admin.overview.summary_title')}</CardTitle>
              <dl className="mt-4 grid gap-4 text-sm sm:grid-cols-2">
                <div><dt>{t('admin.overview.fields.generated_at')}</dt><dd>{formatDateTime(data.generated_at)}</dd></div>
                <div><dt>{t('admin.overview.fields.organizations_total')}</dt><dd>{formatNumber(data.organizations.total)}</dd></div>
                <div><dt>{t('admin.overview.fields.users_total')}</dt><dd>{formatNumber(data.users.total)}</dd></div>
                <div><dt>{t('admin.overview.fields.two_factor_enabled')}</dt><dd>{formatNumber(data.users.two_factor_coverage.enabled)}</dd></div>
              </dl>
            </CardContent>
          </Card>
        </>
      )}
    </div>
  );
}
