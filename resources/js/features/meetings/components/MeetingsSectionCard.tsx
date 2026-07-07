import React from 'react';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { StatusBadge, type CustomBadgeColor } from '@shared/ui';
import type { Meeting } from '@features/meetings/types';

const STATUS_COLOR: Record<string, CustomBadgeColor> = {
  scheduled: 'warning',
  in_progress: 'info',
  completed: 'success',
  cancelled: 'secondary',
};

interface Props {
  meeting: Meeting;
}

const MeetingsSectionCard: React.FC<Props> = ({ meeting }) => {
  const { t } = useTranslation();
  return (
    <div className="rounded-md border border-[var(--border-default)] bg-[var(--surface-default)] p-3">
      <div className="flex items-start justify-between gap-2">
        <div className="min-w-0 flex-1">
          <p className="font-mono text-xs text-[var(--text-tertiary)]">
            {meeting.reference_number}
          </p>
          <Link
            to={`/strategy/meetings/${meeting.id}`}
            className="text-sm font-medium text-[var(--text-primary)] hover:text-[var(--accent-default)]"
          >
            {meeting.title}
          </Link>
          <p className="mt-1 text-xs text-[var(--text-secondary)]">
            {meeting.scheduled_at.slice(0, 16).replace('T', ' ')} · {meeting.duration_minutes} د
          </p>
        </div>
        <StatusBadge
          type="custom"
          status={meeting.status}
          label={t(`meetings.meeting.statuses.${meeting.status}`) || meeting.status_label}
          color={STATUS_COLOR[meeting.status] ?? 'secondary'}
          size="sm"
        />
      </div>
    </div>
  );
};

export default MeetingsSectionCard;
