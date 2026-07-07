import React from 'react';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { formatDate } from '@shared/lib/utils';
import { StatusBadge } from '@shared/ui';
import TaskStatusChanger from './TaskStatusChanger';
import TaskTimeIndicator from './TaskTimeIndicator';
import {IconUser, IconCalendar, IconFlag, IconTarget, IconClockHour4, IconLayoutKanban, IconListTree, IconAlertTriangle} from '@tabler/icons-react';

interface TimeIndicator {
  days_remaining: number | null;
  days_elapsed: number | null;
  total_days: number | null;
  time_progress: number | null;
  status: 'normal' | 'warning' | 'urgent' | 'overdue' | 'completed';
  has_due_date: boolean;
}

interface Subtask {
  id: number;
  title: string;
  status: string;
}

interface TaskData {
  id: number;
  title: string;
  description: string | null;
  status: string;
  priority: string;
  start_date: string | null;
  due_date: string | null;
  completed_date: string | null;
  estimated_hours: number | null;
  actual_hours: number | null;
  time_indicator: TimeIndicator;
  project: { id: number; code: string; name: string; type?: string | null } | null;
  milestone: { id: number; name: string } | null;
  assignee: { id: number; name: string; email: string } | null;
  creator: { id: number; name: string } | null;
  parent: { id: number; title: string } | null;
  subtasks?: Subtask[];
  abilities?: { edit?: boolean; complete?: boolean };
}

interface TaskDetailsPanelProps {
  task: TaskData;
  onStatusChange?: (newStatus: string) => void;
  onClose?: () => void;
  variant?: 'sidebar' | 'inline';
  showTimeIndicator?: boolean;
}

const priorityConfig: Record<string, { labelKey: string; badgeKey?: 'low' | 'medium' | 'high' | 'critical'; color?: 'warning' | 'danger' | 'secondary' | 'info' }> = {
  low: { labelKey: 'priority.low', badgeKey: 'low' },
  medium: { labelKey: 'priority.medium', badgeKey: 'medium' },
  high: { labelKey: 'priority.high', badgeKey: 'high' },
  critical: { labelKey: 'priority.critical', badgeKey: 'critical' },
  urgent: { labelKey: 'priority.urgent', color: 'warning' },
};

const TaskDetailsPanel: React.FC<TaskDetailsPanelProps> = ({
  task,
  onStatusChange,
  onClose,
  variant = 'sidebar',
  showTimeIndicator = true,
}) => {
  const { t } = useTranslation();
  const priority = priorityConfig[task.priority] || priorityConfig.medium;
  const isOverdue = task.due_date &&
    new Date(task.due_date) < new Date() &&
    task.status !== 'completed';

  const sectionClass = variant === 'sidebar'
    ? 'bg-[var(--surface-subtle)] rounded-xl p-4'
    : 'border-b border-[var(--border-default)] pb-4';

  return (
    <div className="space-y-4">
      {/* Status Changer */}
      <div className={sectionClass}>
        <div className="flex items-center justify-between mb-3">
          <span className="text-sm font-medium text-[var(--text-secondary)] flex items-center gap-2">
            <span className="h-1.5 w-1.5 rounded-full bg-[var(--support-teal-default)]" aria-hidden="true" />
            الحالة
          </span>
          {isOverdue && (
            <span className="flex items-center gap-1 text-xs text-[var(--status-danger)] bg-[var(--status-danger-subtle)] px-2 py-0 rounded-full">
              <IconAlertTriangle className="h-3 w-3" />
              متأخرة
            </span>
          )}
        </div>
        {task.abilities?.edit ? (
          <TaskStatusChanger
            taskId={task.id}
            currentStatus={task.status}
            onStatusChange={onStatusChange}
            size="md"
            showLabel={true}
            hasProject={!!task.project}
            subtasks={task.subtasks}
            isImprovement={task.project?.type === 'improvement'}
            taskTitle={task.title}
          />
        ) : (
          <StatusBadge type="task" status={task.status} size="md" />
        )}
      </div>

      {/* Time Indicator */}
      {showTimeIndicator && task.time_indicator?.has_due_date && (
        <TaskTimeIndicator
          indicator={task.time_indicator}
          taskStatus={task.status}
          variant={variant === 'sidebar' ? 'detailed' : 'standard'}
        />
      )}

      {/* Task Info */}
      <div className={sectionClass}>
        <h4 className="text-sm font-semibold text-[var(--text-primary)] mb-4">معلومات المهمة</h4>
        <div className="space-y-4">
          {/* Project */}
          {task.project && (
            <div className="flex items-center justify-between">
              <span className="text-sm text-[var(--text-secondary)] flex items-center gap-2">
                <IconLayoutKanban className="h-4 w-4" />
                المشروع
              </span>
              <Link
                to={`/projects/${task.project.id}`}
                onClick={onClose}
                className="text-sm font-medium text-[var(--accent-default)] hover:underline flex items-center gap-1"
              >
                <span className="px-2 py-0 bg-[var(--accent-subtle)] rounded text-xs">
                  {task.project.code}
                </span>
              </Link>
            </div>
          )}

          {/* Priority */}
          <div className="flex items-center justify-between">
            <span className="text-sm text-[var(--text-secondary)] flex items-center gap-2">
              <IconFlag className="h-4 w-4" />
              الأولوية
            </span>
            {priority.badgeKey ? (
              <StatusBadge type="priority" status={priority.badgeKey} size="sm" />
            ) : (
              <StatusBadge
                type="custom"
                status={task.priority}
                label={t(priority.labelKey)}
                color={priority.color || 'warning'}
                size="sm"
              />
            )}
          </div>

          {/* Assignee */}
          <div className="flex items-center justify-between">
            <span className="text-sm text-[var(--text-secondary)] flex items-center gap-2">
              <IconUser className="h-4 w-4" />
              المكلف
            </span>
            {task.assignee ? (
              <div className="flex items-center gap-2">
                <div className="h-7 w-7 rounded-full bg-[var(--accent-default)] flex items-center justify-center">
                  <span className="text-[var(--text-inverse)] text-xs font-bold">
                    {task.assignee.name.charAt(0)}
                  </span>
                </div>
                <span className="text-sm font-medium text-[var(--text-primary)]">
                  {task.assignee.name}
                </span>
              </div>
            ) : (
              <span className="text-sm text-[var(--text-tertiary)]">غير محدد</span>
            )}
          </div>

          {/* Creator */}
          {task.creator && (
            <div className="flex items-center justify-between">
              <span className="text-sm text-[var(--text-secondary)] flex items-center gap-2">
                <IconUser className="h-4 w-4" />
                المنشئ
              </span>
              <span className="text-sm text-[var(--text-primary)]">{task.creator.name}</span>
            </div>
          )}

          {/* IconFlag */}
          {task.milestone && (
            <div className="flex items-center justify-between">
              <span className="text-sm text-[var(--text-secondary)] flex items-center gap-2">
                <IconTarget className="h-4 w-4" />
                المرحلة
              </span>
              <span className="text-sm text-[var(--text-primary)]">{task.milestone.name}</span>
            </div>
          )}

          {/* Parent Task */}
          {task.parent && (
            <div className="flex items-center justify-between">
              <span className="text-sm text-[var(--text-secondary)] flex items-center gap-2">
                <IconListTree className="h-4 w-4" />
                المهمة الأم
              </span>
              <Link
                to={`/tasks/${task.parent.id}`}
                onClick={onClose}
                className="text-sm text-[var(--accent-default)] hover:underline truncate max-w-[150px]"
              >
                {task.parent.title}
              </Link>
            </div>
          )}
        </div>
      </div>

      {/* Dates */}
      <div className={sectionClass}>
        <h4 className="text-sm font-semibold text-[var(--text-primary)] flex items-center gap-2 mb-4">
          <IconCalendar className="h-4 w-4 text-[var(--accent-default)]" />
          التواريخ
        </h4>
        <div className="space-y-3">
          {task.start_date && (
            <div className="flex items-center justify-between">
              <span className="text-sm text-[var(--text-secondary)]">تاريخ البدء</span>
              <span className="text-sm text-[var(--text-primary)]">
                {formatDate(task.start_date)}
              </span>
            </div>
          )}
          {task.due_date && (
            <div className="flex items-center justify-between">
              <span className="text-sm text-[var(--text-secondary)]">تاريخ الاستحقاق</span>
              <span className={`text-sm font-medium ${isOverdue ? 'text-[var(--status-danger)]' : 'text-[var(--text-primary)]'}`}>
                {formatDate(task.due_date)}
              </span>
            </div>
          )}
          {task.completed_date && (
            <div className="flex items-center justify-between">
              <span className="text-sm text-[var(--text-secondary)]">تاريخ الإنجاز</span>
              <span className="text-sm text-[var(--status-success)] font-medium">
                {formatDate(task.completed_date)}
              </span>
            </div>
          )}
        </div>
      </div>

      {/* Hours */}
      {(task.estimated_hours || task.actual_hours) && (
        <div className={sectionClass}>
          <h4 className="text-sm font-semibold text-[var(--text-primary)] flex items-center gap-2 mb-4">
            <IconClockHour4 className="h-4 w-4 text-[var(--accent-default)]" />
            الساعات
          </h4>
          <div className="space-y-3">
            {task.estimated_hours !== null && (
              <div className="flex items-center justify-between">
                <span className="text-sm text-[var(--text-secondary)]">المقدرة</span>
                <span className="text-sm text-[var(--text-primary)]">{task.estimated_hours} ساعة</span>
              </div>
            )}
            {task.actual_hours !== null && (
              <div className="flex items-center justify-between">
                <span className="text-sm text-[var(--text-secondary)]">الفعلية</span>
                <span className="text-sm text-[var(--text-primary)]">{task.actual_hours} ساعة</span>
              </div>
            )}
            {task.estimated_hours && task.actual_hours && (
              <div className="pt-2 border-t border-[var(--border-default)]">
                <div className="flex items-center justify-between">
                  <span className="text-sm text-[var(--text-secondary)]">الفرق</span>
                  <span className={`text-sm font-medium ${
                    task.actual_hours > task.estimated_hours
                      ? 'text-[var(--status-danger)]'
                      : 'text-[var(--status-success)]'
                  }`}>
                    {task.actual_hours > task.estimated_hours ? '+' : '-'}
                    {Math.abs(task.actual_hours - task.estimated_hours)} ساعة
                  </span>
                </div>
              </div>
            )}
          </div>
        </div>
      )}
    </div>
  );
};

export default TaskDetailsPanel;
