import React, { useCallback, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { IconShieldExclamation, IconClock, IconRefresh } from '@tabler/icons-react';
import {
  PageHeader,
  Card,
  CardContent,
  CardTitle,
  Button,
  Alert,
  EmptyState,
} from '@shared/ui';
import {
  superAdminDashboardApi,
  type SecurityAlerts,
  type SecurityAlertFailedLogin,
} from '@entities/admin';
import { formatNumber, formatDateTime } from '@shared/lib/utils';

type Translator = (key: string, vars?: Record<string, unknown>) => string;

function bucketLabel(
  row: SecurityAlertFailedLogin,
  t: Translator,
): string {
  if (row.ip_address) {
    return t('admin.security_alerts.bucket.ip', {
      ip: row.ip_address,
      attempts: formatNumber(row.attempts),
      distinct: formatNumber(row.distinct_emails ?? 0),
    });
  }
  return t('admin.security_alerts.bucket.email', {
    email: row.email ?? '–',
    attempts: formatNumber(row.attempts),
  });
}

const SecurityAlertsPage: React.FC = () => {
  const { t } = useTranslation();

  const [data, setData] = useState<SecurityAlerts | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const fetchData = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await superAdminDashboardApi.securityAlerts();
      setData(res.data);
    } catch (err) {
      const message =
        (err as { message?: string })?.message || t('admin.security_alerts.load_failed');
      setError(message);
    } finally {
      setLoading(false);
    }
  }, [t]);

  useEffect(() => {
    void fetchData();
  }, [fetchData]);

  const failedLoginCount = data?.failed_logins_repeated.length ?? 0;
  const deniedCount = data?.access_denied_events.length ?? 0;
  const windowMinutes = data?.windows.minutes ?? 60;
  const threshold = data?.windows.repeated_failure_threshold ?? 3;

  return (
    <div className="p-6 space-y-6">
      <PageHeader
        title={t('admin.security_alerts.title')}
        subtitle={t('admin.security_alerts.subtitle', {
          minutes: formatNumber(windowMinutes),
          threshold: formatNumber(threshold),
        })}
        icon={IconShieldExclamation}
        iconTone="admin"
        actions={
          <Button
            variant="secondary"
            onClick={() => void fetchData()}
            disabled={loading}
          >
            <IconRefresh className="h-4 w-4 me-2" />
            {t('common.refresh')}
          </Button>
        }
      />

      {error && <Alert variant="danger">{error}</Alert>}

      {!error && !loading && failedLoginCount === 0 && deniedCount === 0 && (
        <EmptyState
          icon={IconShieldExclamation}
          title={t('admin.security_alerts.empty.title')}
          description={t('admin.security_alerts.empty.description')}
        />
      )}

      <Card data-testid="security-alerts-failed-logins">
        <CardContent>
          <CardTitle>{t('admin.security_alerts.repeated_logins_title')}</CardTitle>
          <p className="text-xs text-[var(--text-tertiary)] mt-1">
            {t('admin.security_alerts.repeated_logins_subtitle', {
              threshold: formatNumber(threshold),
            })}
          </p>

          {loading ? (
            <p className="text-sm text-[var(--text-tertiary)] mt-4">
              {t('common.loading')}
            </p>
          ) : failedLoginCount === 0 ? (
            <p className="text-sm text-[var(--text-tertiary)] mt-4">
              {t('admin.security_alerts.no_repeated_logins')}
            </p>
          ) : (
            <ul className="mt-4 divide-y divide-[var(--border-default)]">
              {data?.failed_logins_repeated.map((row, idx) => {
                const lastSeen = row.last_attempted_at
                  ? formatDateTime(row.last_attempted_at)
                  : '–';
                return (
                  <li
                    key={`${row.ip_address ?? row.email ?? 'unknown'}-${idx}`}
                    className="flex items-center justify-between gap-3 py-3"
                    data-testid="security-alerts-bucket-row"
                  >
                    <span className="text-sm font-medium text-[var(--text-primary)]">
                      {bucketLabel(row, t)}
                    </span>
                    <span className="flex items-center gap-1 text-xs text-[var(--text-tertiary)]">
                      <IconClock className="h-3.5 w-3.5" />
                      {lastSeen}
                    </span>
                  </li>
                );
              })}
            </ul>
          )}
        </CardContent>
      </Card>

      <Card data-testid="security-alerts-denied">
        <CardContent>
          <CardTitle>{t('admin.security_alerts.denied_title')}</CardTitle>
          <p className="text-xs text-[var(--text-tertiary)] mt-1">
            {t('admin.security_alerts.denied_subtitle')}
          </p>

          {loading ? (
            <p className="text-sm text-[var(--text-tertiary)] mt-4">
              {t('common.loading')}
            </p>
          ) : deniedCount === 0 ? (
            <p className="text-sm text-[var(--text-tertiary)] mt-4">
              {t('admin.security_alerts.no_denied')}
            </p>
          ) : (
            <ul className="mt-4 divide-y divide-[var(--border-default)]">
              {data?.access_denied_events.map((row) => (
                <li
                  key={row.id}
                  className="flex items-center justify-between gap-3 py-3 text-sm"
                  data-testid="security-alerts-denied-row"
                >
                  <span className="flex items-center gap-3 min-w-0">
                    <span className="font-mono text-xs text-[var(--text-tertiary)] shrink-0">
                      #{row.id}
                    </span>
                    <span className="font-medium text-[var(--text-primary)] truncate">
                      {row.action}
                    </span>
                    {row.route && (
                      <span className="text-xs text-[var(--text-tertiary)] truncate">
                        {row.route}
                      </span>
                    )}
                  </span>
                  <span className="flex items-center gap-2 text-xs text-[var(--text-tertiary)] shrink-0">
                    {row.ip_address && (
                      <span className="font-mono">{row.ip_address}</span>
                    )}
                    {row.created_at && (
                      <span>{formatDateTime(row.created_at)}</span>
                    )}
                  </span>
                </li>
              ))}
            </ul>
          )}
        </CardContent>
      </Card>
    </div>
  );
};

export default SecurityAlertsPage;
