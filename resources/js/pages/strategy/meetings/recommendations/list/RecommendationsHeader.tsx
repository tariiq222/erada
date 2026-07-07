import React from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router-dom';
import { Button, PageHeader } from '@shared/ui';
import { IconClipboardCheck, IconPlus } from '@shared/ui/icons';

interface Props {
  canCreate: boolean;
}

const RecommendationsHeader: React.FC<Props> = ({ canCreate }) => {
  const { t } = useTranslation();
  return (
    <PageHeader
      icon={IconClipboardCheck}
      iconTone="project"
      title={t('meetings.recommendation.list.header')}
      subtitle={t('meetings.title_en')}
      actions={
        canCreate ? (
          <Link to="/strategy/meetings/recommendations/new">
            <Button leftIcon={<IconPlus className="h-4 w-4" />} size="sm">
              {t('meetings.recommendation.list.new_button')}
            </Button>
          </Link>
        ) : null
      }
    />
  );
};

export default RecommendationsHeader;
