import React from 'react';
import { useTranslation } from 'react-i18next';
import {IconClipboardList, IconCircleCheck, IconUsers, IconChartBar} from '@tabler/icons-react';
import { StatCard } from '@shared/ui';
import type { Survey } from './types';

interface StatsCardsProps {
  surveys: Survey[];
  total: number;
}

const StatsCards: React.FC<StatsCardsProps> = ({ surveys, total }) => {
  const { t } = useTranslation();
  return (
  <div className="grid grid-cols-2 md:grid-cols-4 gap-2 sm:gap-4">
    <StatCard
      label={t('surveys.total_surveys')}
      value={total}
      icon={IconClipboardList}
      color="accent"
    />
    <StatCard
      label={t('surveys.published')}
      value={surveys.filter((s) => s.status === 'published').length}
      icon={IconCircleCheck}
      color="success"
    />
    <StatCard
      label={t('surveys.total_responses')}
      value={surveys.reduce((sum, s) => sum + s.responses_count, 0)}
      icon={IconUsers}
      color="warning"
    />
    <StatCard
      label={t('surveys.type_initial')}
      value={surveys.filter((s) => s.type === 'initial').length}
      icon={IconChartBar}
      color="accent"
    />
  </div>
  );
};

export default StatsCards;
