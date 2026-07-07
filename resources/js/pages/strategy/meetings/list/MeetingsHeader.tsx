import React from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router-dom';
import { Button, PageHeader } from '@shared/ui';
import { IconCalendar, IconPlus } from '@shared/ui/icons';

interface Props { canCreate: boolean }

const MeetingsHeader: React.FC<Props> = ({ canCreate }) => {
  const { t } = useTranslation();
  return (
    <PageHeader
      icon={IconCalendar}
      iconTone="project"
      title={t('meetings.meeting.list.header')}
      subtitle={t('meetings.title_en')}
      actions={canCreate ? (
        <Link to="/strategy/meetings/new">
          <Button leftIcon={<IconPlus className="h-4 w-4" />} size="sm">
            {t('meetings.meeting.list.new_button')}
          </Button>
        </Link>
      ) : null}
    />
  );
};

export default MeetingsHeader;
