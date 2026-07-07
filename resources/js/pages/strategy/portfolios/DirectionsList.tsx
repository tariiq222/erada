import React, { useCallback, useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { portfoliosApi } from '@entities/strategy';
import { Button, Select, Progress, StatusBadge } from '@shared/ui';
import type { CustomBadgeColor } from '@shared/ui';
import {
  PageHeader,
  FilterBar,
  DataTable,
  RowAction,
} from '@shared/ui';
import type { DataTableColumn } from '@shared/ui';
import { useToast } from '@shared/ui/Toast';
import {IconPlus, IconBriefcase, IconBuilding, IconTarget, IconEye, IconPencil, IconTrash} from '@tabler/icons-react';
import {
  type Portfolio,
  type PaginatedResponse,
  statusLabels,
  statusVariants,
  DeletePortfolioModal,
} from './list';

const variantToColor: Record<string, CustomBadgeColor> = {
  default: 'secondary',
  accent: 'primary',
  success: 'success',
  warning: 'warning',
  danger: 'danger',
};

const DirectionsList: React.FC = () => {
  const { t } = useTranslation();
  const { showToast } = useToast();
  const [searchParams, setSearchParams] = useSearchParams();

  const [portfolios, setPortfolios] = useState<Portfolio[]>([]);
  const [loading, setLoading] = useState(true);
  const [pagination, setPagination] = useState({ currentPage: 1, lastPage: 1, total: 0 });

  const [filters, setFilters] = useState({
    search: searchParams.get('search') || '',
    status: searchParams.get('status') || '',
  });
  const [searchInput, setSearchInput] = useState(filters.search);

  const [deleteModal, setDeleteModal] = useState<{ isOpen: boolean; portfolio: Portfolio | null }>({
    isOpen: false,
    portfolio: null,
  });
  const [isDeleting, setIsDeleting] = useState(false);

  const fetchPortfolios = useCallback(
    async (page = 1) => {
      setLoading(true);
      try {
        const params: Record<string, string> = { page: String(page) };
        if (filters.search) params.search = filters.search;
        if (filters.status) params.status = filters.status;
        const res = (await portfoliosApi.getAll(params)) as PaginatedResponse;
        setPortfolios(res.data);
        setPagination({ currentPage: res.current_page, lastPage: res.last_page, total: res.total });
      } catch (error) {
        console.error('Failed to fetch portfolios:', error);
      } finally {
        setLoading(false);
      }
    },
    [filters]
  );

  useEffect(() => {
    fetchPortfolios(1);
  }, [fetchPortfolios]);

  // مزامنة الفلاتر مع الـ URL
  useEffect(() => {
    const sp = new URLSearchParams();
    if (filters.search) sp.set('search', filters.search);
    if (filters.status) sp.set('status', filters.status);
    setSearchParams(sp, { replace: true });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [filters]);

  // بحث فوري بتأخير بسيط
  useEffect(() => {
    const id = setTimeout(() => {
      setFilters((f) => (f.search === searchInput ? f : { ...f, search: searchInput }));
    }, 350);
    return () => clearTimeout(id);
  }, [searchInput]);

  const handleDelete = async () => {
    if (!deleteModal.portfolio) return;
    setIsDeleting(true);
    try {
      await portfoliosApi.delete(deleteModal.portfolio.id);
      showToast('success', t('strategy.portfolio_delete_success', { name: deleteModal.portfolio.name }));
      setDeleteModal({ isOpen: false, portfolio: null });
      fetchPortfolios(pagination.currentPage);
    } catch (error: any) {
      showToast('error', error.message || t('strategy.portfolio_delete_error'));
    } finally {
      setIsDeleting(false);
    }
  };

  const clearFilters = () => {
    setSearchInput('');
    setFilters({ search: '', status: '' });
  };

  const columns: DataTableColumn<Portfolio>[] = [
    {
      key: 'name',
      header: t('strategy.portfolio'),
      render: (p) => (
        <div className="flex items-center gap-3">
          <div className="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-[var(--support-indigo-subtle)] text-[var(--support-indigo-text)]">
            <IconBriefcase className="h-4 w-4" />
          </div>
          <div className="min-w-0">
            <p className="truncate font-semibold text-[var(--text-primary)] transition-colors group-hover:text-[var(--accent-default)]">
              {p.name}
            </p>
            <p className="truncate text-xs text-[var(--text-tertiary)]">{p.code}</p>
          </div>
        </div>
      ),
    },
    {
      key: 'directive',
      header: t('strategy.directive_source'),
      hideBelow: 'lg',
      render: (p) =>
        p.directive_source_label ? (
          <span className="inline-flex items-center gap-1 text-sm text-[var(--text-secondary)]">
            <IconBuilding className="h-3.5 w-3.5 text-[var(--text-tertiary)]" />
            {p.directive_source_label}
          </span>
        ) : (
          <span className="text-sm text-[var(--text-tertiary)]">–</span>
        ),
    },
    {
      key: 'status',
      header: t('common.status'),
      render: (p) => (
        <StatusBadge
          type="custom"
          status={p.status}
          color={variantToColor[statusVariants[p.status]] ?? 'secondary'}
          label={t(statusLabels[p.status]) || p.status_label}
          size="sm"
        />
      ),
    },
    {
      key: 'progress',
      header: t('strategy.progress'),
      width: 'w-40',
      hideBelow: 'md',
      render: (p) => (
        <div className="flex items-center gap-2">
          <Progress value={p.progress} size="sm" className="flex-1" />
          <span className="w-9 shrink-0 text-end text-xs tabular-nums text-[var(--text-tertiary)]">
            {Math.round(p.progress)}%
          </span>
        </div>
      ),
    },
    {
      key: 'objectives',
      header: t('strategy.objectives'),
      align: 'center',
      hideBelow: 'sm',
      render: (p) => (
        <span className="inline-flex items-center gap-1 text-sm text-[var(--text-secondary)]">
          <IconTarget className="h-3.5 w-3.5 text-[var(--text-tertiary)]" />
          {p.objectives_count}
        </span>
      ),
    },
    {
      key: 'programs',
      header: t('strategy.programs'),
      align: 'center',
      hideBelow: 'sm',
      render: (p) => (
        <span className="text-sm tabular-nums text-[var(--text-secondary)]">{p.programs_count || 0}</span>
      ),
    },
  ];

  return (
    <div className="space-y-4 sm:space-y-6">
      <PageHeader
        title={t('strategy.portfolios')}
        subtitle={t('strategy.portfolios_subtitle')}
        icon={IconBriefcase}
        iconTone="project"
        actions={
          <Link to="/strategy/portfolios/new">
            <Button leftIcon={<IconPlus className="h-4 w-4" />} size="sm">
              {t('strategy.new_portfolio')}
            </Button>
          </Link>
        }
      />

      <DataTable
        data={portfolios}
        loading={loading}
        rowKey={(p) => p.id}
        columns={columns}
        rowHref={(p) => `/strategy/portfolios/${p.id}`}
        pagination={{
          currentPage: pagination.currentPage,
          lastPage: pagination.lastPage,
          total: pagination.total,
          onPageChange: (page) => fetchPortfolios(page),
        }}
        toolbar={
          <FilterBar
            search={searchInput}
            onSearchChange={setSearchInput}
            searchPlaceholder={t('common.searchByNameOrCode')}
            hasActiveFilters={!!(filters.search || filters.status)}
            onClear={clearFilters}
          >
            <Select
              value={filters.status}
              onChange={(e) => setFilters((f) => ({ ...f, status: e.target.value }))}
              options={[
                { value: '', label: t('common.allStatuses') },
                ...Object.entries(statusLabels).map(([value, label]) => ({ value, label: t(label) })),
              ]}
            />
          </FilterBar>
        }
        empty={{
          icon: IconBriefcase,
          title: t('strategy.no_portfolios'),
          description: t('strategy.no_portfolios_desc'),
          action: (
            <Link to="/strategy/portfolios/new">
              <Button leftIcon={<IconPlus className="h-4 w-4" />}>{t('strategy.create_new_portfolio')}</Button>
            </Link>
          ),
        }}
        actions={(p) => (
          <>
            <RowAction icon={IconEye} label={t('common.view')} to={`/strategy/portfolios/${p.id}`} />
            <RowAction icon={IconPencil} label={t('common.edit')} to={`/strategy/portfolios/${p.id}/edit`} />
            <RowAction
              icon={IconTrash}
              label={t('common.delete')}
              tone="danger"
              onClick={() => setDeleteModal({ isOpen: true, portfolio: p })}
            />
          </>
        )}
      />

      <DeletePortfolioModal
        isOpen={deleteModal.isOpen}
        portfolio={deleteModal.portfolio}
        isDeleting={isDeleting}
        onClose={() => setDeleteModal({ isOpen: false, portfolio: null })}
        onConfirm={handleDelete}
      />
    </div>
  );
};

export default DirectionsList;
