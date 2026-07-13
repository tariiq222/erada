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
            { value: 'pending', label: t('meetings.recommendation.statuses.pending') },
            { value: 'accepted', label: t('meetings.recommendation.statuses.accepted') },
            { value: 'approved', label: t('meetings.recommendation.statuses.approved') },
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
      <FilterField label={t('meetings.recommendation.fields.meeting', { defaultValue: 'الاجتماع' })}>
        <Input
          type="number"
          value={filters.meeting_id}
          onChange={(e) => onChange('meeting_id', e.target.value)}
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
