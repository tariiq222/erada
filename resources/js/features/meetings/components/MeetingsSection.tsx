import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { Button, EmptyState, Skeleton } from '@shared/ui';
import { IconCalendar, IconPlus } from '@shared/ui/icons';
import type { DecidableAlias } from '@features/meetings/types';
import MeetingsSectionCard from './MeetingsSectionCard';
import CreateMeetingModal from './CreateMeetingModal';
import { useMeetingsSection } from './useMeetingsSection';

const ALIAS_AR: Record<DecidableAlias, string> = {
  project: 'مشروع',
  portfolio: 'هدف تنفيذي',
  program: 'مبادرة',
  risk: 'مخاطرة',
};

export interface MeetingsSectionProps {
  subject_type: DecidableAlias;
  subject_id: number;
  subject_name: string;
  permissions: {
    canView: boolean;
    canCreate: boolean;
    canEdit: boolean;
  };
}

const MeetingsSection: React.FC<MeetingsSectionProps> = ({
  subject_type,
  subject_id,
  subject_name,
  permissions,
}) => {
  const { t } = useTranslation();
  const { meetings, loading, refetch } = useMeetingsSection({ subject_type, subject_id });
  const [showCreate, setShowCreate] = useState(false);

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h3 className="text-base font-semibold text-[var(--text-primary)]">
          {t('meetings.meeting.section.header', { name: subject_name })}
          {meetings.length > 0 && (
            <span className="ms-2 inline-flex items-center rounded-full bg-[var(--surface-muted)] px-2 py-0.5 text-xs tabular-nums text-[var(--text-secondary)]">
              {meetings.length}
            </span>
          )}
        </h3>
        <div className="flex items-center gap-2">
          {permissions.canCreate && (
            <Button
              size="sm"
              leftIcon={<IconPlus className="h-4 w-4" />}
              onClick={() => setShowCreate(true)}
            >
              {t('meetings.meeting.list.new_button')}
            </Button>
          )}
          {permissions.canView && meetings.length > 0 && (
            <Link
              to={`/strategy/meetings?subject_type=${subject_type}&subject_id=${subject_id}`}
              className="text-sm text-[var(--accent-default)] hover:underline"
            >
              {t('meetings.meeting.section.view_all')}
            </Link>
          )}
        </div>
      </div>

      {loading ? (
        <Skeleton className="h-24 w-full" />
      ) : meetings.length === 0 ? (
        <EmptyState
          icon={IconCalendar}
          title={t('meetings.meeting.section.empty', { entity: ALIAS_AR[subject_type] })}
          action={
            permissions.canCreate ? (
              <Button
                leftIcon={<IconPlus className="h-4 w-4" />}
                onClick={() => setShowCreate(true)}
              >
                {t('meetings.meeting.section.create_cta')}
              </Button>
            ) : undefined
          }
        />
      ) : (
        <div className="grid grid-cols-1 gap-2 md:grid-cols-2">
          {meetings.map((m) => (
            <MeetingsSectionCard key={m.id} meeting={m} />
          ))}
        </div>
      )}

      <CreateMeetingModal
        open={showCreate}
        subject_type={subject_type}
        subject_id={subject_id}
        onClose={() => setShowCreate(false)}
        onCreated={() => refetch()}
      />
    </div>
  );
};

export default MeetingsSection;
