import { memo } from 'react';
import { useTranslation } from 'react-i18next';
import {IconList, IconLayoutGrid, IconColumns} from '@tabler/icons-react';
import { FilterRow } from '@shared/ui';
import type { TaskFilters } from './types';

interface TasksFiltersProps {
  filters: TaskFilters;
  viewMode: 'table' | 'cards' | 'kanban';
  onFiltersChange: (filters: TaskFilters) => void;
  onViewModeChange: (mode: 'table' | 'cards' | 'kanban') => void;
}

const TasksFilters = memo<TasksFiltersProps>(({
  filters,
  viewMode,
  onFiltersChange,
  onViewModeChange,
}) => {
  const { t } = useTranslation();
  return (
    <FilterRow>
      {/* Quick Filters */}
      <div className="shrink-0">
        <div className="flex flex-wrap gap-2">
          <button
            onClick={() => {
              onFiltersChange({ ...filters, my_tasks: !filters.my_tasks });
            }}
            className={`px-4 py-2 rounded-md font-medium text-sm transition-colors ${
              filters.my_tasks
                ? 'bg-[var(--accent-default)] text-[var(--text-inverse)]'
                : 'bg-[var(--surface-base)] border border-[var(--border-default)] text-[var(--text-secondary)] hover:border-[var(--accent-default)] hover:text-[var(--accent-default)]'
            }`}
          >
            {t('tasks.my_tasks')}
          </button>
          <button
            onClick={() => {
              onFiltersChange({ ...filters, overdue: !filters.overdue });
            }}
            className={`px-4 py-2 rounded-md font-medium text-sm transition-colors ${
              filters.overdue
                ? 'bg-[var(--status-danger)] text-[var(--text-inverse)]'
                : 'bg-[var(--surface-base)] border border-[var(--border-default)] text-[var(--text-secondary)] hover:border-[var(--status-danger)] hover:text-[var(--status-danger)]'
            }`}
          >
            {t('tasks.overdue')}
          </button>
        </div>
      </div>

      {/* View Mode Toggle */}
      <div className="shrink-0">
        <div className="flex items-center bg-[var(--surface-muted)] rounded-md p-1">
          <button
            onClick={() => onViewModeChange('table')}
            className={`p-2 rounded transition-colors ${
              viewMode === 'table' ? 'bg-[var(--surface-base)] shadow-sm text-[var(--accent-default)]' : 'text-[var(--text-tertiary)] hover:text-[var(--text-primary)]'
            }`}
            title={t('tasks.table_view')}
            aria-label={t('tasks.table_view')}
          >
            <IconList className="h-4 w-4" />
          </button>
          <button
            onClick={() => onViewModeChange('cards')}
            className={`p-2 rounded transition-colors ${
              viewMode === 'cards' ? 'bg-[var(--surface-base)] shadow-sm text-[var(--accent-default)]' : 'text-[var(--text-tertiary)] hover:text-[var(--text-primary)]'
            }`}
            title={t('tasks.cards_view')}
            aria-label={t('tasks.cards_view')}
          >
            <IconLayoutGrid className="h-4 w-4" />
          </button>
          <button
            onClick={() => onViewModeChange('kanban')}
            className={`p-2 rounded transition-colors ${
              viewMode === 'kanban' ? 'bg-[var(--surface-base)] shadow-sm text-[var(--accent-default)]' : 'text-[var(--text-tertiary)] hover:text-[var(--text-primary)]'
            }`}
            title={t('tasks.kanban_view')}
            aria-label={t('tasks.kanban_view')}
          >
            <IconColumns className="h-4 w-4" />
          </button>
        </div>
      </div>
    </FilterRow>
  );
});

TasksFilters.displayName = 'TasksFilters';

export default TasksFilters;
