import React from 'react';
import { useTranslation } from 'react-i18next';
import { formatDate } from '@shared/lib/utils';
import { TaskTimeIndicator } from '@widgets/task';
import {IconUser, IconAlertTriangle, IconCalendar, IconActivity, IconLayoutKanban} from '@tabler/icons-react';
import type { TaskDetails } from './types';
import { StatusIconBox } from '@shared/ui/StatusIconBox';

interface DetailsTabProps {
  task: TaskDetails;
  isOverdue: boolean;
}

const DetailsTab: React.FC<DetailsTabProps> = ({ task, isOverdue }) => {
  const { t } = useTranslation();
  return (
    <div className="space-y-6">
      {/* Description */}
      <div className="bg-[var(--surface-subtle)] rounded-xl p-5">
        <h4 className="text-sm font-semibold text-[var(--text-primary)] mb-3">{t('tasks.description')}</h4>
        {task.description ? (
            <p className="text-[var(--text-primary)] text-sm whitespace-pre-wrap leading-relaxed">
            {task.description}
          </p>
        ) : (
          <p className="text-[var(--text-tertiary)] text-sm">{t('tasks.no_description')}</p>
        )}
      </div>

      {/* Task Info Grid */}
      <div className="bg-[var(--surface-subtle)] rounded-xl p-5">
        <h4 className="text-sm font-semibold text-[var(--text-primary)] mb-4">{t('tasks.task_info')}</h4>
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
          {/* Assignee */}
          <div className="flex items-center gap-3">
            <StatusIconBox status="info">
              <IconUser className="h-4 w-4 text-[var(--accent-default)]" />
            </StatusIconBox>
            <div>
              <p className="text-xs text-[var(--text-tertiary)]">{t('tasks.assignee')}</p>
              {task.assignee ? (
                <p className="text-sm font-medium text-[var(--text-primary)]">{task.assignee.name}</p>
              ) : (
                <p className="text-sm text-[var(--text-tertiary)]">{t('tasks.unassigned')}</p>
              )}
            </div>
          </div>

          {/* Priority */}
          <div className="flex items-center gap-3">
            <StatusIconBox status="warn">
              <IconAlertTriangle className="h-4 w-4 text-[var(--status-warning)]" />
            </StatusIconBox>
            <div>
              <p className="text-xs text-[var(--text-tertiary)]">{t('common.priority')}</p>
              <p className="text-sm font-medium text-[var(--text-primary)]">
                {t(`priority.${task.priority}`)}
              </p>
            </div>
          </div>

          {/* Due Date */}
          {task.due_date && (
            <div className="flex items-center gap-3">
            <StatusIconBox status="info">
              <IconCalendar className="h-4 w-4 text-[var(--accent-default)]" />
            </StatusIconBox>
              <div>
                <p className="text-xs text-[var(--text-tertiary)]">{t('common.due_date')}</p>
                <p className={`text-sm font-medium ${isOverdue ? 'text-[var(--status-danger)]' : 'text-[var(--text-primary)]'}`}>
                  {formatDate(task.due_date)}
                </p>
              </div>
            </div>
          )}

          {/* Creator */}
          {task.creator && (
            <div className="flex items-center gap-3">
            <StatusIconBox status="neutral">
              <IconUser className="h-4 w-4 text-[var(--text-secondary)]" />
            </StatusIconBox>
              <div>
                <p className="text-xs text-[var(--text-tertiary)]">{t('tasks.creator')}</p>
                <p className="text-sm font-medium text-[var(--text-primary)]">{task.creator.name}</p>
              </div>
            </div>
          )}

          {/* Milestone */}
          {task.milestone && (
            <div className="flex items-center gap-3">
            <StatusIconBox status="info">
              <IconLayoutKanban className="h-4 w-4 text-[var(--accent-default)]" />
            </StatusIconBox>
              <div>
                <p className="text-xs text-[var(--text-tertiary)]">{t('tasks.milestone')}</p>
                <p className="text-sm font-medium text-[var(--text-primary)]">{task.milestone.name}</p>
              </div>
            </div>
          )}

          {/* Hours */}
          {(task.estimated_hours || task.actual_hours) && (
            <div className="flex items-center gap-3">
            <StatusIconBox status="ok">
              <IconActivity className="h-4 w-4 text-[var(--status-success)]" />
            </StatusIconBox>
              <div>
                <p className="text-xs text-[var(--text-tertiary)]">{t('tasks.hours')}</p>
                <p className="text-sm font-medium text-[var(--text-primary)]">
                  {task.actual_hours || 0} / {task.estimated_hours || 0} {t('tasks.hour')}
                </p>
              </div>
            </div>
          )}
        </div>
      </div>

      {/* Time Indicator (for mobile) */}
      <div className="lg:hidden">
        {task.time_indicator?.has_due_date && (
          <TaskTimeIndicator
            indicator={task.time_indicator}
            taskStatus={task.status}
            variant="detailed"
          />
        )}
      </div>
    </div>
  );
};

export default DetailsTab;
