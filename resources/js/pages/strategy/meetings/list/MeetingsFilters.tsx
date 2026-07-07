import React from 'react';
import { useTranslation } from 'react-i18next';
import { FilterField, FilterRow, Input, Select, Switch } from '@shared/ui';
import type { MeetingListFilters } from './types';

interface Props {
  filters: MeetingListFilters;
  onChange: <K extends keyof MeetingListFilters>(key: K, value: MeetingListFilters[K]) => void;
  onReset: () => void;
}

const MeetingsFilters: React.FC<Props> = ({ filters, onChange, onReset }) => {
  const { t } = useTranslation();
  return (
    <FilterRow onClear={onReset} clearLabel={t('meetings.meeting.list.filters.clear')}>
      <FilterField label={t('meetings.meeting.list.filters.subject_type')}>
        <Select
          value={filters.subject_type}
          onChange={(e) => onChange('subject_type', e.target.value)}
          options={[
            { value: 'all', label: t('common.all') },
            { value: 'project', label: 'project' },
            { value: 'portfolio', label: 'portfolio' },
            { value: 'program', label: 'program' },
            { value: 'risk', label: 'risk' },
          ]}
        />
      </FilterField>
      <FilterField label={t('meetings.meeting.list.filters.subject_id')}>
        <Input
          type="number"
          value={filters.subject_id}
          onChange={(e) => onChange('subject_id', e.target.value)}
        />
      </FilterField>
      <FilterField label={t('meetings.meeting.list.filters.status')}>
        <Select
          value={filters.status}
          onChange={(e) => onChange('status', e.target.value)}
          options={[
            { value: '', label: t('common.all') },
            { value: 'scheduled', label: t('meetings.meeting.statuses.scheduled') },
            { value: 'in_progress', label: t('meetings.meeting.statuses.in_progress') },
            { value: 'completed', label: t('meetings.meeting.statuses.completed') },
            { value: 'cancelled', label: t('meetings.meeting.statuses.cancelled') },
          ]}
        />
      </FilterField>
      <FilterField label={t('meetings.meeting.list.filters.from')}>
        <Input
          type="date"
          value={filters.from}
          onChange={(e) => onChange('from', e.target.value)}
        />
      </FilterField>
      <FilterField label={t('meetings.meeting.list.filters.to')}>
        <Input
          type="date"
          value={filters.to}
          onChange={(e) => onChange('to', e.target.value)}
        />
      </FilterField>
      <div className="shrink-0">
        <Switch
          checked={filters.pending_reminder}
          onChange={(e) => onChange('pending_reminder', e.target.checked)}
          label={t('meetings.meeting.list.filters.pending_reminder')}
        />
      </div>
    </FilterRow>
  );
};

export default MeetingsFilters;
