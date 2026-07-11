import { useCallback, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { IconRefresh, IconShieldExclamation } from '@tabler/icons-react';
import { adminApi } from '@admin/api/adminApi';
import type { SecurityAlertsData } from '@admin/model/admin';
import { Alert } from '@shared/ui/Alert';
import { Button } from '@shared/ui/Button';
import { Card, CardContent, CardTitle } from '@shared/ui/Card';
import { formatDateTime, formatNumber } from '@shared/lib/utils';

export function SecurityAlerts() {
  const { t } = useTranslation();
  const [data, setData] = useState<SecurityAlertsData | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const response = await adminApi.securityAlerts();
      setData(response.data);
    } catch (caught) {
      setError((caught as { message?: string }).message ?? t('admin.security_alerts.load_failed'));
    } finally {
      setLoading(false);
    }
  }, [t]);

  useEffect(() => {
    void load();
  }, [load]);

  const failed = data?.failed_logins_repeated ?? [];
  const denied = data?.access_denied_events ?? [];
  const minutes = data?.windows.minutes ?? 60;
  const threshold = data?.windows.repeated_failure_threshold ?? 3;
  const empty = !loading && !error && failed.length === 0 && denied.length === 0;

  return (
    <div className="space-y-6 p-6" data-testid="admin-protected-page">
      <header className="flex flex-wrap items-start justify-between gap-4">
        <div className="flex items-start gap-3"><IconShieldExclamation className="mt-1 h-6 w-6 text-[var(--accent-default)]" aria-hidden="true" /><div><h1 className="text-2xl font-bold text-[var(--text-primary)]">{t('admin.security_alerts.title')}</h1><p className="text-sm text-[var(--text-tertiary)]">{t('admin.security_alerts.subtitle', { minutes: formatNumber(minutes), threshold: formatNumber(threshold) })}</p></div></div>
        <Button variant="secondary" disabled={loading} onClick={() => void load()}><IconRefresh className="me-2 h-4 w-4" />{t('common.refresh')}</Button>
      </header>
      {loading && !data && <p className="text-sm text-[var(--text-tertiary)]">{t('common.loading')}</p>}
      {error && <Alert variant="danger">{error}</Alert>}
      {empty && <section className="rounded-lg border border-[var(--border-default)] p-8 text-center"><IconShieldExclamation className="mx-auto h-8 w-8 text-[var(--text-tertiary)]" aria-hidden="true" /><h2 className="mt-3 font-semibold text-[var(--text-primary)]">{t('admin.security_alerts.empty.title')}</h2><p className="mt-1 text-sm text-[var(--text-tertiary)]">{t('admin.security_alerts.empty.description')}</p></section>}
      {!loading && !error && !empty && (
        <div className="grid gap-4 lg:grid-cols-2">
          <Card><CardContent><CardTitle>{t('admin.security_alerts.repeated_logins_title')}</CardTitle><ul className="mt-4 divide-y divide-[var(--border-default)]">{failed.map((row, index) => <li className="py-3 text-sm" key={`${row.email ?? row.ip_address}-${index}`}><span>{row.ip_address ? t('admin.security_alerts.bucket.ip', { ip: row.ip_address, attempts: formatNumber(row.attempts), distinct: formatNumber(row.distinct_emails ?? 0) }) : t('admin.security_alerts.bucket.email', { email: row.email ?? '–', attempts: formatNumber(row.attempts) })}</span>{row.last_attempted_at && <time className="ms-2 text-[var(--text-tertiary)]">{formatDateTime(row.last_attempted_at)}</time>}</li>)}</ul></CardContent></Card>
          <Card><CardContent><CardTitle>{t('admin.security_alerts.denied_title')}</CardTitle><ul className="mt-4 divide-y divide-[var(--border-default)]">{denied.map((row) => <li className="py-3 text-sm" key={row.id}><span>{row.action}</span>{row.route && <span className="ms-2 text-[var(--text-tertiary)]">{row.route}</span>}</li>)}</ul></CardContent></Card>
        </div>
      )}
    </div>
  );
}
