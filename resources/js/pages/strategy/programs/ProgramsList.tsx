import React, { useCallback, useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { programsApi, portfoliosApi } from '@entities/strategy';
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
import {IconPlus, IconRocket, IconBriefcase, IconLayoutKanban, IconEye, IconPencil, IconTrash} from '@tabler/icons-react';
import {
  type Program,
  type PaginatedResponse,
  type PortfolioOption,
  statusLabels,
  statusVariants,
  priorityLabels,
  priorityVariants,
  DeleteProgramModal,
} from './list';

const variantToColor: Record<string, CustomBadgeColor> = {
  default: 'secondary',
  accent: 'primary',
  success: 'success',
  warning: 'warning',
  danger: 'danger',
};

const ProgramsList: React.FC = () => {
  const { t } = useTranslation();
  const { showToast } = useToast();
  const [searchParams, setSearchParams] = useSearchParams();

  const [programs, setPrograms] = useState<Program[]>([]);
  const [portfolios, setPortfolios] = useState<PortfolioOption[]>([]);
  const [loading, setLoading] = useState(true);
  const [pagination, setPagination] = useState({ currentPage: 1, lastPage: 1, total: 0 });

  const [filters, setFilters] = useState({
    search: searchParams.get('search') || '',
    status: searchParams.get('status') || '',
    priority: searchParams.get('priority') || '',
    portfolio_id: searchParams.get('portfolio_id') || '',
  });
  const [searchInput, setSearchInput] = useState(filters.search);

  const [deleteModal, setDeleteModal] = useState<{ isOpen: boolean; program: Program | null }>({
    isOpen: false,
    program: null,
  });
  const [isDeleting, setIsDeleting] = useState(false);

  const fetchPrograms = useCallback(
    async (page = 1) => {
      setLoading(true);
      try {
        const params: Record<string, string> = { page: String(page) };
        if (filters.search) params.search = filters.search;
        if (filters.status) params.status = filters.status;
        if (filters.priority) params.priority = filters.priority;
        if (filters.portfolio_id) params.portfolio_id = filters.portfolio_id;
        const res = (await programsApi.getAll(params)) as PaginatedResponse;
        setPrograms(res.data);
        setPagination({ currentPage: res.current_page, lastPage: res.last_page, total: res.total });
      } catch (error) {
        console.error('Failed to fetch programs:', error);
      } finally {
        setLoading(false);
      }
    },
    [filters]
  );

  useEffect(() => {
    fetchPrograms(1);
  }, [fetchPrograms]);

  useEffect(() => {
    (async () => {
      try {
        const res = (await portfoliosApi.getList()) as PortfolioOption[];
        setPortfolios(res || []);
      } catch (error) {
        console.error('Failed to fetch portfolios:', error);
      }
    })();
  }, []);

  useEffect(() => {
    const sp = new URLSearchParams();
    if (filters.search) sp.set('search', filters.search);
    if (filters.status) sp.set('status', filters.status);
    if (filters.priority) sp.set('priority', filters.priority);
    if (filters.portfolio_id) sp.set('portfolio_id', filters.portfolio_id);
    setSearchParams(sp, { replace: true });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [filters]);

  useEffect(() => {
    const id = setTimeout(() => {
      setFilters((f) => (f.search === searchInput ? f : { ...f, search: searchInput }));
    }, 350);
    return () => clearTimeout(id);
  }, [searchInput]);

  const handleDelete = async () => {
    if (!deleteModal.program) return;
    setIsDeleting(true);
    try {
      await programsApi.delete(deleteModal.program.id);
      showToast('success', t('strategy.program_delete_success', { name: deleteModal.program.name }));
      setDeleteModal({ isOpen: false, program: null });
      fetchPrograms(pagination.currentPage);
    } catch (error: any) {
      showToast('error', error.message || t('strategy.program_delete_error'));
    } finally {
      setIsDeleting(false);
    }
  };

  const clearFilters = () => {
    setSearchInput('');
    setFilters({ search: '', status: '', priority: '', portfolio_id: '' });
  };

  const hasActiveFilters = !!(
    filters.search ||
    filters.status ||
    filters.priority ||
    filters.portfolio_id
  );

  const columns: DataTableColumn<Program>[] = [
    {
      key: 'name',
      header: t('strategy.program'),
      render: (p) => (
        <div className="flex items-center gap-3">
          <div className="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-[var(--support-indigo-subtle)] text-[var(--support-indigo-text)]">
            <IconRocket className="h-4 w-4" />
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
      key: 'portfolio',
      header: t('strategy.portfolio'),
      hideBelow: 'lg',
      render: (p) =>
        p.portfolio ? (
          <Link
            to={`/strategy/portfolios/${p.portfolio.id}`}
            onClick={(e) => e.stopPropagation()}
            className="inline-flex items-center gap-1 text-sm text-[var(--accent-default)] hover:underline"
          >
            <IconBriefcase className="h-3.5 w-3.5" />
            {p.portfolio.code}
          </Link>
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
      key: 'priority',
      header: t('common.priority'),
      hideBelow: 'md',
      render: (p) => (
        <StatusBadge
          type="custom"
          status={p.priority}
          color={variantToColor[priorityVariants[p.priority]] ?? 'secondary'}
          label={t(priorityLabels[p.priority]) || p.priority_label}
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
      key: 'projects',
      header: t('strategy.projects'),
      align: 'center',
      hideBelow: 'sm',
      render: (p) => (
        <span className="inline-flex items-center gap-1 text-sm text-[var(--text-secondary)]">
          <IconLayoutKanban className="h-3.5 w-3.5 text-[var(--text-tertiary)]" />
          {p.projects_count}
        </span>
      ),
    },
    {
      key: 'manager',
      header: t('strategy.program_manager'),
      hideBelow: 'lg',
      render: (p) =>
        p.program_manager ? (
          <div className="flex items-center gap-2">
            <div className="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-[var(--accent-subtle)] text-xs font-semibold text-[var(--accent-default)]">
              {p.program_manager.name.charAt(0)}
            </div>
            <span className="truncate text-sm text-[var(--text-secondary)]">
              {p.program_manager.name}
            </span>
          </div>
        ) : (
          <span className="text-sm text-[var(--text-tertiary)]">–</span>
        ),
    },
  ];

  return (
    <div className="space-y-4 sm:space-y-6">
      <PageHeader
        title={t('strategy.programs')}
        subtitle={t('strategy.programs_subtitle')}
        icon={IconRocket}
        iconTone="project"
        actions={
          <Link to="/strategy/programs/new">
            <Button leftIcon={<IconPlus className="h-4 w-4" />} size="sm">
              {t('strategy.new_program')}
            </Button>
          </Link>
        }
      />

      <DataTable
        data={programs}
        loading={loading}
        rowKey={(p) => p.id}
        columns={columns}
        rowHref={(p) => `/strategy/programs/${p.id}`}
        minWidth="860px"
        pagination={{
          currentPage: pagination.currentPage,
          lastPage: pagination.lastPage,
          total: pagination.total,
          onPageChange: (page) => fetchPrograms(page),
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
              onChange={(e) => setFilters((f) => ({ ...f, status: e.target.value }))}
              options={[
                { value: '', label: t('common.allStatuses') },
                ...Object.entries(statusLabels).map(([value, label]) => ({ value, label: t(label) })),
              ]}
            />
            <Select
              value={filters.priority}
              onChange={(e) => setFilters((f) => ({ ...f, priority: e.target.value }))}
              options={[
                { value: '', label: t('common.allPriorities') },
                ...Object.entries(priorityLabels).map(([value, label]) => ({ value, label: t(label) })),
              ]}
            />
            <Select
              value={filters.portfolio_id}
              onChange={(e) => setFilters((f) => ({ ...f, portfolio_id: e.target.value }))}
              options={[
                { value: '', label: t('strategy.portfolios.allPortfolios') },
                ...portfolios.map((p) => ({ value: p.id.toString(), label: `${p.code} - ${p.name}` })),
              ]}
            />
          </FilterBar>
        }
        empty={{
          icon: IconRocket,
          title: t('strategy.no_programs'),
          description: t('strategy.no_programs_desc'),
          action: (
            <Link to="/strategy/programs/new">
              <Button leftIcon={<IconPlus className="h-4 w-4" />}>{t('strategy.create_new_program')}</Button>
            </Link>
          ),
        }}
        actions={(p) => (
          <>
            <RowAction icon={IconEye} label={t('common.view')} to={`/strategy/programs/${p.id}`} />
            <RowAction icon={IconPencil} label={t('common.edit')} to={`/strategy/programs/${p.id}/edit`} />
            <RowAction
              icon={IconTrash}
              label={t('common.delete')}
              tone="danger"
              onClick={() => setDeleteModal({ isOpen: true, program: p })}
            />
          </>
        )}
      />

      <DeleteProgramModal
        isOpen={deleteModal.isOpen}
        program={deleteModal.program}
        isDeleting={isDeleting}
        onClose={() => setDeleteModal({ isOpen: false, program: null })}
        onConfirm={handleDelete}
      />
    </div>
  );
};

export default ProgramsList;
