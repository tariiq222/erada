import React from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router-dom';
import { Card, CardHeader, CardTitle, CardContent, EmptyState } from '@shared/ui';
import {IconCalendarCheck, IconArrowLeft, IconClock, IconTag, IconCircleCheck} from '@tabler/icons-react';
import { formatDate } from '@shared/lib/utils';

interface UpcomingTask {
  id: number;
  title: string;
  due_date: string;
  status: string;
  priority: string;
  project: { id: number; name: string; code: string } | null;
}

interface UpcomingTasksCardProps {
  data: UpcomingTask[];
  loading?: boolean;
  onComplete?: (taskId: number) => void;
}

const priorityColors: Record<string, string> = {
  urgent: 'var(--status-danger)',
  high: 'var(--status-warning)',
  medium: 'var(--accent-default)',
  low: 'var(--text-tertiary)',
};

const getDueDateInfo = (dateStr: string, t: (key: string, opts?: Record<string, unknown>) => string): { label: string; color: string } => {
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  const dueDate = new Date(dateStr);
  dueDate.setHours(0, 0, 0, 0);

  const diffDays = Math.ceil((dueDate.getTime() - today.getTime()) / (1000 * 60 * 60 * 24));

  if (diffDays === 0) {
    return { label: t('tasks.due_today'), color: 'var(--status-danger)' };
  } else if (diffDays === 1) {
    return { label: t('tasks.remaining_days', { count: 1 }), color: 'var(--status-warning)' };
  } else if (diffDays <= 7) {
    return { label: t('tasks.remaining_days', { count: diffDays }), color: 'var(--accent-default)' };
  } else {
    return { label: formatDate(dateStr), color: 'var(--text-tertiary)' };
  }
};

export const UpcomingTasksCard: React.FC<UpcomingTasksCardProps> = ({
  data,
  loading,
  onComplete,
}) => {
  const { t } = useTranslation();
  if (loading) {
    return (
      <Card>
        <CardHeader className="border-b border-[var(--border-default)] pb-3">
          <CardTitle className="flex items-center gap-2 text-sm">
            <IconCalendarCheck className="h-4 w-4 text-[var(--accent-default)]" />
            {t('dashboard.upcoming_tasks')}
          </CardTitle>
        </CardHeader>
        <CardContent className="p-4">
          <div className="space-y-3 animate-pulse">
            {[1, 2, 3, 4].map((i) => (
              <div key={i} className="p-3 border border-[var(--border-default)] rounded-lg">
                <div className="h-4 bg-[var(--surface-muted)] rounded w-3/4 mb-2" />
                <div className="h-3 bg-[var(--surface-muted)] rounded w-1/2" />
              </div>
            ))}
          </div>
        </CardContent>
      </Card>
    );
  }

  return (
    <Card>
      <CardHeader className="border-b border-[var(--border-default)] pb-3">
        <div className="flex items-center justify-between">
          <CardTitle className="flex items-center gap-2 text-sm">
            <IconCalendarCheck className="h-4 w-4 text-[var(--accent-default)]" />
            {t('dashboard.upcoming_tasks')}
          </CardTitle>
          <Link
            to="/my-tasks"
            className="text-sm font-medium text-[var(--accent-default)] hover:text-[var(--accent-hover)] flex items-center gap-1"
          >
            {t('common.view_all')}
            <IconArrowLeft className="h-4 w-4 rtl:rotate-180" />
          </Link>
        </div>
      </CardHeader>
      <CardContent className="p-4">
        {data.length === 0 ? (
          <EmptyState
            icon={IconCircleCheck}
            title={t('dashboard.no_upcoming_tasks')}
            description={t('dashboard.all_on_time')}
            iconTone="success"
            size="md"
          />
        ) : (
          <div className="space-y-2">
            {data.slice(0, 5).map((task) => {
              const dueInfo = getDueDateInfo(task.due_date, t);
              return (
                <div
                  key={task.id}
                  className="group flex items-start gap-3 p-3 rounded-lg border border-[var(--border-default)] hover:border-[var(--accent-default)] hover:bg-[var(--accent-subtle)] transition-colors"
                >
                  {onComplete && (
                    <button
                      onClick={() => onComplete(task.id)}
                      className="mt-0 shrink-0 w-5 h-5 rounded border-2 border-[var(--border-default)] hover:border-[var(--accent-default)] hover:bg-[var(--accent-default)] hover:text-[var(--text-inverse)] transition-colors flex items-center justify-center group-hover:border-[var(--accent-default)]"
                      title={t('tasks.complete')}
                    >
                      <IconCircleCheck className="h-3 w-3 opacity-0 group-hover:opacity-100 transition-opacity" />
                    </button>
                  )}
                  <Link to={`/tasks/${task.id}`} className="flex-1 min-w-0">
                    <div className="flex items-start justify-between gap-2">
                      <h4 className="text-sm font-medium text-[var(--text-primary)] truncate">
                        {task.title}
                      </h4>
                      <div
                        className="w-2 h-2 rounded-full shrink-0 mt-1"
                        style={{ backgroundColor: priorityColors[task.priority] || priorityColors.medium }}
                        title={task.priority}
                      />
                    </div>
                    <div className="flex flex-wrap items-center gap-3 mt-1 text-xs">
                      <span
                        className="flex items-center gap-1"
                        style={{ color: dueInfo.color }}
                      >
                        <IconClock className="h-3 w-3" />
                        {dueInfo.label}
                      </span>
                      {task.project && (
                        <span className="flex items-center gap-1 text-[var(--text-tertiary)]">
                          <IconTag className="h-3 w-3" />
                          {task.project.code}
                        </span>
                      )}
                    </div>
                  </Link>
                </div>
              );
            })}
          </div>
        )}
      </CardContent>
    </Card>
  );
};

export default UpcomingTasksCard;
