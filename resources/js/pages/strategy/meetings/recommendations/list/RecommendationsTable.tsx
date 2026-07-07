import React from 'react';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { IconClipboardCheck, IconEye, IconPencil } from '@shared/ui/icons';
import { Badge, DataTable, RowAction, StatusBadge } from '@shared/ui';
import type { DataTableColumn } from '@shared/ui';
import type { Recommendation } from '@features/meetings/types';
import { STATUS_COLORS, PRIORITY_COLORS } from './constants';

interface Props {
  recommendations: Recommendation[];
  loading: boolean;
  canEdit: boolean;
  currentPage: number;
  lastPage: number;
  total: number;
  onPageChange: (page: number) => void;
}

const RecommendationsTable: React.FC<Props> = ({
  recommendations,
  loading,
  canEdit,
  currentPage,
  lastPage,
  total,
  onPageChange,
}) => {
  const { t } = useTranslation();

  const columns: DataTableColumn<Recommendation>[] = [
    {
      key: 'reference_number',
      header: t('meetings.recommendation.fields.reference_number'),
      render: (r) => (
        <span className="font-mono text-xs text-[var(--text-secondary)]">{r.reference_number}</span>
      ),
    },
    {
      key: 'title',
      header: t('meetings.recommendation.fields.title'),
      render: (r) => (
        <Link
          to={`/strategy/meetings/recommendations/${r.id}`}
          className="font-medium text-[var(--text-primary)] hover:text-[var(--accent-default)]"
        >
          {r.title}
        </Link>
      ),
    },
    {
      key: 'decision',
      header: t('meetings.recommendation.fields.decision'),
      render: (r) => (
        <Link
          to={`/strategy/decisions/${r.decision_id}`}
          className="text-sm text-[var(--accent-default)] hover:underline"
        >
          {r.decision
            ? `${r.decision.reference_number}: ${r.decision.title}`
            : `#${r.decision_id}`}
        </Link>
      ),
    },
    {
      key: 'priority',
      header: t('meetings.recommendation.fields.priority'),
      render: (r) => (
        <StatusBadge
          type="custom"
          status={r.priority}
          label={t(`meetings.recommendation.priorities.${r.priority}`) ?? r.priority_label ?? r.priority}
          color={PRIORITY_COLORS[r.priority] ?? 'secondary'}
          size="sm"
        />
      ),
    },
    {
      key: 'status',
      header: t('meetings.recommendation.fields.status'),
      render: (r) => (
        <StatusBadge
          type="custom"
          status={r.status}
          label={t(`meetings.recommendation.statuses.${r.status}`) || r.status_label}
          color={STATUS_COLORS[r.status] ?? 'secondary'}
          size="sm"
        />
      ),
    },
    {
      key: 'due_date',
      header: t('meetings.recommendation.fields.due_date'),
      hideBelow: 'md',
      render: (r) => (
        <span className="text-sm tabular-nums text-[var(--text-secondary)]">
          {r.due_date ?? '—'}
          {r.is_overdue && (
            <Badge variant="danger" size="sm" className="ms-2">
              {t('meetings.recommendation.fields.overdue_badge')}
            </Badge>
          )}
        </span>
      ),
    },
  ];

  return (
    <DataTable
      data={recommendations}
      loading={loading}
      rowKey={(r) => r.id}
      columns={columns}
      rowHref={(r) => `/strategy/meetings/recommendations/${r.id}`}
      pagination={{ currentPage, lastPage, total, onPageChange }}
      empty={{
        icon: IconClipboardCheck,
        title: t('meetings.recommendation.list.empty'),
      }}
      actions={(r) => (
        <>
          <RowAction
            icon={IconEye}
            label={t('common.view')}
            to={`/strategy/meetings/recommendations/${r.id}`}
          />
          {canEdit && (
            <RowAction
              icon={IconPencil}
              label={t('common.edit')}
              to={`/strategy/meetings/recommendations/${r.id}/edit`}
            />
          )}
        </>
      )}
    />
  );
};

export default RecommendationsTable;
