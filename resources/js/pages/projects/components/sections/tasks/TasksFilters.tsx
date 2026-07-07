import React from 'react';
import { useTranslation } from 'react-i18next';
import { FilterField, FilterRow, Select } from '@shared/ui';
import type { TaskType } from '../../../types';

export interface TasksFiltersProps {
  tasks: TaskType[];
  filteredTasks: TaskType[];
  statusFilter: string;
  priorityFilter: string;
  assigneeFilter: string;
  assignees: Array<{ id: number; name: string }>;
  activeFiltersCount: number;
  onStatusFilterChange: (value: string) => void;
  onPriorityFilterChange: (value: string) => void;
  onAssigneeFilterChange: (value: string) => void;
  onResetFilters: () => void;
}

const TasksFilters: React.FC<TasksFiltersProps> = ({
  tasks,
  filteredTasks: _filteredTasks,
  statusFilter,
  priorityFilter,
  assigneeFilter,
  assignees,
  activeFiltersCount,
  onStatusFilterChange,
  onPriorityFilterChange,
  onAssigneeFilterChange,
  onResetFilters,
}) => {
  const { t } = useTranslation();
  return (
    <FilterRow
      onClear={activeFiltersCount > 0 ? onResetFilters : undefined}
      clearLabel={t('projects.reset_filters')}
    >
      <FilterField label={t('common.status')}>
        <Select
          value={statusFilter}
          onChange={(e) => onStatusFilterChange(e.target.value)}
          options={[
            { value: 'all', label: `${t('projects.all_statuses')} (${tasks.length})` },
            { value: 'todo', label: `${t('status.todo')} (${tasks.filter(t => t.status === 'todo').length})` },
            { value: 'in_progress', label: `${t('status.in_progress')} (${tasks.filter(t => t.status === 'in_progress').length})` },
            { value: 'in_review', label: `${t('status.in_review')} (${tasks.filter(t => t.status === 'in_review').length})` },
            { value: 'completed', label: `${t('status.completed')} (${tasks.filter(t => t.status === 'completed').length})` },
          ]}
        />
      </FilterField>
      <FilterField label={t('common.priority')}>
        <Select
          value={priorityFilter}
          onChange={(e) => onPriorityFilterChange(e.target.value)}
          options={[
            { value: 'all', label: t('projects.all_priorities') },
            { value: 'urgent', label: t('priority.urgent') },
            { value: 'high', label: t('priority.high') },
            { value: 'normal', label: t('priority.normal') },
            { value: 'low', label: t('priority.low') },
          ]}
        />
      </FilterField>
      <FilterField label={t('projects.assignee')}>
        <Select
          value={assigneeFilter}
          onChange={(e) => onAssigneeFilterChange(e.target.value)}
          options={[
            { value: 'all', label: t('projects.all_members') },
            ...assignees.map(a => ({ value: String(a.id), label: a.name })),
          ]}
        />
      </FilterField>
    </FilterRow>
  );
};

export default TasksFilters;
