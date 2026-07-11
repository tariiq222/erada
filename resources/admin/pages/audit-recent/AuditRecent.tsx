import { useCallback, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { IconHistory, IconRefresh } from '@tabler/icons-react';
import { adminApi } from '@admin/api/adminApi';
import type { AuditRecentResponse, AuditRecentRow } from '@admin/model/admin';
import { Alert } from '@shared/ui/Alert';
import { Button } from '@shared/ui/Button';
import { Card, CardContent, CardTitle } from '@shared/ui/Card';
import { Pagination } from '@shared/ui/Pagination';
import { StatusBadge } from '@shared/ui/StatusBadge';
import { formatDateTime } from '@shared/lib/utils';

const DEFAULT_PER_PAGE = 50;

export function AuditRecent() {
  const { t } = useTranslation();
  const [rows, setRows] = useState<AuditRecentRow[]>([]);
  const [meta, setMeta] = useState<AuditRecentResponse['meta'] | null>(null);
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async (nextPage: number, perPage: number = DEFAULT_PER_PAGE) => {
    setLoading(true);
    setError(null);
    try {
      const response = await adminApi.auditRecent({ page: nextPage, per_page: perPage });
      setRows(response.data);
      setMeta(response.meta);
      setPage(response.meta.current_page);
    } catch (caught) {
      setError((caught as { message?: string }).message ?? t('admin.audit_recent.load_failed'));
    } finally {
      setLoading(false);
    }
  }, [t]);

  useEffect(() => {
    void load(1);
  }, [load]);

  const changePage = (nextPage: number) => {
    void load(nextPage, meta?.per_page ?? DEFAULT_PER_PAGE);
  };

  return (
    <div className="space-y-6 p-6" data-testid="admin-protected-page">
      <header className="flex flex-wrap items-start justify-between gap-4">
        <div className="flex items-start gap-3"><IconHistory className="mt-1 h-6 w-6 text-[var(--accent-default)]" aria-hidden="true" /><div><h1 className="text-2xl font-bold text-[var(--text-primary)]">{t('admin.audit_recent.title')}</h1><p className="text-sm text-[var(--text-tertiary)]">{t('admin.audit_recent.subtitle')}</p></div></div>
        <Button variant="secondary" disabled={loading} onClick={() => void load(page, meta?.per_page ?? DEFAULT_PER_PAGE)}><IconRefresh className="me-2 h-4 w-4" />{t('common.refresh')}</Button>
      </header>
      {loading && rows.length === 0 && <p className="text-sm text-[var(--text-tertiary)]">{t('common.loading')}</p>}
      {error && <Alert variant="danger">{error}</Alert>}
      {!loading && !error && rows.length === 0 && <section className="rounded-lg border border-[var(--border-default)] p-8 text-center"><IconHistory className="mx-auto h-8 w-8 text-[var(--text-tertiary)]" aria-hidden="true" /><h2 className="mt-3 font-semibold text-[var(--text-primary)]">{t('admin.audit_recent.empty.title')}</h2><p className="mt-1 text-sm text-[var(--text-tertiary)]">{t('admin.audit_recent.empty.description')}</p></section>}
      {!error && rows.length > 0 && (
        <Card>
          <CardContent>
            <CardTitle>{t('admin.audit_recent.list_title')}</CardTitle>
            {meta && <p className="mt-1 text-xs text-[var(--text-tertiary)]">{t('admin.audit_recent.list_subtitle', { returned: meta.returned, limit: meta.limit })}</p>}
            <ul className="mt-4 divide-y divide-[var(--border-default)]">
              {rows.map((row) => (
                <li className="flex items-start justify-between gap-3 py-3" data-testid="audit-recent-row" key={row.id}>
                  <span className="min-w-0"><StatusBadge type="custom" color="secondary" status={row.action} label={row.action} /><span className="ms-2 text-sm text-[var(--text-primary)]">{row.description ?? '–'}</span><span className="ms-2 text-xs text-[var(--text-tertiary)]">{row.actor?.name ?? t('admin.audit_recent.system')}</span></span>
                  {row.created_at && <time className="shrink-0 text-xs text-[var(--text-tertiary)]">{formatDateTime(row.created_at)}</time>}
                </li>
              ))}
            </ul>
            {meta && <Pagination className="mt-4" currentPage={meta.current_page} totalPages={meta.last_page} onPageChange={changePage} />}
          </CardContent>
        </Card>
      )}
    </div>
  );
}
