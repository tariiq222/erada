import React from 'react';
import { useTranslation } from 'react-i18next';
import {IconListCheck, IconPlus, IconLayoutGrid, IconList} from '@tabler/icons-react';
import { Button, FilterButton } from '@shared/ui';

export interface TasksHeaderProps {
  taskCount: number;
  viewMode: 'kanban' | 'list';
  showFilters: boolean;
  activeFiltersCount: number;
  onViewModeChange: (mode: 'kanban' | 'list') => void;
  onToggleFilters: () => void;
  onCreateTask: () => void;
}

const TasksHeader: React.FC<TasksHeaderProps> = ({
  taskCount,
  viewMode,
  showFilters,
  activeFiltersCount,
  onViewModeChange,
  onToggleFilters,
  onCreateTask,
}) => {
  const { t } = useTranslation();
  return (
    <div className="flex items-center justify-between">
      <div className="flex items-center gap-2">
        <IconListCheck className="h-5 w-5 text-[var(--text-secondary)]" />
        <div>
          <h3 className="font-semibold text-[var(--text-primary)]">{t('projects.tasks')}</h3>
          <p className="text-sm text-[var(--text-tertiary)]">{taskCount} {t('projects.task_count_unit')}</p>
        </div>
      </div>
      <div className="flex items-center gap-2">
        <FilterButton
          isOpen={showFilters}
          onClick={onToggleFilters}
          activeCount={activeFiltersCount}
        />
        <div className="flex items-center bg-[var(--surface-subtle)] rounded-lg p-0">
          <button
            onClick={() => onViewModeChange('kanban')}
            className={`p-1 rounded-md transition-colors ${
              viewMode === 'kanban' ? 'bg-[var(--surface-base)] shadow-sm text-[var(--text-primary)]' : 'text-[var(--text-tertiary)] hover:text-[var(--text-secondary)]'
            }`}
            title={t('projects.kanban_view')}
            aria-label={t('projects.kanban_view')}
            aria-pressed={viewMode === 'kanban'}
          >
            <IconLayoutGrid className="h-4 w-4" />
          </button>
          <button
            onClick={() => onViewModeChange('list')}
            className={`p-1 rounded-md transition-colors ${
              viewMode === 'list' ? 'bg-[var(--surface-base)] shadow-sm text-[var(--text-primary)]' : 'text-[var(--text-tertiary)] hover:text-[var(--text-secondary)]'
            }`}
            title={t('projects.list_view')}
            aria-label={t('projects.list_view')}
            aria-pressed={viewMode === 'list'}
          >
            <IconList className="h-4 w-4" />
          </button>
        </div>
        <Button size="sm" leftIcon={<IconPlus className="h-4 w-4" />} onClick={onCreateTask}>
          {t('projects.add_task')}
        </Button>
      </div>
    </div>
  );
};

export default TasksHeader;
