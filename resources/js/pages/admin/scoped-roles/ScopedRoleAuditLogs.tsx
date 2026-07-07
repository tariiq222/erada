import React, { useCallback, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import {IconHistory, IconShieldCheck} from '@tabler/icons-react';
import { PageHeader, FilterBar, DataTable, Select, Input, DatePicker, StatusBadge, Alert } from '@shared/ui';
import type { DataTableColumn, SelectOption } from '@shared/ui';
import { scopedRolesApi, type ScopedRoleAuditLog } from '@entities/scoped-role';
import { formatDateTime } from '@shared/lib/utils';

interface MetaState {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

const ScopedRoleAuditLogs: React.FC<{ embedded?: boolean }> = ({ embedded }) => {
  const { t } = useTranslation();

  const ACTION_LABELS: Record<string, string> = {
    role_assigned: t('admin.scopedRolesAudit.actions.role_assigned'),
    role_revoked: t('admin.scopedRolesAudit.actions.role_revoked'),
    role_updated: t('admin.scopedRolesAudit.actions.role_updated'),
    permission_granted: t('admin.scopedRolesAudit.actions.permission_granted'),
    permission_revoked: t('admin.scopedRolesAudit.actions.permission_revoked'),
    access_denied: t('admin.scopedRolesAudit.actions.access_denied'),
  };

  const SCOPE_LABELS: Record<string, string> = {
    project: t('admin.scopedRolesAudit.scopes.project'),
    department: t('admin.scopedRolesAudit.scopes.department'),
  };

  const [data, setData] = useState<ScopedRoleAuditLog[]>([]);
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
      const res = await scopedRolesApi.auditLogs({
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
    { value: '', label: t('admin.scopedRolesAudit.filters.allActions') },
    ...Object.keys(ACTION_LABELS).map((key) => ({
      value: key,
      label: ACTION_LABELS[key],
    })),
  ];

  const scopeOptions: SelectOption[] = [
    { value: '', label: t('admin.scopedRolesAudit.filters.allScopes') },
    { value: 'project', label: SCOPE_LABELS['project'] },
    { value: 'department', label: SCOPE_LABELS['department'] },
  ];

  const columns: DataTableColumn<ScopedRoleAuditLog>[] = [
    {
      key: 'user',
      header: t('admin.scopedRolesAudit.fields.user'),
      render: (row) => (
        <span className="text-[var(--text-primary)]">{row.user?.name || '–'}</span>
      ),
    },
    {
      key: 'action',
      header: t('admin.scopedRolesAudit.fields.action'),
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
      header: t('admin.scopedRolesAudit.fields.targetUser'),
      render: (row) => (
        <span className="text-[var(--text-primary)]">{row.target_user?.name || '–'}</span>
      ),
    },
    {
      key: 'scope',
      header: t('admin.scopedRolesAudit.fields.scope'),
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
      header: t('admin.scopedRolesAudit.fields.ip'),
      hideBelow: 'lg',
      render: (row) => (
        <span className="font-mono text-xs text-[var(--text-primary)]">
          {row.ip_address || '–'}
        </span>
      ),
    },
    {
      key: 'created_at',
      header: t('admin.scopedRolesAudit.fields.date'),
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
        title={t('admin.scopedRolesAudit.title')}
        subtitle={t('admin.scopedRolesAudit.subtitle')}
        icon={IconShieldCheck}
        iconTone="admin"
      />

      {error && (
        <Alert variant="danger">{error}</Alert>
      )}

      <FilterBar
        search={search}
        onSearchChange={setSearch}
        searchPlaceholder={t('admin.scopedRolesAudit.filters.searchPlaceholder')}
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
          placeholder={t('admin.scopedRolesAudit.filters.userId')}
          onBlur={applyFilters}
        />
        <DatePicker
          value={fromDate}
          onChange={(value) => {
            setFromDate(value);
            if (page !== 1) setPage(1);
          }}
          aria-label={t('admin.scopedRolesAudit.filters.fromDate')}
          title={t('admin.scopedRolesAudit.filters.fromDate')}
        />
        <DatePicker
          value={toDate}
          onChange={(value) => {
            setToDate(value);
            if (page !== 1) setPage(1);
          }}
          aria-label={t('admin.scopedRolesAudit.filters.toDate')}
          title={t('admin.scopedRolesAudit.filters.toDate')}
        />
      </FilterBar>

      <DataTable<ScopedRoleAuditLog>
        columns={columns}
        data={filteredData}
        rowKey={(row) => row.id}
        loading={loading}
        empty={{
          icon: IconHistory,
          title: t('admin.scopedRolesAudit.empty'),
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

export default ScopedRoleAuditLogs;
