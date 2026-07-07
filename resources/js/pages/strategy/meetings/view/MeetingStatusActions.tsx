import React from 'react';
import { useTranslation } from 'react-i18next';
import { Button, Card, CardContent, CardHeader, CardTitle, Tooltip } from '@shared/ui';
import { IconPlayerPlay, IconCheck, IconX } from '@shared/ui/icons';
import type { Meeting } from '@features/meetings/types';

interface Props {
  meeting: Meeting;
  onStart: () => Promise<void>;
  onComplete: () => Promise<void>;
  onCancel: () => Promise<void>;
}

const MeetingStatusActions: React.FC<Props> = ({ meeting, onStart, onComplete, onCancel }) => {
  const { t } = useTranslation();
  const canStart = meeting.status === 'scheduled';
  const canComplete = meeting.status === 'in_progress';
  const canCancel = meeting.status === 'scheduled' || meeting.status === 'in_progress';

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('meetings.meeting.fields.status')}</CardTitle>
      </CardHeader>
      <CardContent className="flex flex-wrap gap-2">
        <Tooltip content={canStart ? t('meetings.meeting.actions.start') : t('meetings.meeting.messages.invalid_transition')}>
          <Button leftIcon={<IconPlayerPlay className="h-4 w-4" />} disabled={!canStart} onClick={onStart} size="sm">
            {t('meetings.meeting.actions.start')}
          </Button>
        </Tooltip>
        <Tooltip content={canComplete ? t('meetings.meeting.actions.complete') : t('meetings.meeting.messages.invalid_transition')}>
          <Button leftIcon={<IconCheck className="h-4 w-4" />} disabled={!canComplete} onClick={onComplete} size="sm">
            {t('meetings.meeting.actions.complete')}
          </Button>
        </Tooltip>
        <Tooltip content={canCancel ? t('meetings.meeting.actions.cancel') : t('meetings.meeting.messages.invalid_transition')}>
          <Button variant="danger" leftIcon={<IconX className="h-4 w-4" />} disabled={!canCancel} onClick={onCancel} size="sm">
            {t('meetings.meeting.actions.cancel')}
          </Button>
        </Tooltip>
      </CardContent>
    </Card>
  );
};

export default MeetingStatusActions;
