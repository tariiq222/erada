import React from 'react';
import { useTranslation } from 'react-i18next';
import { Card, CardContent, CardHeader, CardTitle, StatusBadge } from '@shared/ui';
import type { Meeting } from '@features/meetings/types';
import { STATUS_COLORS } from '../list/constants';

interface Props { meeting: Meeting }

const MeetingOverview: React.FC<Props> = ({ meeting }) => {
  const { t } = useTranslation();
  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('meetings.meeting.detail.overview')}</CardTitle>
      </CardHeader>
      <CardContent className="space-y-4">
        <div className="flex flex-wrap items-center gap-2">
          <StatusBadge
            type="custom"
            status={meeting.status}
            label={t(`meetings.meeting.statuses.${meeting.status}`) || meeting.status_label}
            color={STATUS_COLORS[meeting.status] ?? 'secondary'}
          />
          <span className="font-mono text-xs text-[var(--text-secondary)]">
            {meeting.reference_number}
          </span>
        </div>

        <dl className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <dt className="text-xs text-[var(--text-tertiary)]">{t('meetings.meeting.fields.scheduled_at')}</dt>
            <dd className="text-sm tabular-nums text-[var(--text-secondary)]">
              {meeting.scheduled_at.slice(0, 16).replace('T', ' ')}
            </dd>
          </div>
          <div>
            <dt className="text-xs text-[var(--text-tertiary)]">{t('meetings.meeting.fields.duration_minutes')}</dt>
            <dd className="text-sm tabular-nums text-[var(--text-secondary)]">{meeting.duration_minutes}</dd>
          </div>
          {meeting.location && (
            <div>
              <dt className="text-xs text-[var(--text-tertiary)]">{t('meetings.meeting.fields.location')}</dt>
              <dd className="text-sm text-[var(--text-secondary)]">{meeting.location}</dd>
            </div>
          )}
          {meeting.virtual_link && (
            <div>
              <dt className="text-xs text-[var(--text-tertiary)]">{t('meetings.meeting.fields.virtual_link')}</dt>
              <dd className="text-sm">
                <a href={meeting.virtual_link} target="_blank" rel="noreferrer"
                   className="text-[var(--accent-default)] hover:underline">{meeting.virtual_link}</a>
              </dd>
            </div>
          )}
          <div>
            <dt className="text-xs text-[var(--text-tertiary)]">{t('meetings.meeting.fields.organizer')}</dt>
            <dd className="text-sm text-[var(--text-secondary)]">{meeting.organizer?.name ?? `#${meeting.organizer_id}`}</dd>
          </div>
          {meeting.subject && (
            <div>
              <dt className="text-xs text-[var(--text-tertiary)]">{t('meetings.meeting.fields.subject_type')}</dt>
              <dd className="text-sm text-[var(--text-secondary)]">
                {meeting.subject.name}
              </dd>
            </div>
          )}
          {meeting.category && (
            <div>
              <dt className="text-xs text-[var(--text-tertiary)]">{t('meetings.meeting.fields.subject_type')}</dt>
              <dd className="text-sm text-[var(--text-secondary)]">
                {meeting.category.name}
              </dd>
            </div>
          )}
        </dl>

        {meeting.description && (
          <div>
            <h4 className="mb-1 text-xs font-medium text-[var(--text-tertiary)]">
              {t('meetings.meeting.fields.description')}
            </h4>
            <p className="text-sm text-[var(--text-primary)] whitespace-pre-wrap">{meeting.description}</p>
          </div>
        )}

        {meeting.agenda && (
          <div>
            <h4 className="mb-1 text-xs font-medium text-[var(--text-tertiary)]">
              {t('meetings.meeting.fields.agenda')}
            </h4>
            <p className="text-sm text-[var(--text-primary)] whitespace-pre-wrap">{meeting.agenda}</p>
          </div>
        )}
      </CardContent>
    </Card>
  );
};

export default MeetingOverview;
