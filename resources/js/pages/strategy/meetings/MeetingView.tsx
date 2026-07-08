import React, { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import {
  Breadcrumb, Button, DeleteConfirmationModal, Skeleton,
  Card, CardContent,
} from '@shared/ui';
import { useAuth } from '@shared/contexts/AuthContext';
import { useCan } from '@shared/api/access';
import { IconEdit, IconTrash } from '@shared/ui/icons';
import CopyButton from '@shared/ui/CopyButton';
import {
  useMeetingView, MeetingOverview, MeetingAttendees,
  MeetingStatusActions, MeetingAgenda,
} from './view';
import ResolutionsSection from '@features/meetings/resolutions/ResolutionsSection';

const MeetingViewSkeleton: React.FC = () => (
  <div className="space-y-4">
    <Skeleton className="h-6 w-48" />
    <Skeleton className="h-8 w-72" />
    <Card>
      <CardContent className="space-y-3 p-6">
        <Skeleton className="h-4 w-32" />
        <Skeleton className="h-20 w-full" />
      </CardContent>
    </Card>
  </div>
);

const MeetingView: React.FC = () => {
  const { t } = useTranslation();
  const { id } = useParams<{ id: string }>();
  const { user } = useAuth();
  const { meeting, loading, deleting, remove, start, complete, cancel } = useMeetingView(id);
  const [showDelete, setShowDelete] = useState(false);

  const canEdit = useCan('meetings.edit');
  const canDelete = useCan('meetings.delete');
  // Phase 1 / Direction R: the new ResolutionsSection consumes a wider
  // permission shape than the legacy Recommendation-backed section did.
  // We grant everything via the engine — `useCan` falls back to
  // super_admin: true so no extra wiring is needed.
  const canResView = useCan('meeting_resolutions.view');
  const canResCreate = useCan('meeting_resolutions.create');
  const canResUpdate = useCan('meeting_resolutions.update');
  const canResDelete = useCan('meeting_resolutions.delete');
  const canResHold = useCan('meeting_resolutions.hold');
  const canResReleaseHold = useCan('meeting_resolutions.release_hold');
  const canResConvertToTasks = useCan('meeting_resolutions.convert_to_tasks');
  const canResComplete = useCan('meeting_resolutions.complete');
  const canResCancel = useCan('meeting_resolutions.cancel');

  if (loading || !meeting) return <MeetingViewSkeleton />;

  return (
    <div className="space-y-4">
      <Breadcrumb
        items={[
          { label: t('meetings.title'), href: '/strategy/meetings' },
          { label: `#${meeting.id}` },
        ]}
      />

      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-semibold text-[var(--text-primary)]">{meeting.title}</h1>
        <div className="flex items-center gap-2">
          {canEdit && (
            <Link to={`/strategy/meetings/${meeting.id}/edit`}>
              <Button variant="outline" size="sm" leftIcon={<IconEdit className="h-4 w-4" />}>
                {t('meetings.meeting.detail.edit_button')}
              </Button>
            </Link>
          )}
          {canDelete && (
            <Button variant="danger" size="sm" leftIcon={<IconTrash className="h-4 w-4" />}
                    onClick={() => setShowDelete(true)}>
              {t('meetings.meeting.detail.delete_button')}
            </Button>
          )}
        </div>
      </div>
      {meeting.reference_number && (
        <div className="flex items-center gap-2">
          <code className="rounded bg-[var(--surface-muted)] px-2 py-1 font-mono text-sm text-[var(--text-secondary)]">
            {meeting.reference_number}
          </code>
          <CopyButton text={meeting.reference_number} />
        </div>
      )}

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <div className="lg:col-span-2 space-y-4">
          <MeetingOverview meeting={meeting} />
          <MeetingAgenda meetingId={meeting.id} currentUserId={user?.id} />
        </div>
        <div className="space-y-4">
          <MeetingStatusActions meeting={meeting} onStart={start} onComplete={complete} onCancel={cancel} />
          <MeetingAttendees meeting={meeting} />
        </div>
      </div>

      <ResolutionsSection
        meetingId={meeting.id}
        permissions={{
          canView: canResView,
          canCreate: canResCreate,
          canUpdate: canResUpdate,
          canDelete: canResDelete,
          canStart: canResUpdate,
          canHold: canResHold,
          canReleaseHold: canResReleaseHold,
          canConvertToTasks: canResConvertToTasks,
          canComplete: canResComplete,
          canCancel: canResCancel,
        }}
      />

      <DeleteConfirmationModal
        isOpen={showDelete}
        item={meeting}
        title={t('meetings.meeting.detail.delete_button')}
        itemName={meeting.title}
        warningMessage={t('meetings.meeting.detail.delete_confirm')}
        isDeleting={deleting}
        onClose={() => setShowDelete(false)}
        onConfirm={remove}
      />
    </div>
  );
};

export default MeetingView;
