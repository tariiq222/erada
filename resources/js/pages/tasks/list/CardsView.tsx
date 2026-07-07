import { memo } from 'react';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { formatDate } from '@shared/lib/utils';
import {
  Card,
  CardContent,
  Pagination,
} from '@shared/ui';
import {IconEye, IconEdit, IconFlag, IconClock, IconLayoutKanban, IconCircle} from '@tabler/icons-react';
import { priorityLabels, statusIcons } from './constants';
import {
  TASK_STATUS_CLASS,
  PRIORITY_TEXT,
  type TaskStatusKey,
  type PriorityKey,
} from '@shared/lib/statusTokens';
import type { Task, PaginationState } from './types';

interface CardsViewProps {
  tasks: Task[];
  pagination: PaginationState;
  onOpenTaskModal: (taskId: number) => void;
  onPageChange: (page: number) => void;
  isOverdue: (task: Task) => boolean;
}

const CardsView = memo<CardsViewProps>(({
  tasks,
  pagination,
  onOpenTaskModal,
  onPageChange,
  isOverdue,
}) => {
  const { t } = useTranslation();
  return (
    <>
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        {tasks.map((task) => {
          const StatusIcon = statusIcons[task.status] || IconCircle;
          return (
            <Card key={task.id} className="border border-[var(--border-default)] hover:border-[var(--accent-default)] hover:shadow-md transition-[border-color,box-shadow] group">
              <CardContent className="p-4">
                <div className="flex items-start justify-between mb-3">
                  <div className={`p-2 rounded-lg ${TASK_STATUS_CLASS[task.status as TaskStatusKey]}`}>
                    <StatusIcon className="h-5 w-5" />
                  </div>
                  <div className="flex items-center gap-1">
                    <button
                      onClick={() => onOpenTaskModal(task.id)}
                      aria-label={t('common.view')}
                      className="p-1 rounded-lg text-[var(--text-tertiary)] hover:bg-[var(--accent-subtle)] hover:text-[var(--accent-default)] transition-colors"
                    >
                      <IconEye className="h-4 w-4" />
                    </button>
                    <Link
                      to={`/tasks/${task.id}/edit`}
                      aria-label={t('common.edit')}
                      className="p-1 rounded-lg text-[var(--text-tertiary)] hover:bg-[var(--status-warning-subtle)] hover:text-[var(--status-warning)] transition-colors"
                    >
                      <IconEdit className="h-4 w-4" />
                    </Link>
                  </div>
                </div>
                <button onClick={() => onOpenTaskModal(task.id)} className="text-start w-full">
                  <h3 className="font-semibold text-[var(--text-primary)] mb-2 group-hover:text-[var(--accent-default)] transition-colors line-clamp-2">
                    {task.title}
                  </h3>
                </button>
                {task.project && (
                  <Link
                    to={`/projects/${task.project.id}`}
                    className="inline-flex items-center gap-1 px-2 py-1 bg-[var(--accent-subtle)] text-[var(--accent-default)] rounded text-xs font-medium hover:bg-[var(--accent-default)] hover:text-[var(--text-inverse)] transition-colors mb-3"
                  >
                    <IconLayoutKanban className="h-3 w-3" />
                    {task.project.code} - {task.project.name}
                  </Link>
                )}
                <div className="flex items-center justify-between pt-3 border-t border-[var(--border-default)]">
                  <div className="flex items-center gap-3">
                    <div className="flex items-center gap-1">
                      <IconFlag className={`h-4 w-4 ${PRIORITY_TEXT[task.priority as PriorityKey]}`} />
                      <span className="text-xs text-[var(--text-tertiary)]">{t(priorityLabels[task.priority])}</span>
                    </div>
                    {task.due_date && (
                      <div className={`flex items-center gap-1 ${isOverdue(task) ? 'text-[var(--status-danger)]' : 'text-[var(--text-tertiary)]'}`}>
                        <IconClock className="h-4 w-4" />
                        <span className="text-xs">{formatDate(task.due_date)}</span>
                      </div>
                    )}
                  </div>
                  {task.assignee && (
                    <div className="flex items-center gap-2">
                      <div className="h-7 w-7 rounded-full bg-[var(--accent-default)] flex items-center justify-center">
                        <span className="text-[var(--text-inverse)] text-xs font-medium">{task.assignee.name.charAt(0)}</span>
                      </div>
                    </div>
                  )}
                </div>
              </CardContent>
            </Card>
          );
        })}
      </div>
      {pagination.lastPage > 1 && (
        <div className="mt-6">
          <Pagination
            currentPage={pagination.currentPage}
            totalPages={pagination.lastPage}
            onPageChange={onPageChange}
          />
        </div>
      )}
    </>
  );
});

CardsView.displayName = 'CardsView';

export default CardsView;
