import React, { useCallback, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import {IconHistory, IconShieldCheck} from '@tabler/icons-react';
import { PageHeader, FilterBar, DataTable, Select, Input, DatePicker, StatusBadge, Alert } from '@shared/ui';
import type { DataTableColumn, SelectOption } from '@shared/ui';
import {
  authorizationAssignmentsApi,
  AUTHORIZATION_ASSIGNMENT_AUDIT_EVENT_LIST,
  type AuthorizationAssignmentAuditLog,
} from '@entities/authorization-assignment';
import { formatDateTime } from '@shared/lib/utils';

interface MetaState {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

const AuthorizationAssignmentAuditLogs: React.FC<{ embedded?: boolean }> = ({ embedded }) => {
  const { t } = useTranslation();

  // Per-event translated labels. Where a translation already exists for the
// canonical concept, reuse it; otherwise the raw event text is shown (the
// plan forbids inventing unrelated translation keys).
//   - canonical_assignment_assigned → reuse "role_assigned"
//   - canonical_assignment_revoked → reuse "role_revoked"
//   - canonical_assignment_synced  → no translation → raw event text
//   - role_created                  → no translation → raw event text
//   - role_updated                  → reuse "role_updated"
//   - role_disabled                 → no translation → raw event text
const ACTION_LABELS: Record<string, string> = {
  canonical_assignment_assigned: t('admin.authorizationAssignmentAudit.actions.role_assigned'),
  canonical_assignment_revoked: t('admin.authorizationAssignmentAudit.actions.role_revoked'),
  // canonical_assignment_synced: no pre-existing translation; display event text.
  // role_created: no pre-existing translation; display event text.
  role_updated: t('admin.authorizationAssignmentAudit.actions.role_updated'),
  // role_disabled: no pre-existing translation; display event text.
};

  const SCOPE_LABELS: Record<string, string> = {
    project: t('admin.authorizationAssignmentAudit.scopes.project'),
    department: t('admin.authorizationAssignmentAudit.scopes.department'),
  };

  const [data, setData] = useState<AuthorizationAssignmentAuditLog[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [page, setPage] = useState(1);
  const [meta, setMeta] = useState<MetaState>({
    current_page: 1,
    last_page: 1,
    per_page: 25,
    total: 0,
  });

  // Filter state
  const [search, setSearch] = useState('');
  const [actionFilter, setActionFilter] = useState('');
  const [userIdFilter, setUserIdFilter] = useState('');
  const [scopeTypeFilter, setScopeTypeFilter] = useState('');
  const [fromDate, setFromDate] = useState('');
  const [toDate, setToDate] = useState('');

  const hasActiveFilters =
    actionFilter !== '' ||
    userIdFilter !== '' ||
    scopeTypeFilter !== '' ||
    fromDate !== '' ||
    toDate !== '' ||
    search !== '';

  const fetchData = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await authorizationAssignmentsApi.auditLogs({
        action: actionFilter || undefined,
        user_id: userIdFilter || undefined,
        scope_type: scopeTypeFilter || undefined,
        from_date: fromDate || undefined,
        to_date: toDate || undefined,
        page,
        per_page: 25,
      });
      const m = (res.meta ?? res) as any;
      setData(res.data || []);
      setMeta({
        current_page: m.current_page || 1,
        last_page: m.last_page || 1,
        per_page: m.per_page || 25,
        total: m.total || 0,
      });
    } catch (err: any) {
      setError(err?.message || t('common.error'));
    } finally {
      setLoading(false);
    }
  }, [page, actionFilter, userIdFilter, scopeTypeFilter, fromDate, toDate, t]);

  useEffect(() => {
    fetchData();
  }, [fetchData]);

  const applyFilters = () => {
    if (page !== 1) {
      setPage(1);
    } else {
      fetchData();
    }
  };

  const handleClear = () => {
    setSearch('');
    setActionFilter('');
    setUserIdFilter('');
    setScopeTypeFilter('');
    setFromDate('');
    setToDate('');
    setPage(1);
  };

  // Client-side search filter applied over the fetched page
  const filteredData = search
    ? data.filter((row) => {
        const needle = search.toLowerCase();
        return (
          (row.description || '').toLowerCase().includes(needle) ||
          (row.user?.name || '').toLowerCase().includes(needle) ||
          (row.target_user?.name || '').toLowerCase().includes(needle)
        );
      })
    : data;

  const actionOptions: SelectOption[] = [
    { value: '', label: t('admin.authorizationAssignmentAudit.filters.allActions') },
    ...AUTHORIZATION_ASSIGNMENT_AUDIT_EVENT_LIST.map((event) => ({
      value: event,
      label: ACTION_LABELS[event] || event,
    })),
  ];

  const scopeOptions: SelectOption[] = [
    { value: '', label: t('admin.authorizationAssignmentAudit.filters.allScopes') },
    { value: 'project', label: SCOPE_LABELS['project'] },
    { value: 'department', label: SCOPE_LABELS['department'] },
  ];

  const columns: DataTableColumn<AuthorizationAssignmentAuditLog>[] = [
    {
      key: 'user',
      header: t('admin.authorizationAssignmentAudit.fields.user'),
      render: (row) => (
        <span className="text-[var(--text-primary)]">{row.user?.name || '–'}</span>
      ),
    },
    {
      key: 'action',
      header: t('admin.authorizationAssignmentAudit.fields.action'),
      render: (row) => (
        <div>
          <StatusBadge
            type="custom"
            color="secondary"
            status={row.action}
            label={ACTION_LABELS[row.action] || row.action}
          />
          <p className="text-xs text-[var(--text-tertiary)] mt-0">{row.description}</p>
        </div>
      ),
    },
    {
      key: 'target_user',
      header: t('admin.authorizationAssignmentAudit.fields.targetUser'),
      render: (row) => (
        <span className="text-[var(--text-primary)]">{row.target_user?.name || '–'}</span>
      ),
    },
    {
      key: 'scope',
      header: t('admin.authorizationAssignmentAudit.fields.scope'),
      hideBelow: 'md',
      render: (row) => (
        <div>
          <span className="text-[var(--text-primary)]">
            {(row.scope_type ? SCOPE_LABELS[row.scope_type] || row.scope_type : null) || '–'}
          </span>
          <p className="text-xs text-[var(--text-tertiary)] mt-0">{row.role || '–'}</p>
        </div>
      ),
    },
    {
      key: 'ip_address',
      header: t('admin.authorizationAssignmentAudit.fields.ip'),
      hideBelow: 'lg',
      render: (row) => (
        <span className="font-mono text-xs text-[var(--text-primary)]">
          {row.ip_address || '–'}
        </span>
      ),
    },
    {
      key: 'created_at',
      header: t('admin.authorizationAssignmentAudit.fields.date'),
      render: (row) => (
        <span className="text-xs whitespace-nowrap text-[var(--text-primary)]">
          {formatDateTime(row.created_at)}
        </span>
      ),
    },
  ];

  return (
    <div className={embedded ? 'space-y-6' : 'p-6 space-y-6'}>
<PageHeader
        title={t('admin.authorizationAssignmentAudit.title')}
        subtitle={t('admin.authorizationAssignmentAudit.subtitle')}
        icon={IconShieldCheck}
        iconTone="admin"
      />

      {error && (
        <Alert variant="danger">{error}</Alert>
      )}

      <FilterBar
        search={search}
        onSearchChange={setSearch}
        searchPlaceholder={t('admin.authorizationAssignmentAudit.filters.searchPlaceholder')}
        hasActiveFilters={hasActiveFilters}
        onClear={handleClear}
      >
        <Select
          value={actionFilter}
          onChange={(e) => {
            setActionFilter(e.target.value);
            applyFilters();
          }}
          options={actionOptions}
        />
        <Select
          value={scopeTypeFilter}
          onChange={(e) => {
            setScopeTypeFilter(e.target.value);
            applyFilters();
          }}
          options={scopeOptions}
        />
        <Input
          type="text"
          value={userIdFilter}
          onChange={(e) => setUserIdFilter(e.target.value)}
          placeholder={t('admin.authorizationAssignmentAudit.filters.userId')}
          onBlur={applyFilters}
        />
        <DatePicker
          value={fromDate}
          onChange={(value) => {
            setFromDate(value);
            if (page !== 1) setPage(1);
          }}
          aria-label={t('admin.authorizationAssignmentAudit.filters.fromDate')}
          title={t('admin.authorizationAssignmentAudit.filters.fromDate')}
        />
        <DatePicker
          value={toDate}
          onChange={(value) => {
            setToDate(value);
            if (page !== 1) setPage(1);
          }}
          aria-label={t('admin.authorizationAssignmentAudit.filters.toDate')}
          title={t('admin.authorizationAssignmentAudit.filters.toDate')}
        />
      </FilterBar>

      <DataTable<AuthorizationAssignmentAuditLog>
        columns={columns}
        data={filteredData}
        rowKey={(row) => row.id}
        loading={loading}
        empty={{
          icon: IconHistory,
          title: t('admin.authorizationAssignmentAudit.empty'),
        }}
        pagination={{
          currentPage: meta.current_page,
          lastPage: meta.last_page,
          total: meta.total,
          onPageChange: setPage,
        }}
      />
    </div>
  );
};

export default AuthorizationAssignmentAuditLogs;
