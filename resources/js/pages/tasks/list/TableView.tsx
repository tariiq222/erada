import { memo } from 'react';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { formatDate } from '@shared/lib/utils';
import {
  Card,
  Badge,
  Table,
  TableHeader,
  TableBody,
  TableHead,
  TableRow,
  TableCell,
  Pagination,
} from '@shared/ui';
import { IconButton } from '@shared/ui/IconButton';
import {IconEye, IconEdit, IconFlag, IconClock, IconCircle, IconTrash} from '@tabler/icons-react';
import {
  statusLabels,
  statusVariants,
  priorityLabels,
  statusIcons,
  statusColors,
  priorityColors,
} from './constants';
import type { Task, PaginationState } from './types';

interface TableViewProps {
  tasks: Task[];
  pagination: PaginationState;
  onOpenTaskModal: (taskId: number) => void;
  onPageChange: (page: number) => void;
  onDelete: (task: Task) => void;
  isOverdue: (task: Task) => boolean;
}

const TableView = memo<TableViewProps>(({
  tasks,
  pagination,
  onOpenTaskModal,
  onPageChange,
  onDelete,
  isOverdue,
}) => {
  const { t } = useTranslation();
  return (
    <Card className="p-0 border border-[var(--border-default)] overflow-hidden">
      <div className="overflow-x-auto">
      <Table hoverable>
        <TableHeader>
          <TableRow>
            <TableHead>{t('tasks.task')}</TableHead>
            <TableHead>{t('tasks.project')}</TableHead>
            <TableHead>{t('common.status')}</TableHead>
            <TableHead>{t('common.priority')}</TableHead>
            <TableHead>{t('common.due_date')}</TableHead>
            <TableHead>{t('tasks.assignee')}</TableHead>
            <TableHead className="w-24 text-center">{t('common.actions')}</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {tasks.map((task) => {
            const StatusIcon = statusIcons[task.status] || IconCircle;
            return (
              <TableRow key={task.id}>
                <TableCell>
                  <div className="flex items-center gap-3">
                    <div className={`p-2 rounded-lg ${statusColors[task.status]}`}>
                      <StatusIcon className="h-4 w-4" />
                    </div>
                    <div>
                      <button
                        onClick={() => onOpenTaskModal(task.id)}
                        className="font-medium text-[var(--text-primary)] hover:text-[var(--accent-default)] transition-colors text-start"
                      >
                        {task.title}
                      </button>
                      {task.milestone && (
                        <p className="text-xs text-[var(--text-tertiary)]">{task.milestone.name}</p>
                      )}
                    </div>
                  </div>
                </TableCell>
                <TableCell>
                  {task.project ? (
                    <Link
                      to={`/projects/${task.project.id}`}
                      className="inline-flex px-2 py-1 bg-[var(--accent-subtle)] text-[var(--accent-default)] rounded text-xs font-medium hover:bg-[var(--accent-default)] hover:text-[var(--text-inverse)] transition-colors"
                    >
                      {task.project.code}
                    </Link>
                  ) : (
                    <span className="text-[var(--text-tertiary)] text-sm">-</span>
                  )}
                </TableCell>
                <TableCell>
                  <Badge variant={statusVariants[task.status]} size="sm">
                    {t(statusLabels[task.status])}
                  </Badge>
                </TableCell>
                <TableCell>
                  <div className="flex items-center gap-1">
                    <IconFlag className={`h-4 w-4 ${priorityColors[task.priority]}`} />
                    <span className="text-sm text-[var(--text-secondary)]">{t(priorityLabels[task.priority])}</span>
                  </div>
                </TableCell>
                <TableCell>
                  {task.due_date ? (
                    <span className={`flex items-center gap-1 text-sm ${isOverdue(task) ? 'text-[var(--status-danger)] font-medium' : 'text-[var(--text-secondary)]'}`}>
                      <IconClock className="h-3.5 w-3.5" />
                      {formatDate(task.due_date)}
                    </span>
                  ) : (
                    <span className="text-[var(--text-tertiary)] text-sm">-</span>
                  )}
                </TableCell>
                <TableCell>
                  {task.assignee ? (
                    <div className="flex items-center gap-2">
                      <div className="h-7 w-7 rounded-full bg-[var(--accent-default)] flex items-center justify-center">
                        <span className="text-[var(--text-inverse)] text-xs font-medium">{task.assignee.name.charAt(0)}</span>
                      </div>
                      <span className="text-[var(--text-primary)] text-sm">{task.assignee.name}</span>
                    </div>
                  ) : (
                    <span className="text-[var(--text-tertiary)] text-sm">-</span>
                  )}
                </TableCell>
                <TableCell>
                  <div className="flex items-center justify-center gap-1">
                    <button
                      onClick={() => onOpenTaskModal(task.id)}
                      className="p-2 rounded-lg text-[var(--text-tertiary)] hover:bg-[var(--accent-subtle)] hover:text-[var(--accent-default)] transition-colors"
                      title={t('common.view')}
                      aria-label={t('common.view')}
                    >
                      <IconEye className="h-4 w-4" />
                    </button>
                    <Link
                      to={`/tasks/${task.id}/edit`}
                      className="p-2 rounded-lg text-[var(--text-tertiary)] hover:bg-[var(--status-warning-subtle)] hover:text-[var(--status-warning)] transition-colors"
                      title={t('common.edit')}
                      aria-label={t('common.edit')}
                    >
                      <IconEdit className="h-4 w-4" />
                    </Link>
                    <IconButton
                      variant="danger"
                      onClick={() => onDelete(task)}
                      title={t('common.delete')}
                      aria-label={t('common.delete')}
                    >
                      <IconTrash className="h-4 w-4" />
                    </IconButton>
                  </div>
                </TableCell>
              </TableRow>
            );
          })}
        </TableBody>
      </Table>
      </div>
      {pagination.lastPage > 1 && (
        <div className="p-4 border-t border-[var(--border-default)]">
          <Pagination
            currentPage={pagination.currentPage}
            totalPages={pagination.lastPage}
            onPageChange={onPageChange}
          />
        </div>
      )}
    </Card>
  );
});

TableView.displayName = 'TableView';

export default TableView;
