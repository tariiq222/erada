import React, { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { activityLogsApi, ActivityLogEntry } from '@entities/admin';
import { formatDateTime } from '@shared/lib/utils';
import { Card } from '@shared/ui/Card';
import { Button } from '@shared/ui/Button';
import { Input } from '@shared/ui/Input';
import { Select } from '@shared/ui/Select';
import { StatusBadge } from '@shared/ui/StatusBadge';
import { PageHeader } from '@shared/ui/PageHeader';
import { Alert } from '@shared/ui/Alert';
import {IconSearch, IconDownload, IconJson, IconLoader, IconHistory} from '@tabler/icons-react';

const ACTION_KEYS = [
  'created',
  'updated',
  'deleted',
  'restored',
  'login',
  'logout',
  'login_failed',
  'password_changed',
  'role_assigned',
  'role_revoked',
  'permission_granted',
  'permission_revoked',
  'access_denied',
] as const;

export const ActivityLogsList: React.FC = () => {
  const { t } = useTranslation();

  const actionLabels = useMemo(
    () =>
      ACTION_KEYS.reduce<Record<string, string>>((acc, key) => {
        acc[key] = t(`admin.activityLogs.actions.${key}`);
        return acc;
      }, {}),
    [t]
  );

  const [data, setData] = useState<ActivityLogEntry[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [search, setSearch] = useState('');
  const [actionFilter, setActionFilter] = useState('');
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1, total: 0 });
  const [page, setPage] = useState(1);

  const fetchData = async () => {
    setLoading(true);
    setError(null);
    try {
      const result = (await activityLogsApi.list({
        search,
        action: actionFilter,
        page,
        per_page: 25,
      })) as any;
      setData(result.data || []);
      setMeta(result.meta || { current_page: 1, last_page: 1, total: 0 });
    } catch (err: any) {
      setError(err?.message || t('common.error'));
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchData();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [page]);

  const handleExport = async (format: 'csv' | 'json') => {
    try {
      const blob =
        format === 'csv'
          ? await activityLogsApi.exportCsv({ search, action: actionFilter })
          : await activityLogsApi.exportJson({ search, action: actionFilter });
      const url = URL.createObjectURL(blob as any);
      const a = document.createElement('a');
      a.href = url;
      a.download = `activity-log-${new Date().toISOString().slice(0, 10)}.${format}`;
      a.click();
      URL.revokeObjectURL(url);
    } catch (err: any) {
      setError(err?.message || t('common.error'));
    }
  };

  return (
    <div className="p-6 space-y-6">
      <PageHeader
        icon={IconHistory}
        iconTone="admin"
        title={t('admin.activityLogs.title')}
        subtitle={t('admin.activityLogs.subtitle')}
        actions={
          <div className="flex gap-2">
            <Button variant="secondary" onClick={() => handleExport('csv')}>
              <IconDownload className="w-4 h-4 me-2" />
              CSV
            </Button>
            <Button variant="secondary" onClick={() => handleExport('json')}>
              <IconJson className="w-4 h-4 me-2" />
              JSON
            </Button>
          </div>
        }
      />

      {error && (
        <Alert variant="danger">{error}</Alert>
      )}

      <Card className="p-4">
        <div className="flex flex-wrap items-center gap-2 mb-4">
          <div className="relative flex-1 min-w-[200px]">
            <IconSearch className="absolute top-1/2 -translate-y-1/2 start-3 w-4 h-4 text-[var(--text-secondary)]" />
            <Input
              type="text"
              placeholder={t('admin.activityLogs.searchPlaceholder')}
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              onKeyDown={(e) => e.key === 'Enter' && fetchData()}
              className="ps-10"
            />
          </div>
          <Select
            value={actionFilter}
            onChange={(e) => setActionFilter(e.target.value)}
            className="min-w-[180px]"
            placeholder={t('admin.activityLogs.allActions')}
            options={Object.entries(actionLabels).map(([k, v]) => ({
              value: k,
              label: v,
            }))}
          />
          <Button variant="secondary" onClick={fetchData}>
            {t('common.search')}
          </Button>
        </div>

        {loading ? (
          <div className="flex items-center justify-center py-12">
            <IconLoader className="w-6 h-6 animate-spin text-[var(--accent-default)]" />
          </div>
        ) : data.length === 0 ? (
          <div className="text-center py-12 text-[var(--text-secondary)]">
            {t('admin.activityLogs.empty')}
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-[var(--border)]">
                  <th className="py-3 px-2 text-start font-semibold">
                    {t('admin.activityLogs.fields.time')}
                  </th>
                  <th className="py-3 px-2 text-start font-semibold">
                    {t('admin.activityLogs.fields.user')}
                  </th>
                  <th className="py-3 px-2 text-start font-semibold">
                    {t('admin.activityLogs.fields.action')}
                  </th>
                  <th className="py-3 px-2 text-start font-semibold">
                    {t('admin.activityLogs.fields.description')}
                  </th>
                  <th className="py-3 px-2 text-start font-semibold">
                    {t('admin.activityLogs.fields.ip')}
                  </th>
                </tr>
              </thead>
              <tbody>
                {data.map((log) => (
                  <tr
                    key={log.id}
                    className="border-b border-[var(--border)] hover:bg-[var(--bg-hover)]"
                  >
                    <td className="py-3 px-2 font-mono text-xs whitespace-nowrap">
                      {formatDateTime(log.created_at)}
                    </td>
                    <td className="py-3 px-2">
                      <div className="font-medium">{log.user?.name || '–'}</div>
                      {/* Phase 4B — ActivityLogResource on the BE emits
                          actor as { id, name } only. The shape is
                          intentionally email-free (PII / cross-org
                          boundary) — the FE MUST NOT rely on email.
                          The status row keeps the secondary line
                          empty rather than reading a field the BE
                          doesn't surface. */}
                    </td>
                    <td className="py-3 px-2">
                      <StatusBadge
                        type="custom"
                        color="secondary"
                        status={log.action}
                        label={actionLabels[log.action] || log.action}
                      />
                    </td>
                    <td className="py-3 px-2">{log.description}</td>
                    <td className="py-3 px-2 font-mono text-xs">
                      {log.ip_address || '–'}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {meta.last_page > 1 && (
          <div className="flex items-center justify-between mt-4 pt-4 border-t border-[var(--border)]">
            <span className="text-sm text-[var(--text-secondary)]">
              {t('common.page')} {meta.current_page} / {meta.last_page} •{' '}
              {meta.total} {t('common.total')}
            </span>
            <div className="flex gap-2">
              <Button
                size="sm"
                variant="secondary"
                disabled={page === 1}
                onClick={() => setPage((p) => Math.max(1, p - 1))}
              >
                {t('common.prev')}
              </Button>
              <Button
                size="sm"
                variant="secondary"
                disabled={page === meta.last_page}
                onClick={() => setPage((p) => p + 1)}
              >
                {t('common.next')}
              </Button>
            </div>
          </div>
        )}
      </Card>
    </div>
  );
};

export default ActivityLogsList;
