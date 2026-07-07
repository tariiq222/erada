import React from 'react';
import { useTranslation } from 'react-i18next';
import {IconFlag, IconGripVertical, IconGitBranch, IconChevronDown, IconLoader} from '@tabler/icons-react';
import { formatDateShort } from '@shared/lib/utils';
import { priorityColors } from '../../../constants';
import type { TaskType, SubTask } from '../../../types';
import { renderTimeIndicator } from './renderTimeIndicator';
import SubtasksAccordion from './SubtasksAccordion';

export interface TaskCardProps {
  task: TaskType;
  draggedTask: TaskType | null;
  expandedSubtasks: number | null;
  loadingSubtasks: number | null;
  subtasksData: Record<number, SubTask[]>;
  openSubtaskStatusMenu: number | null;
  onDragStart: (e: React.DragEvent, task: TaskType) => void;
  onDragEnd: (e: React.DragEvent) => void;
  onTaskClick: (task: TaskType) => void;
  onToggleSubtasks: (e: React.MouseEvent, taskId: number) => void;
  onUpdateSubtaskStatus: (e: React.MouseEvent, parentId: number, subtaskId: number, newStatus: string) => void;
  onOpenSubtaskStatusMenu: (subtaskId: number | null) => void;
  isOverdue: (dueDate: string | null) => boolean;
}

const TaskCard: React.FC<TaskCardProps> = ({
  task,
  draggedTask,
  expandedSubtasks,
  loadingSubtasks,
  subtasksData,
  openSubtaskStatusMenu,
  onDragStart,
  onDragEnd,
  onTaskClick,
  onToggleSubtasks,
  onUpdateSubtaskStatus,
  onOpenSubtaskStatusMenu,
  isOverdue,
}) => {
  const { t } = useTranslation();
  const subtasks = subtasksData[task.id] || [];
  const hasInProgressOrReview = subtasks.some(s => s.status === 'in_progress' || s.status === 'in_review');
  const allCompleted = subtasks.length > 0 && subtasks.every(s => s.status === 'completed');

  let iconColor = 'text-[var(--text-tertiary)]';
  let bgColor = 'hover:bg-[var(--surface-subtle)]';
  if (allCompleted) {
    iconColor = 'text-[var(--status-success-text)]';
    bgColor = 'bg-[var(--status-success-subtle)] hover:bg-[var(--status-success-subtle)]';
  } else if (hasInProgressOrReview) {
    iconColor = 'text-[var(--status-danger)]';
    bgColor = 'bg-[var(--status-danger-subtle)] hover:bg-[var(--status-danger-subtle)]';
  }

  return (
    <div
      draggable
      onDragStart={(e) => onDragStart(e, task)}
      onDragEnd={onDragEnd}
      className={`group bg-[var(--surface-base)] rounded-lg p-2 border border-[var(--border-default)] shadow-sm hover:shadow-md hover:border-[var(--accent-subtle)] transition-[box-shadow,border-color,opacity,transform] cursor-grab active:cursor-grabbing ${
        draggedTask?.id === task.id ? 'opacity-50 scale-95' : ''
      }`}
    >
      {/* العنوان */}
      <div className="flex items-start gap-2">
        <IconGripVertical className="h-4 w-4 text-[var(--text-tertiary)] mt-0 opacity-0 group-hover:opacity-100 shrink-0" />
        <button
          type="button"
          onClick={(e) => {
            e.stopPropagation();
            onTaskClick(task);
          }}
          className="flex-1 font-medium text-[var(--text-primary)] text-sm hover:text-[var(--text-secondary)] line-clamp-2 text-start cursor-pointer"
        >
          {task.title}
        </button>
      </div>

      {/* المرحلة */}
      {task.milestone && (
        <div className="mt-1 flex items-center gap-1">
          <IconFlag className="h-3 w-3 text-[var(--text-tertiary)]" />
          <span className="text-xs text-[var(--text-tertiary)] truncate">{task.milestone.name}</span>
        </div>
      )}

      {/* المؤشر الزمني */}
      {task.time_indicator && task.time_indicator.has_due_date && (
        <div className="mt-2">
          {renderTimeIndicator(task.time_indicator, task.status)}
        </div>
      )}

      {/* التفاصيل السفلية */}
      <div className="flex items-center justify-between mt-2 pt-2 border-t border-[var(--border-default)]">
        <div className="flex items-center gap-2">
          <IconFlag className={`h-3.5 w-3.5 ${priorityColors[task.priority]}`} />
          {task.due_date && (
            <span className={`text-xs ${
              isOverdue(task.due_date) && task.status !== 'completed' ? 'text-[var(--status-danger-text)] font-medium' : 'text-[var(--text-tertiary)]'
            }`}>
              {formatDateShort(task.due_date)}
            </span>
          )}
        </div>
        <div className="flex items-center gap-2">
          {/* زر المهام الفرعية */}
          {(task.subtasks_count ?? 0) > 0 && (
            <button
              onClick={(e) => onToggleSubtasks(e, task.id)}
              className={`flex items-center gap-1 px-1 py-0 rounded text-xs transition-colors ${
                expandedSubtasks === task.id
                  ? 'bg-[var(--accent-subtle)] text-[var(--accent-default)]'
                  : `${iconColor} ${bgColor}`
              }`}
              title={t('projects.view_subtasks')}
            >
              {loadingSubtasks === task.id ? (
                <IconLoader className="h-3 w-3 animate-spin" />
              ) : (
                <IconGitBranch className="h-3 w-3" />
              )}
              <span>{task.subtasks_count}</span>
              <IconChevronDown className={`h-3 w-3 transition-transform ${expandedSubtasks === task.id ? 'rotate-180' : ''}`} />
            </button>
          )}
          {task.assignee && (
            <div className="h-6 w-6 rounded-full bg-[var(--surface-muted)] flex items-center justify-center" title={task.assignee.name}>
              <span className="text-[var(--text-secondary)] text-xs font-bold">{task.assignee.name.charAt(0)}</span>
            </div>
          )}
        </div>
      </div>

      {/* أكورديون المهام الفرعية */}
      {expandedSubtasks === task.id && subtasksData[task.id] && (
        <SubtasksAccordion
          taskId={task.id}
          subtasks={subtasksData[task.id]}
          openSubtaskStatusMenu={openSubtaskStatusMenu}
          onUpdateSubtaskStatus={onUpdateSubtaskStatus}
          onOpenSubtaskStatusMenu={onOpenSubtaskStatusMenu}
        />
      )}
    </div>
  );
};

export default TaskCard;
