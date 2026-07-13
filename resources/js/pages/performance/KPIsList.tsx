import React, { useCallback, useEffect, useRef, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import {
  IconDownload,
  IconEye,
  IconFileSpreadsheet,
  IconPencil,
  IconPlus,
  IconTarget,
  IconTrash,
  IconTrendingUp,
  IconUpload,
  IconUser,
} from '@tabler/icons-react';
import { performanceApi, type PerformanceKPI, type PerformanceKPIExportFormat } from '@entities/performance';
import { useCan } from '@shared/api/access';
import { useOrganization } from '@shared/contexts/OrganizationContext';
import {
  Button,
  DataTable,
  DeleteConfirmationModal,
  FilterBar,
  Input,
  PageHeader,
  Progress,
  RowAction,
  Select,
  StatusBadge,
} from '@shared/ui';
import type { DataTableColumn } from '@shared/ui';
import { useToast } from '@shared/ui/Toast';
import {
  achievement,
  displayValue,
  getErrorMessage,
  KPI_STATUSES,
  performanceColor,
  performanceLabelKey,
  statusColor,
  statusLabelKey,
} from './shared';

const KPIsList: React.FC = () => {
  const { t } = useTranslation();
  const { showToast } = useToast();
  const showToastRef = useRef(showToast);
  const { currentOrganization } = useOrganization();
  const [searchParams, setSearchParams] = useSearchParams();
  const fileInputRef = useRef<HTMLInputElement>(null);

  const [kpis, setKpis] = useState<PerformanceKPI[]>([]);
  const [loading, setLoading] = useState(true);
  const [exportingFormat, setExportingFormat] = useState<PerformanceKPIExportFormat | null>(null);
  const [isImporting, setIsImporting] = useState(false);
  const [pagination, setPagination] = useState({ currentPage: 1, lastPage: 1, total: 0 });
  const [filters, setFilters] = useState({
    search: searchParams.get('search') || '',
    status: searchParams.get('status') || '',
    category: searchParams.get('category') || '',
  });
  const [searchInput, setSearchInput] = useState(filters.search);
  const [deleteModal, setDeleteModal] = useState<{ isOpen: boolean; kpi: PerformanceKPI | null }>({
    isOpen: false,
    kpi: null,
  });
  const [isDeleting, setIsDeleting] = useState(false);

  // KPI CRUD gates use the canonical `kpis.*` capabilities matching the
  // backend authorization. The create path resolves to `kpis.manage` per
  // `KpiController::authorizePerformance` (lines 188-191: the `match` arm
  // maps 'create' / 'update' / 'delete' to `Capability::KPIS_MANAGE`),
  // `StoreKpiRequest::authorize()` (line 37), and `KpiPolicy::create()`
  // (line 104). The `Capability::KPIS_CREATE = 'kpis.create'` constant is
  // defined but unused in any controller/policy/request — using it on the
  // SPA would let `kpis.create`-only users reach the form only to hit a
  // backend 403, and would hide the button from `kpis.manage`-only users
  // the engine accepts. So the create gate is `kpis.manage` only.
  const canManageKpi = useCan('kpis.manage');
  const canEditKpi = useCan('kpis.edit');
  const canDeleteKpi = useCan('kpis.delete');
  const canCreate = canManageKpi;
  const canEdit = canEditKpi || canManageKpi;
  const canDelete = canDeleteKpi || canManageKpi;
  const canImport = canCreate && canEdit;

  useEffect(() => {
    showToastRef.current = showToast;
  }, [showToast]);

  const fetchKpis = useCallback(
    async (page = 1) => {
      setLoading(true);
      try {
        const res = await performanceApi.listKPIs({
          page,
          search: filters.search,
          status: filters.status,
          category: filters.category,
        });
        setKpis(res.data);
        setPagination({
          currentPage: res.current_page ?? page,
          lastPage: res.last_page ?? 1,
          total: res.total ?? res.data.length,
        });
      } catch (error) {
        console.error('Failed to fetch performance KPIs:', error);
        showToastRef.current('error', t('performance.load_error'));
      } finally {
        setLoading(false);
      }
    },
    [filters, t],
  );

  useEffect(() => {
    fetchKpis(1);
  }, [fetchKpis]);

  useEffect(() => {
    const sp = new URLSearchParams();
    if (filters.search) sp.set('search', filters.search);
    if (filters.status) sp.set('status', filters.status);
    if (filters.category) sp.set('category', filters.category);
    setSearchParams(sp, { replace: true });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [filters]);

  useEffect(() => {
    const id = setTimeout(() => {
      setFilters((current) => (current.search === searchInput ? current : { ...current, search: searchInput }));
    }, 350);
    return () => clearTimeout(id);
  }, [searchInput]);

  const clearFilters = () => {
    setSearchInput('');
    setFilters({ search: '', status: '', category: '' });
  };

  const handleExport = async (format: PerformanceKPIExportFormat) => {
    setExportingFormat(format);
    try {
      const blob = await performanceApi.exportKPIs(format, {
        search: filters.search,
        status: filters.status,
        category: filters.category,
      });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `performance-kpis-${new Date().toISOString().slice(0, 10)}.${format}`;
      document.body.appendChild(a);
      a.click();
      URL.revokeObjectURL(url);
      document.body.removeChild(a);
      showToast('success', t('performance.export_success'));
    } catch (error: unknown) {
      showToast('error', getErrorMessage(error, t('performance.export_error')));
    } finally {
      setExportingFormat(null);
    }
  };

  const handleImportFile = async (event: React.ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0];
    if (!file) return;

    setIsImporting(true);
    try {
      const summary = await performanceApi.importKPIs(file, currentOrganization?.id);
      showToast(
        'success',
        t('performance.import_success', {
          created: summary.created,
          updated: summary.updated,
          skipped: summary.skipped,
        }),
      );
      fetchKpis(1);
    } catch (error: unknown) {
      showToast('error', getErrorMessage(error, t('performance.import_error')));
    } finally {
      setIsImporting(false);
      event.target.value = '';
    }
  };

  const handleDelete = async () => {
    if (!deleteModal.kpi) return;
    setIsDeleting(true);
    try {
      await performanceApi.deleteKPI(deleteModal.kpi.id);
      showToast('success', t('performance.delete_success'));
      setDeleteModal({ isOpen: false, kpi: null });
      fetchKpis(pagination.currentPage);
    } catch (error: unknown) {
      showToast('error', getErrorMessage(error, t('performance.delete_error')));
    } finally {
      setIsDeleting(false);
    }
  };

  const hasActiveFilters = !!(filters.search || filters.status || filters.category);

  const columns: DataTableColumn<PerformanceKPI>[] = [
    {
      key: 'name',
      header: t('performance.kpi'),
      render: (kpi) => (
        <div className="flex items-center gap-3">
          <div className="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-[var(--support-indigo-subtle)] text-[var(--support-indigo-text)]">
            <IconTarget className="h-4 w-4" />
          </div>
          <div className="min-w-0">
            <p className="truncate font-semibold text-[var(--text-primary)] transition-colors group-hover:text-[var(--accent-default)]">
              {kpi.name}
            </p>
            <p className="truncate text-xs text-[var(--text-tertiary)]">{kpi.code || '-'}</p>
          </div>
        </div>
      ),
    },
    {
      key: 'status',
      header: t('common.status'),
      render: (kpi) => (
        <StatusBadge
          type="custom"
          status={kpi.status || 'active'}
          color={statusColor(kpi.status)}
          label={t(statusLabelKey(kpi.status))}
          size="sm"
        />
      ),
    },
    {
      key: 'performance',
      header: t('performance.performance_status'),
      hideBelow: 'md',
      render: (kpi) => (
        <StatusBadge
          type="custom"
          status={kpi.performance_status || 'unknown'}
          color={performanceColor(kpi.performance_status)}
          label={t(performanceLabelKey(kpi.performance_status))}
          size="sm"
        />
      ),
    },
    {
      key: 'progress',
      header: t('common.progress'),
      width: 'w-44',
      render: (kpi) => {
        const value = achievement(kpi);
        return (
          <div className="flex items-center gap-2">
            <Progress value={value} size="sm" className="flex-1" />
            <span className="w-10 shrink-0 text-end text-xs tabular-nums text-[var(--text-tertiary)]">{value}%</span>
          </div>
        );
      },
    },
    {
      key: 'target',
      header: t('common.target'),
      hideBelow: 'lg',
      render: (kpi) => <span className="text-sm text-[var(--text-secondary)]">{displayValue(kpi.target, kpi.unit)}</span>,
    },
    {
      key: 'category',
      header: t('performance.category'),
      hideBelow: 'lg',
      render: (kpi) => <span className="text-sm text-[var(--text-secondary)]">{kpi.category || '-'}</span>,
    },
    {
      key: 'owner',
      header: t('common.owner'),
      hideBelow: 'lg',
      render: (kpi) =>
        kpi.owner ? (
          <span className="inline-flex items-center gap-1 text-sm text-[var(--text-secondary)]">
            <IconUser className="h-3.5 w-3.5 text-[var(--text-tertiary)]" />
            {kpi.owner.name}
          </span>
        ) : (
          <span className="text-sm text-[var(--text-tertiary)]">-</span>
        ),
    },
  ];

  return (
    <div className="space-y-4 sm:space-y-6">
      <PageHeader
        title={t('performance.kpis')}
        subtitle={t('performance.subtitle')}
        icon={IconTrendingUp}
        iconTone="project"
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <Button
              variant="secondary"
              size="sm"
              loading={exportingFormat === 'csv'}
              leftIcon={<IconDownload className="h-4 w-4" />}
              onClick={() => handleExport('csv')}
            >
              {t('performance.export_csv')}
            </Button>
            <Button
              variant="secondary"
              size="sm"
              loading={exportingFormat === 'xlsx'}
              leftIcon={<IconFileSpreadsheet className="h-4 w-4" />}
              onClick={() => handleExport('xlsx')}
            >
              {t('performance.export_xlsx')}
            </Button>
            {canImport && (
              <>
                <input
                  ref={fileInputRef}
                  type="file"
                  className="hidden"
                  accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                  onChange={handleImportFile}
                />
                <Button
                  variant="secondary"
                  size="sm"
                  loading={isImporting}
                  leftIcon={<IconUpload className="h-4 w-4" />}
                  onClick={() => fileInputRef.current?.click()}
                >
                  {t('performance.import_file')}
                </Button>
              </>
            )}
            {canCreate && (
              <Link to="/performance/kpis/new">
                <Button leftIcon={<IconPlus className="h-4 w-4" />} size="sm">
                  {t('performance.new_kpi')}
                </Button>
              </Link>
            )}
          </div>
        }
      />

      <DataTable
        data={kpis}
        loading={loading}
        rowKey={(kpi) => kpi.id}
        columns={columns}
        rowHref={(kpi) => `/performance/kpis/${kpi.id}`}
        minWidth="920px"
        pagination={{
          currentPage: pagination.currentPage,
          lastPage: pagination.lastPage,
          total: pagination.total,
          onPageChange: (page) => fetchKpis(page),
        }}
        toolbar={
          <FilterBar
            search={searchInput}
            onSearchChange={setSearchInput}
            searchPlaceholder={t('common.searchByNameOrCode')}
            hasActiveFilters={hasActiveFilters}
            onClear={clearFilters}
          >
            <Select
              value={filters.status}
              onChange={(event) => setFilters((current) => ({ ...current, status: event.target.value }))}
              options={[
                { value: '', label: t('common.allStatuses') },
                ...KPI_STATUSES.map((status) => ({ value: status.value, label: t(status.labelKey) })),
              ]}
            />
            <Input
              value={filters.category}
              onChange={(event) => setFilters((current) => ({ ...current, category: event.target.value }))}
              placeholder={t('performance.category_filter_placeholder')}
            />
          </FilterBar>
        }
        empty={{
          icon: IconTrendingUp,
          title: t('performance.no_kpis'),
          description: t('performance.no_kpis_desc'),
          action: canCreate ? (
            <Link to="/performance/kpis/new">
              <Button leftIcon={<IconPlus className="h-4 w-4" />}>{t('performance.create_kpi')}</Button>
            </Link>
          ) : undefined,
        }}
        actions={(kpi) => (
          <>
            <RowAction icon={IconEye} label={t('common.view')} to={`/performance/kpis/${kpi.id}`} />
            {canEdit && <RowAction icon={IconPencil} label={t('common.edit')} to={`/performance/kpis/${kpi.id}/edit`} />}
            {canDelete && (
              <RowAction
                icon={IconTrash}
                label={t('common.delete')}
                tone="danger"
                onClick={() => setDeleteModal({ isOpen: true, kpi })}
              />
            )}
          </>
        )}
      />

      <DeleteConfirmationModal
        isOpen={deleteModal.isOpen}
        item={deleteModal.kpi}
        title={t('performance.delete_title')}
        itemName={deleteModal.kpi?.name || ''}
        itemSubtitle={deleteModal.kpi?.code || undefined}
        warningMessage={t('performance.delete_warning')}
        confirmButtonText={t('common.delete')}
        isDeleting={isDeleting}
        onClose={() => setDeleteModal({ isOpen: false, kpi: null })}
        onConfirm={handleDelete}
      />
    </div>
  );
};

export default KPIsList;
