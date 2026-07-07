import React from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router-dom';
import {IconFlag, IconCircle} from '@tabler/icons-react';
import { formatDate } from '@shared/lib/utils';
import {
  Card,
  Table,
  TableHeader,
  TableBody,
  TableHead,
  TableRow,
  TableCell,
} from '@shared/ui';
import { taskStatusLabels, taskStatusIcons, taskStatusColors, priorityColors } from '../../../constants';
import type { TaskType } from '../../../types';

export interface TasksListViewProps {
  tasks: TaskType[];
}

const TasksListView: React.FC<TasksListViewProps> = ({ tasks }) => {
  const { t } = useTranslation();
  const isOverdue = (dueDate: string | null): boolean => {
    return dueDate !== null && new Date(dueDate) < new Date();
  };

  return (
    <Card className="p-0">
      <Table hoverable>
        <TableHeader>
          <TableRow>
            <TableHead>{t('projects.task_label')}</TableHead>
            <TableHead>{t('common.status')}</TableHead>
            <TableHead>{t('common.priority')}</TableHead>
            <TableHead>{t('projects.due_date')}</TableHead>
            <TableHead>{t('projects.assignee')}</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {tasks.map((task) => {
            const StatusIcon = taskStatusIcons[task.status] || IconCircle;
            return (
              <TableRow key={task.id}>
                <TableCell>
                  <Link
                    to={`/tasks/${task.id}`}
                    className="font-medium text-[var(--text-primary)] hover:text-[var(--text-secondary)] transition-colors"
                  >
                    {task.title}
                  </Link>
                </TableCell>
                <TableCell>
                  <div className="flex items-center gap-2">
                    <div className={`p-1 rounded ${taskStatusColors[task.status]}`}>
                      <StatusIcon className="h-3.5 w-3.5" />
                    </div>
                    <span className="text-sm">{taskStatusLabels[task.status]}</span>
                  </div>
                </TableCell>
                <TableCell>
                  <div className="flex items-center gap-1">
                    <IconFlag className={`h-3.5 w-3.5 ${priorityColors[task.priority]}`} />
                    <span className="text-sm text-[var(--text-secondary)]">{task.priority}</span>
                  </div>
                </TableCell>
                <TableCell>
                  {task.due_date ? (
                    <span className={`text-sm ${isOverdue(task.due_date) && task.status !== 'completed' ? 'text-[var(--status-danger)] font-medium' : 'text-[var(--text-secondary)]'}`}>
                      {formatDate(task.due_date)}
                    </span>
                  ) : (
                    <span className="text-[var(--text-tertiary)]">-</span>
                  )}
                </TableCell>
                <TableCell>
                  {task.assignee ? (
                    <div className="flex items-center gap-2">
                      <div className="h-7 w-7 rounded-full bg-[var(--surface-muted)] flex items-center justify-center">
                        <span className="text-[var(--text-secondary)] text-xs font-medium">
                          {task.assignee.name.charAt(0)}
                        </span>
                      </div>
                      <span className="text-sm text-[var(--text-secondary)]">{task.assignee.name}</span>
                    </div>
                  ) : (
                    <span className="text-[var(--text-tertiary)]">-</span>
                  )}
                </TableCell>
              </TableRow>
            );
          })}
        </TableBody>
      </Table>
    </Card>
  );
};

export default TasksListView;
