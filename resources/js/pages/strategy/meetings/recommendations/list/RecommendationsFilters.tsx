import React from 'react';
import { useTranslation } from 'react-i18next';
import { FilterField, FilterRow, Input, Select, Switch } from '@shared/ui';
import type { RecommendationListFilters } from './types';

interface Props {
  filters: RecommendationListFilters;
  onChange: <K extends keyof RecommendationListFilters>(key: K, value: RecommendationListFilters[K]) => void;
  onReset: () => void;
}

const RecommendationsFilters: React.FC<Props> = ({ filters, onChange, onReset }) => {
  const { t } = useTranslation();
  return (
    <FilterRow onClear={onReset} clearLabel={t('meetings.recommendation.list.filters.clear')}>
      <FilterField label={t('meetings.recommendation.list.filters.status')}>
        <Select
          value={filters.status}
          onChange={(e) => onChange('status', e.target.value)}
          options={[
            { value: '', label: t('common.all') },
            { value: 'proposed', label: t('meetings.recommendation.statuses.proposed') },
            { value: 'accepted', label: t('meetings.recommendation.statuses.accepted') },
            { value: 'rejected', label: t('meetings.recommendation.statuses.rejected') },
            { value: 'deferred', label: t('meetings.recommendation.statuses.deferred') },
            { value: 'completed', label: t('meetings.recommendation.statuses.completed') },
          ]}
        />
      </FilterField>
      <FilterField label={t('meetings.recommendation.list.filters.priority')}>
        <Select
          value={filters.priority}
          onChange={(e) => onChange('priority', e.target.value)}
          options={[
            { value: '', label: t('common.all') },
            { value: 'low', label: t('meetings.recommendation.priorities.low') },
            { value: 'medium', label: t('meetings.recommendation.priorities.medium') },
            { value: 'high', label: t('meetings.recommendation.priorities.high') },
            { value: 'critical', label: t('meetings.recommendation.priorities.critical') },
          ]}
        />
      </FilterField>
      <FilterField label={t('meetings.recommendation.list.filters.decision')}>
        <Input
          type="number"
          value={filters.decision_id}
          onChange={(e) => onChange('decision_id', e.target.value)}
        />
      </FilterField>
      <div className="shrink-0">
        <Switch
          checked={filters.overdue}
          onChange={(e) => onChange('overdue', e.target.checked)}
          label={t('meetings.recommendation.list.filters.overdue')}
        />
      </div>
    </FilterRow>
  );
};

export default RecommendationsFilters;
