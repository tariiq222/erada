import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router-dom';
import {
  Badge,
  Button,
  DataTable,
  FilterField,
  FilterRow,
  PageHeader,
  RowAction,
  Select,
  SkeletonTable,
  StatusBadge,
  Switch,
} from '@shared/ui';
import type { CustomBadgeColor, DataTableColumn } from '@shared/ui';
import { api } from '@shared/api/client';
import {
  IconClipboardCheck,
  IconEye,
  IconPlus,
} from '@shared/ui/icons';

// Resolution kind enum — server contract is `recommendation` | `decision`.
type ResolutionKind = 'recommendation' | 'decision';
type ResolutionStatus =
  | 'open'
  | 'in_progress'
  | 'converted_to_tasks'
  | 'completed'
  | 'cancelled';
type ResolutionPriority = 'low' | 'medium' | 'high' | 'critical';

interface Resolution {
  id: number;
  reference_number: string;
  kind: ResolutionKind;
  title: string;
  description?: string | null;
  status: ResolutionStatus;
  priority: ResolutionPriority;
  due_date: string | null;
  is_overdue?: boolean;
  is_on_hold?: boolean;
  owner?: { id: number; name: string } | null;
  meeting?: { id: number; title: string; reference_number?: string } | null;
  kind_label?: string;
  status_label?: string;
  priority_label?: string;
  // Phase 3: task-progress aggregates surfaced on the list endpoint.
  tasks_count?: number;
  completed_tasks_count?: number;
  pending_tasks_count?: number;
  completion_percentage?: number;
}

interface ResolutionListResponse {
  data: Resolution[];
  current_page?: number;
  last_page?: number;
  per_page?: number;
  total?: number;
  meta?: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
}

interface ResolutionsPageFilters {
  kind: string;
  status: string;
  owner: string;
  overdue_only: boolean;
  page: number;
  per_page: number;
}

const KIND_COLORS: Record<ResolutionKind, CustomBadgeColor> = {
  recommendation: 'info',
  decision: 'primary',
};

const STATUS_COLORS: Record<ResolutionStatus, CustomBadgeColor> = {
  open: 'warning',
  in_progress: 'info',
  converted_to_tasks: 'primary',
  completed: 'success',
  cancelled: 'secondary',
};

const PRIORITY_COLORS: Record<ResolutionPriority, CustomBadgeColor> = {
  low: 'secondary',
  medium: 'info',
  high: 'warning',
  critical: 'danger',
};

const EMPTY_FILTERS: ResolutionsPageFilters = {
  kind: '',
  status: '',
  owner: '',
  overdue_only: false,
  page: 1,
  per_page: 15,
};

const ResolutionsPage: React.FC = () => {
  const { t } = useTranslation();

  const [filters, setFiltersState] = useState<ResolutionsPageFilters>(EMPTY_FILTERS);
  const [resolutions, setResolutions] = useState<Resolution[]>([]);
  const [loading, setLoading] = useState<boolean>(true);
  const [error, setError] = useState<string | null>(null);
  const [pagination, setPagination] = useState({
    currentPage: 1,
    lastPage: 1,
    total: 0,
  });

  const fetchResolutions = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const params: Record<string, string> = {
        page: String(filters.page),
        per_page: String(filters.per_page),
      };
      if (filters.kind) params.kind = filters.kind;
      if (filters.status) params.status = filters.status;
      if (filters.owner) params.owner = filters.owner;
      if (filters.overdue_only) params.overdue_only = '1';

      const qs = new URLSearchParams(params).toString();
      const res = (await api.get(`/meeting-resolutions?${qs}`)) as ResolutionListResponse;

      const data = Array.isArray(res?.data) ? res.data : [];
      const meta = res?.meta ?? {
        current_page: res?.current_page ?? 1,
        last_page: res?.last_page ?? 1,
        per_page: res?.per_page ?? filters.per_page,
        total: res?.total ?? data.length,
      };

      setResolutions(data);
      setPagination({
        currentPage: meta.current_page ?? 1,
        lastPage: meta.last_page ?? 1,
        total: meta.total ?? data.length,
      });
    } catch (err) {
      console.error('Failed to fetch meeting resolutions:', err);
      setError(
        t('common.error_generic', { defaultValue: 'حدث خطأ غير متوقع' }),
      );
      setResolutions([]);
      setPagination({ currentPage: 1, lastPage: 1, total: 0 });
    } finally {
      setLoading(false);
    }
  }, [filters, t]);

  useEffect(() => {
    fetchResolutions();
  }, [fetchResolutions]);

  const setFilter = useCallback(
    <K extends keyof ResolutionsPageFilters>(
      key: K,
      value: ResolutionsPageFilters[K],
    ) => {
      setFiltersState((prev) => ({
        ...prev,
        [key]: value,
        page: key === 'page' ? (value as number) : 1,
      }));
    },
    [],
  );

  const resetFilters = useCallback(() => {
    setFiltersState(EMPTY_FILTERS);
  }, []);

  const columns: DataTableColumn<Resolution>[] = useMemo(
    () => [
      {
        key: 'reference_number',
        header: t('meetings.reference_number.label', {
          defaultValue: 'الرقم المرجعي',
        }),
        render: (r) => (
          <span className="font-mono text-xs text-[var(--text-secondary)]">
            {r.reference_number}
          </span>
        ),
      },
      {
        key: 'title',
        header: t('meetings.resolution.form.title_label', {
          defaultValue: 'العنوان',
        }),
        render: (r) => (
          <span
            className="line-clamp-2 max-w-[20rem] font-medium text-[var(--text-primary)]"
            title={r.title}
          >
            {r.title}
          </span>
        ),
      },
      {
        key: 'kind',
        header: t('meetings.resolution.form.kind_label', {
          defaultValue: 'نوع المخرج',
        }),
        render: (r) => (
          <StatusBadge
            type="custom"
            status={r.kind}
            label={
              t(`meetings.resolution.kinds.${r.kind}`) ||
              r.kind_label ||
              r.kind
            }
            color={KIND_COLORS[r.kind] ?? 'secondary'}
            size="sm"
          />
        ),
      },
      {
        key: 'status',
        header: t('meetings.resolution.list.filters.status', {
          defaultValue: 'الحالة',
        }),
        render: (r) => (
          <div className="flex flex-wrap items-center gap-1.5">
            <StatusBadge
              type="custom"
              status={r.status}
              label={
                t(`meetings.resolution.statuses.${r.status}`) ||
                r.status_label ||
                r.status
              }
              color={STATUS_COLORS[r.status] ?? 'secondary'}
              size="sm"
            />
            {r.is_on_hold && (
              <Badge variant="warning" size="sm" data-testid="hold-indicator">
                {t('meetings.resolution.statuses.on_hold', {
                  defaultValue: 'معلّق',
                })}
              </Badge>
            )}
          </div>
        ),
      },
      {
        key: 'priority',
        header: t('meetings.resolution.form.priority_label', {
          defaultValue: 'الأولوية',
        }),
        render: (r) => (
          <StatusBadge
            type="custom"
            status={r.priority}
            label={
              t(`meetings.resolution.priorities.${r.priority}`) ||
              r.priority_label ||
              r.priority
            }
            color={PRIORITY_COLORS[r.priority] ?? 'secondary'}
            size="sm"
          />
        ),
      },
      {
        key: 'owner',
        header: t('meetings.resolution.form.owner_label', {
          defaultValue: 'المسؤول',
        }),
        hideBelow: 'md',
        render: (r) => (
          <span className="text-sm text-[var(--text-secondary)]">
            {r.owner?.name ?? '—'}
          </span>
        ),
      },
      {
        key: 'meeting',
        header: t('meetings.title', { defaultValue: 'الاجتماعات' }),
        hideBelow: 'lg',
        render: (r) => {
          if (!r.meeting) {
            return (
              <span className="text-sm text-[var(--text-tertiary)]">—</span>
            );
          }
          return (
            <Link
              to={`/strategy/meetings/${r.meeting.id}`}
              className="text-sm text-[var(--accent-default)] hover:underline"
            >
              {r.meeting.title}
            </Link>
          );
        },
      },
      {
        key: 'due_date',
        header: t('meetings.resolution.form.due_date_label', {
          defaultValue: 'تاريخ الاستحقاق',
        }),
        hideBelow: 'md',
        render: (r) => (
          <span className="text-sm tabular-nums text-[var(--text-secondary)]">
            {r.due_date ?? '—'}
            {r.is_overdue && (
              <Badge variant="danger" size="sm" className="ms-2">
                {t('common.overdue', { defaultValue: 'متأخر' })}
              </Badge>
            )}
          </span>
        ),
      },
      // Phase 3 — task-progress aggregates on the follow-up page.
      // Renders only meaningful content (a row with 0 tasks shows "—").
      {
        key: 'tasks_progress',
        header: t('meetings.resolution.tasks_progress.label', {
          defaultValue: 'المهام',
        }),
        hideBelow: 'lg',
        render: (r) => {
          const total = r.tasks_count ?? 0;
          if (total === 0) {
            return <span className="text-sm text-[var(--text-tertiary)]">—</span>;
          }
          const pct = Math.round(r.completion_percentage ?? 0);
          return (
            <div className="flex flex-col gap-1">
              <div className="text-xs tabular-nums text-[var(--text-secondary)]">
                {r.completed_tasks_count ?? 0} / {total}
              </div>
              <div className="h-1 w-24 overflow-hidden rounded-full bg-[var(--surface-muted)]">
                <div
                  className="h-full rounded-full bg-[var(--status-success)]"
                  style={{ width: `${Math.min(100, pct)}%` }}
                />
              </div>
              <div className="text-[10px] tabular-nums text-[var(--text-tertiary)]">{pct}%</div>
            </div>
          );
        },
      },
    ],
    [t],
  );

  return (
    <div className="space-y-4 sm:space-y-6">
      <PageHeader
        icon={IconClipboardCheck}
        iconTone="project"
        title={t('meetings.resolution.list.header', {
          defaultValue: 'متابعة مخرجات الاجتماعات',
        })}
        subtitle={t('meetings.title_en', { defaultValue: 'Meetings' })}
        actions={
          <Button
            leftIcon={<IconPlus className="h-4 w-4" />}
            size="sm"
            disabled
            title={t('common.coming_soon', {
              defaultValue: 'Coming soon',
            })}
          >
            {t('meetings.resolution.list.new_button', {
              defaultValue: 'مخرج جديد',
            })}
          </Button>
        }
      />

      <FilterRow
        onClear={resetFilters}
        clearLabel={t('meetings.meeting.list.filters.clear', {
          defaultValue: 'مسح الفلاتر',
        })}
      >
        <FilterField
          label={t('meetings.resolution.list.filters.kind', {
            defaultValue: 'النوع',
          })}
        >
          <Select
            value={filters.kind}
            onChange={(e) => setFilter('kind', e.target.value)}
            options={[
              {
                value: '',
                label: t('common.all', { defaultValue: 'الكل' }),
              },
              {
                value: 'recommendation',
                label: t('meetings.resolution.kinds.recommendation', {
                  defaultValue: 'توصية',
                }),
              },
              {
                value: 'decision',
                label: t('meetings.resolution.kinds.decision', {
                  defaultValue: 'قرار',
                }),
              },
            ]}
          />
        </FilterField>

        <FilterField
          label={t('meetings.resolution.list.filters.status', {
            defaultValue: 'الحالة',
          })}
        >
          <Select
            value={filters.status}
            onChange={(e) => setFilter('status', e.target.value)}
            options={[
              {
                value: '',
                label: t('common.all', { defaultValue: 'الكل' }),
              },
              {
                value: 'open',
                label: t('meetings.resolution.statuses.open', {
                  defaultValue: 'مفتوح',
                }),
              },
              {
                value: 'in_progress',
                label: t('meetings.resolution.statuses.in_progress', {
                  defaultValue: 'قيد التنفيذ',
                }),
              },
              {
                value: 'converted_to_tasks',
                label: t('meetings.resolution.statuses.converted_to_tasks', {
                  defaultValue: 'محول إلى مهام',
                }),
              },
              {
                value: 'completed',
                label: t('meetings.resolution.statuses.completed', {
                  defaultValue: 'مكتمل',
                }),
              },
              {
                value: 'cancelled',
                label: t('meetings.resolution.statuses.cancelled', {
                  defaultValue: 'ملغى',
                }),
              },
            ]}
          />
        </FilterField>

        <FilterField
          label={t('meetings.resolution.list.filters.owner', {
            defaultValue: 'المسؤول',
          })}
        >
          <input
            type="text"
            value={filters.owner}
            onChange={(e) => setFilter('owner', e.target.value)}
            placeholder={t('common.search', { defaultValue: 'بحث...' })}
            className="w-full rounded-md border border-[var(--border-default)] bg-[var(--surface-base)] px-3 py-1.5 text-sm text-[var(--text-primary)] placeholder:text-[var(--text-tertiary)] focus:border-[var(--accent-default)] focus:outline-none focus:ring-1 focus:ring-[var(--accent-default)]"
          />
        </FilterField>

        <div className="shrink-0">
          <Switch
            checked={filters.overdue_only}
            onChange={(e) => setFilter('overdue_only', e.target.checked)}
            label={t('meetings.resolution.list.filters.overdue', {
              defaultValue: 'متأخرة فقط',
            })}
          />
        </div>
      </FilterRow>

      {loading ? (
        <SkeletonTable rows={6} />
      ) : (
        <DataTable
          data={resolutions}
          rowKey={(r) => r.id}
          columns={columns}
          pagination={{
            currentPage: pagination.currentPage,
            lastPage: pagination.lastPage,
            total: pagination.total,
            onPageChange: (p) => setFilter('page', p),
          }}
          empty={{
            icon: IconClipboardCheck,
            title: t('meetings.resolution.list.empty', {
              defaultValue: 'لا توجد مخرجات للاجتماعات بعد',
            }),
          }}
          actions={(r) => (
            <RowAction
              icon={IconEye}
              label={t('common.view', { defaultValue: 'عرض' })}
              to={r.meeting ? `/strategy/meetings/${r.meeting.id}` : `/strategy/meetings`}
            />
          )}
        />
      )}

      {error && (
        <div className="text-sm text-[var(--status-danger)]">{error}</div>
      )}
    </div>
  );
};

export default ResolutionsPage;
