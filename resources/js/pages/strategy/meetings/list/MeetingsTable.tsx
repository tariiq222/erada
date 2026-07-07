import React from 'react';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { IconCalendar, IconEye, IconPencil } from '@shared/ui/icons';
import { DataTable, RowAction, StatusBadge } from '@shared/ui';
import type { DataTableColumn } from '@shared/ui';
import type { Meeting } from '@features/meetings/types';
import { STATUS_COLORS } from './constants';

interface Props {
  meetings: Meeting[];
  loading: boolean;
  canEdit: boolean;
  currentPage: number;
  lastPage: number;
  total: number;
  onPageChange: (page: number) => void;
}

const MeetingsTable: React.FC<Props> = ({
  meetings, loading, canEdit, currentPage, lastPage, total, onPageChange,
}) => {
  const { t } = useTranslation();

  const columns: DataTableColumn<Meeting>[] = [
    {
      key: 'reference_number',
      header: t('meetings.meeting.fields.reference_number'),
      render: (m) => (
        <span className="font-mono text-xs text-[var(--text-secondary)]">{m.reference_number}</span>
      ),
    },
    {
      key: 'title',
      header: t('meetings.meeting.fields.title'),
      render: (m) => (
        <Link
          to={`/strategy/meetings/${m.id}`}
          className="font-medium text-[var(--text-primary)] hover:text-[var(--accent-default)]"
        >
          {m.title}
        </Link>
      ),
    },
    {
      key: 'subject',
      header: t('meetings.meeting.fields.subject_type'),
      render: (m) => (
        <span className="text-sm text-[var(--text-secondary)]">
          {m.subject ? `${m.subject_type?.split('\\').pop()}: ${m.subject.name}` : '—'}
        </span>
      ),
    },
    {
      key: 'status',
      header: t('meetings.meeting.fields.status'),
      render: (m) => (
        <StatusBadge
          type="custom"
          status={m.status}
          label={t(`meetings.meeting.statuses.${m.status}`) || m.status_label}
          color={STATUS_COLORS[m.status] ?? 'secondary'}
          size="sm"
        />
      ),
    },
    {
      key: 'scheduled_at',
      header: t('meetings.meeting.fields.scheduled_at'),
      hideBelow: 'md',
      render: (m) => (
        <span className="text-sm tabular-nums text-[var(--text-secondary)]">
          {m.scheduled_at.slice(0, 16).replace('T', ' ')}
        </span>
      ),
    },
    {
      key: 'duration',
      header: t('meetings.meeting.fields.duration_minutes'),
      hideBelow: 'lg',
      render: (m) => (
        <span className="text-sm tabular-nums text-[var(--text-secondary)]">{m.duration_minutes} د</span>
      ),
    },
    {
      key: 'organizer',
      header: t('meetings.meeting.fields.organizer'),
      hideBelow: 'lg',
      render: (m) => (
        <span className="text-sm text-[var(--text-secondary)]">
          {m.organizer?.name ?? `#${m.organizer_id}`}
        </span>
      ),
    },
  ];

  return (
    <DataTable
      data={meetings}
      loading={loading}
      rowKey={(m) => m.id}
      columns={columns}
      rowHref={(m) => `/strategy/meetings/${m.id}`}
      pagination={{ currentPage, lastPage, total, onPageChange }}
      empty={{ icon: IconCalendar, title: t('meetings.meeting.list.empty') }}
      actions={(m) => (
        <>
          <RowAction icon={IconEye} label={t('common.view')} to={`/strategy/meetings/${m.id}`} />
          {canEdit && (
            <RowAction icon={IconPencil} label={t('common.edit')} to={`/strategy/meetings/${m.id}/edit`} />
          )}
        </>
      )}
    />
  );
};

export default MeetingsTable;
