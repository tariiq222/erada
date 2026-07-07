import React from 'react';
import { useTranslation } from 'react-i18next';
import { IconSearch } from '@tabler/icons-react';
import { Button, FilterField, FilterRow, Input, Select } from '@shared/ui';
import { statusLabels, priorityLabels } from './constants';
import type { TaskFilters } from './types';

interface TasksFiltersCardProps {
  filters: TaskFilters;
  onFiltersChange: (filters: TaskFilters) => void;
  onSearch: (e: React.FormEvent) => void;
  onClose: () => void;
}

const TasksFiltersCard: React.FC<TasksFiltersCardProps> = ({
  filters,
  onFiltersChange,
  onSearch,
  onClose,
}) => {
  const { t } = useTranslation();
  return (
    <form onSubmit={onSearch}>
      <FilterRow onClear={onClose} clearLabel={t('tasks.filter_results')}>
        <FilterField label={t('common.search')}>
          <Input
            placeholder={t('tasks.search_by_title')}
            value={filters.search}
            onChange={(e) => onFiltersChange({ ...filters, search: e.target.value })}
            leftIcon={<IconSearch className="h-4 w-4" />}
          />
        </FilterField>
        <FilterField label={t('tasks.all_statuses')}>
          <Select
            value={filters.status}
            onChange={(e) => onFiltersChange({ ...filters, status: e.target.value })}
            options={[
              { value: '', label: t('tasks.all_statuses') },
              ...Object.entries(statusLabels).map(([value, labelKey]) => ({
                value,
                label: t(labelKey),
              })),
            ]}
          />
        </FilterField>
        <FilterField label={t('tasks.all_priorities')}>
          <Select
            value={filters.priority}
            onChange={(e) => onFiltersChange({ ...filters, priority: e.target.value })}
            options={[
              { value: '', label: t('tasks.all_priorities') },
              ...Object.entries(priorityLabels).map(([value, labelKey]) => ({
                value,
                label: t(labelKey),
              })),
            ]}
          />
        </FilterField>
        <div className="shrink-0">
          <Button type="submit" variant="secondary" size="sm" leftIcon={<IconSearch className="h-4 w-4" />}>
            {t('common.search')}
          </Button>
        </div>
      </FilterRow>
    </form>
  );
};

export default TasksFiltersCard;
