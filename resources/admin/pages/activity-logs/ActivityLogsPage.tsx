import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { IconHistory } from '@tabler/icons-react';
import { useAuth } from '@shared/contexts/AuthContext';
import { adminApi, apiErrorMessage } from '@admin/api/adminApi';
import type { ActivityLogEntry } from '@admin/model/admin';
import {
  ActorView,
  canExportActivityLogs,
} from '@admin/model/adminPredicates';
import { Alert } from '@shared/ui/Alert';
import { Button } from '@shared/ui/Button';
import { Card } from '@shared/ui/Card';
import { Input } from '@shared/ui/Input';
import { AdminPageHeader as PageHeader } from '@admin/pages/access/AdminPageHeader';

const actions = ['created', 'updated', 'deleted', 'login', 'logout', 'role_assigned', 'role_revoked', 'access_denied'];

/**
 * Activity logs surface for the OrgSuper-reachable subset of the admin SPA.
 *
 * Authorization posture:
 *   - The list endpoint (`GET /api/activity-logs`) is actor-scoped — the
 *     backend filters the response to logs the calling user is permitted
 *     to see. OrgSuper and super admin both hit this endpoint; OrgSuper
 *     never reaches the platform-wide `/api/admin/audit/recent` route,
 *     which is mounted under the `SuperAdminBoundary`.
 *   - The export endpoints (`GET /api/activity-logs/export`) are
 *     platform-wide and gated by a super-only capability. OrgSuper must
 *     never fire them, so the export buttons are hidden when
 *     `canExportActivityLogs(actor)` is false.
 */
export function ActivityLogsPage() {
  const { t } = useTranslation();
  const { user } = useAuth();
  const actor = user as ActorView | null;
  const showExport = canExportActivityLogs(actor);
  const [rows, setRows] = useState<ActivityLogEntry[]>([]);
  const [search, setSearch] = useState('');
  const [action, setAction] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const load = async () => {
    setLoading(true);
    setError(null);
    try {
      const response = await adminApi.activityLogs.list({ search, action, page: 1, per_page: 25 });
      setRows(response.data);
    } catch (caught) {
      setError(apiErrorMessage(caught, t('common.error')));
    } finally {
      setLoading(false);
    }
  };
  // Initial fetch only; filters are submitted explicitly.
  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(() => { void load(); }, []);
  const exportLogs = async (format: 'csv' | 'json') => {
    if (!showExport) return;
    setError(null);
    try {
      const blob = await adminApi.activityLogs.export(format, { search, action });
      const url = URL.createObjectURL(blob);
      const anchor = document.createElement('a');
      anchor.href = url;
      anchor.download = `activity-log-${new Date().toISOString().slice(0, 10)}.${format}`;
      document.body.appendChild(anchor);
      try {
        anchor.click();
      } finally {
        anchor.remove();
        URL.revokeObjectURL(url);
      }
    } catch (caught) {
      setError(apiErrorMessage(caught, t('common.error')));
    }
  };
  return (
    <div className="space-y-6 p-6" data-testid="admin-protected-page">
      <PageHeader
        icon={IconHistory}
        iconTone="admin"
        title={t('admin.activityLogs.title')}
        subtitle={t('admin.activityLogs.subtitle')}
        actions={
          showExport ? (
            <div className="flex gap-2">
              <Button variant="secondary" onClick={() => void exportLogs('csv')}>CSV</Button>
              <Button variant="secondary" onClick={() => void exportLogs('json')}>JSON</Button>
            </div>
          ) : null
        }
      />
      {error && <Alert variant="danger">{error}</Alert>}
      <Card>
        <div className="mb-4 grid gap-3 sm:grid-cols-[1fr_14rem_auto]">
          <Input value={search} onChange={(event) => setSearch(event.target.value)} placeholder={t('admin.activityLogs.searchPlaceholder')} />
          <label className="text-sm">
            {t('admin.activityLogs.fields.action')}
            <select
              aria-label={t('admin.activityLogs.fields.action')}
              className="mt-1 block w-full rounded-lg border p-2"
              value={action}
              onChange={(event) => setAction(event.target.value)}
            >
              <option value="">{t('admin.activityLogs.allActions')}</option>
              {actions.map((item) => (
                <option key={item} value={item}>{t(`admin.activityLogs.actions.${item}`)}</option>
              ))}
            </select>
          </label>
          <Button variant="secondary" onClick={() => void load()}>{t('common.search')}</Button>
        </div>
        {loading ? (
          <p>{t('common.loading')}</p>
        ) : rows.length === 0 ? (
          <p>{t('admin.activityLogs.empty')}</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b">
                  <th className="p-2 text-start">{t('admin.activityLogs.fields.time')}</th>
                  <th className="p-2 text-start">{t('admin.activityLogs.fields.user')}</th>
                  <th className="p-2 text-start">{t('admin.activityLogs.fields.action')}</th>
                  <th className="p-2 text-start">{t('admin.activityLogs.fields.description')}</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => (
                  <tr key={row.id} className="border-b">
                    <td className="p-2">{row.created_at}</td>
                    <td className="p-2">{row.user?.name ?? '—'}</td>
                    <td className="p-2">{row.action}</td>
                    <td className="p-2">{row.description ?? '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </div>
  );
}
