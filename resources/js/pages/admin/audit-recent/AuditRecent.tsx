import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { IconHistory, IconRefresh } from '@tabler/icons-react';
import {
  PageHeader,
  Card,
  CardContent,
  CardTitle,
  Button,
  Alert,
  EmptyState,
  StatusBadge,
  type CustomBadgeColor,
} from '@shared/ui';
import {
  superAdminDashboardApi,
  type AuditRecentRow,
  type AuditRecentResponse,
} from '@entities/admin';
import { formatDateTime } from '@shared/lib/utils';

const ACTION_PALETTE: Record<string, CustomBadgeColor> = {
  login: 'success',
  logout: 'secondary',
  login_failed: 'danger',
  password_changed: 'warning',
  account_setup: 'info',
  role_assigned: 'success',
  role_revoked: 'danger',
  role_updated: 'warning',
  permission_granted: 'success',
  permission_revoked: 'danger',
  system_role_assigned: 'success',
  system_role_revoked: 'danger',
  access_denied: 'danger',
};

const AuditRecentPage: React.FC = () => {
  const { t } = useTranslation();

  const [data, setData] = useState<AuditRecentRow[]>([]);
  const [meta, setMeta] = useState<AuditRecentResponse['meta'] | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [page, setPage] = useState(1);

  const perPage = meta?.per_page ?? 50;

  const fetchData = useCallback(
    async (pageToLoad: number = page) => {
      setLoading(true);
      setError(null);
      try {
        const res = await superAdminDashboardApi.auditRecent({
          page: pageToLoad,
          per_page: perPage,
        });
        setData(res.data);
        setMeta(res.meta);
      } catch (err) {
        const message =
          (err as { message?: string })?.message || t('admin.audit_recent.load_failed');
        setError(message);
      } finally {
        setLoading(false);
      }
    },
    [page, perPage, t],
  );

  useEffect(() => {
    void fetchData(page);
  }, [fetchData, page]);

  const lastPage = useMemo(() => {
    if (!meta) return 1;
    return Math.max(1, Math.ceil(meta.returned / meta.per_page));
  }, [meta]);

  return (
    <div className="p-6 space-y-6">
      <PageHeader
        title={t('admin.audit_recent.title')}
        subtitle={t('admin.audit_recent.subtitle')}
        icon={IconHistory}
        iconTone="admin"
        actions={
          <Button
            variant="secondary"
            onClick={() => void fetchData(page)}
            disabled={loading}
          >
            <IconRefresh className="h-4 w-4 me-2" />
            {t('common.refresh')}
          </Button>
        }
      />

      {error && <Alert variant="danger">{error}</Alert>}

      {!error && !loading && data.length === 0 && (
        <EmptyState
          icon={IconHistory}
          title={t('admin.audit_recent.empty.title')}
          description={t('admin.audit_recent.empty.description')}
        />
      )}

      <Card data-testid="audit-recent-card">
        <CardContent>
          <CardTitle>{t('admin.audit_recent.list_title')}</CardTitle>
          <p className="text-xs text-[var(--text-tertiary)] mt-1">
            {meta
              ? t('admin.audit_recent.list_subtitle', {
                  returned: meta.returned,
                  limit: meta.limit,
                })
              : t('common.loading')}
          </p>

          {data.length > 0 && (
            <>
              <ul
                className="mt-4 divide-y divide-[var(--border-default)]"
                data-testid="audit-recent-list"
              >
                {data.map((row) => (
                  <li
                    key={row.id}
                    className="py-3 flex items-start justify-between gap-3"
                    data-testid="audit-recent-row"
                  >
                    <span className="flex items-start gap-3 min-w-0">
                      <span className="shrink-0 mt-0.5">
                        <StatusBadge
                          type="custom"
                          color={ACTION_PALETTE[row.action] ?? 'secondary'}
                          status={row.action}
                          label={row.action}
                        />
                      </span>
                      <span className="min-w-0">
                        <span className="block text-sm text-[var(--text-primary)] truncate">
                          {row.description ?? '–'}
                        </span>
                        <span className="block text-xs text-[var(--text-tertiary)] mt-0.5 truncate">
                          {row.actor ? row.actor.name : t('admin.audit_recent.system')}
                          {row.target_user &&
                            ` → ${row.target_user.name}`}
                          {row.scope_type && (
                            <>
                              {' · '}
                              {row.scope_type}
                              {row.role ? `:${row.role}` : ''}
                            </>
                          )}
                        </span>
                      </span>
                    </span>
                    <span className="shrink-0 text-xs text-[var(--text-tertiary)] whitespace-nowrap">
                      {row.created_at ? formatDateTime(row.created_at) : '–'}
                    </span>
                  </li>
                ))}
              </ul>

              <nav
                className="mt-4 flex items-center justify-between gap-3 text-sm"
                aria-label={t('admin.audit_recent.pagination_label')}
              >
                <Button
                  variant="secondary"
                  onClick={() => setPage((p) => Math.max(1, p - 1))}
                  disabled={loading || page <= 1}
                  data-testid="audit-recent-prev"
                >
                  {t('common.previous')}
                </Button>
                <span className="text-[var(--text-tertiary)]" data-testid="audit-recent-page-indicator">
                  {t('common.of', { current: page, last: lastPage })}
                </span>
                <Button
                  variant="secondary"
                  onClick={() => setPage((p) => p + 1)}
                  disabled={loading || data.length < perPage}
                  data-testid="audit-recent-next"
                >
                  {t('common.next')}
                </Button>
              </nav>
            </>
          )}
        </CardContent>
      </Card>
    </div>
  );
};

export default AuditRecentPage;
