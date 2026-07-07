import React, { memo } from 'react';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { formatDateShort } from '@shared/lib/utils';
import {IconFlag, IconLayoutKanban, IconGripVertical, IconGitBranch, IconChevronDown, IconCircle, IconLoader} from '@tabler/icons-react';
import { statusLabels, statusIcons } from './constants';
import {
  TASK_STATUS_CLASS,
  PRIORITY_TEXT,
  KANBAN_TASK_COLUMN_TOKENS,
  SUBTASK_AGGREGATE_TOKENS,
  type TaskStatusKey,
  type PriorityKey,
} from '@shared/lib/statusTokens';
import TimeIndicator from './TimeIndicator';
import type { Task, SubTask } from './types';

interface KanbanViewProps {
  tasks: Task[];
  draggedTask: Task | null;
  dragOverColumn: string | null;
  expandedSubtasks: number | null;
  loadingSubtasks: number | null;
  subtasksData: Record<number, SubTask[]>;
  onDragStart: (e: React.DragEvent, task: Task) => void;
  onDragEnd: (e: React.DragEvent) => void;
  onDragOver: (e: React.DragEvent, status: string) => void;
  onDragLeave: () => void;
  onDrop: (e: React.DragEvent, status: string) => void;
  onOpenTaskModal: (taskId: number) => void;
  onToggleSubtasks: (e: React.MouseEvent, taskId: number) => void;
  onUpdateSubtaskStatus: (e: React.MouseEvent, parentId: number, subtaskId: number, newStatus: string) => void;
  isOverdue: (task: Task) => boolean;
}

const KanbanView = memo<KanbanViewProps>(({
  tasks,
  draggedTask,
  dragOverColumn,
  expandedSubtasks,
  loadingSubtasks,
  subtasksData,
  onDragStart,
  onDragEnd,
  onDragOver,
  onDragLeave,
  onDrop,
  onOpenTaskModal,
  onToggleSubtasks,
  onUpdateSubtaskStatus,
  isOverdue,
}) => {
  const { t } = useTranslation();
  const kanbanStatuses = ['todo', 'in_progress', 'in_review', 'completed'] as const;

  return (
    <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
      {kanbanStatuses.map((status) => {
        const StatusIcon = statusIcons[status] || IconCircle;
        const statusTasks = tasks.filter(t => t.status === status);
        const columnStyle = KANBAN_TASK_COLUMN_TOKENS[status as TaskStatusKey];
        const isDropTarget = dragOverColumn === status && draggedTask?.status !== status;

        return (
          <div
            key={status}
            className={`min-w-[200px] rounded-xl border transition-[border-color,box-shadow] duration-300 ${columnStyle.bg} ${
              isDropTarget
                ? 'border-[var(--accent-default)] ring-2 ring-[var(--accent-subtle)]'
                : columnStyle.border
            }`}
            onDragOver={(e) => onDragOver(e, status)}
            onDragLeave={onDragLeave}
            onDrop={(e) => onDrop(e, status)}
          >
            {/* Column Header */}
            <div className={`sticky top-0 px-3 py-2 rounded-t-xl ${columnStyle.headerBg} border-b ${columnStyle.border}`}>
              <div className="flex items-center justify-between">
                <div className="flex items-center gap-2">
                  <StatusIcon className={`h-4 w-4 ${columnStyle.headerText}`} />
                  <span className={`font-medium text-sm ${columnStyle.headerText}`}>
                    {t(statusLabels[status])}
                  </span>
                </div>
                <span className={`text-xs font-bold px-2 py-0 rounded-full bg-[var(--surface-base)]/80 ${columnStyle.headerText}`}>
                  {statusTasks.length}
                </span>
              </div>
            </div>

            {/* Tasks Container */}
            <div className="p-2 space-y-2 min-h-[100px]">
              {statusTasks.map((task) => (
                <KanbanTaskCard
                  key={task.id}
                  task={task}
                  draggedTask={draggedTask}
                  expandedSubtasks={expandedSubtasks}
                  loadingSubtasks={loadingSubtasks}
                  subtasksData={subtasksData}
                  onDragStart={onDragStart}
                  onDragEnd={onDragEnd}
                  onOpenTaskModal={onOpenTaskModal}
                  onToggleSubtasks={onToggleSubtasks}
                  onUpdateSubtaskStatus={onUpdateSubtaskStatus}
                  isOverdue={isOverdue}
                />
              ))}

              {/* Empty State */}
              {statusTasks.length === 0 && (
                <div className={`text-center py-8 rounded-lg border border-dashed ${columnStyle.border}`}>
                  <StatusIcon className={`h-6 w-6 mx-auto mb-2 ${columnStyle.headerText} opacity-40`} />
                  <p className={`text-sm ${columnStyle.headerText} opacity-60`}>{t('tasks.no_tasks')}</p>
                </div>
              )}

              {/* Drop Zone */}
              {isDropTarget && (
                <div className="border-2 border-dashed border-[var(--border-strong)] rounded-lg p-3 bg-[var(--surface-subtle)] text-center">
                  <p className="text-sm font-medium text-[var(--text-secondary)]">{t('tasks.drop_here')}</p>
                </div>
              )}
            </div>
          </div>
        );
      })}
    </div>
  );
});

interface KanbanTaskCardProps {
  task: Task;
  draggedTask: Task | null;
  expandedSubtasks: number | null;
  loadingSubtasks: number | null;
  subtasksData: Record<number, SubTask[]>;
  onDragStart: (e: React.DragEvent, task: Task) => void;
  onDragEnd: (e: React.DragEvent) => void;
  onOpenTaskModal: (taskId: number) => void;
  onToggleSubtasks: (e: React.MouseEvent, taskId: number) => void;
  onUpdateSubtaskStatus: (e: React.MouseEvent, parentId: number, subtaskId: number, newStatus: string) => void;
  isOverdue: (task: Task) => boolean;
}

const KanbanTaskCard = memo<KanbanTaskCardProps>(({
  task,
  draggedTask,
  expandedSubtasks,
  loadingSubtasks,
  subtasksData,
  onDragStart,
  onDragEnd,
  onOpenTaskModal,
  onToggleSubtasks,
  onUpdateSubtaskStatus,
  isOverdue,
}) => {
  const { t } = useTranslation();
  const subtasks = subtasksData[task.id] || [];
  const hasInProgressOrReview = subtasks.some(s => s.status === 'in_progress' || s.status === 'in_review');
  const allCompleted = subtasks.length > 0 && subtasks.every(s => s.status === 'completed');

  const aggregateKey: 'default' | 'allCompleted' | 'hasInProgressOrReview' = allCompleted
    ? 'allCompleted'
    : hasInProgressOrReview
      ? 'hasInProgressOrReview'
      : 'default';
  const { iconColor, bgColor } = SUBTASK_AGGREGATE_TOKENS[aggregateKey];

  return (
    <div
      data-testid="kanban-task-card"
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
            onOpenTaskModal(task.id);
          }}
          className="flex-1 font-medium text-[var(--text-primary)] text-sm hover:text-[var(--text-primary)] line-clamp-2 text-start cursor-pointer"
        >
          {task.title}
        </button>
      </div>

      {/* Project Badge */}
      {task.project && (
        <div className="mt-1 flex items-center gap-1">
          <Link
            to={`/projects/${task.project.id}`}
            onClick={(e) => e.stopPropagation()}
            className="inline-flex items-center gap-1 px-2 py-0 bg-[var(--accent-subtle)] text-[var(--accent-default)] rounded text-xs font-medium hover:bg-[var(--accent-default)] hover:text-[var(--text-inverse)] transition-colors"
          >
            <IconLayoutKanban className="h-3 w-3" />
            {task.project.code}
          </Link>
        </div>
      )}

      {/* المؤشر الزمني */}
      {task.time_indicator && task.time_indicator.has_due_date && (
        <div className="mt-2">
          <TimeIndicator indicator={task.time_indicator} taskStatus={task.status} />
        </div>
      )}

      {/* التفاصيل السفلية */}
      <div className="flex items-center justify-between mt-2 pt-2 border-t border-[var(--border-default)]">
        <div className="flex items-center gap-2">
          <IconFlag className={`h-3.5 w-3.5 ${PRIORITY_TEXT[task.priority as PriorityKey]}`} />
          {task.due_date && (
            <span className={`text-xs ${
              isOverdue(task) && task.status !== 'completed' ? 'text-[var(--status-danger)] font-medium' : 'text-[var(--text-secondary)]'
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
              title={t('tasks.show_subtasks')}
              aria-label={t('tasks.show_subtasks')}
            >
              {loadingSubtasks === task.id ? (
                <IconLoader data-testid="subtask-loading-spinner" className="h-3 w-3 animate-spin" />
              ) : (
                <IconGitBranch className="h-3 w-3" />
              )}
              <span>{task.subtasks_count}</span>
              <IconChevronDown className={`h-3 w-3 transition-transform ${expandedSubtasks === task.id ? 'rotate-180' : ''}`} />
            </button>
          )}
          {task.assignee && (
            <div className="h-6 w-6 rounded-full bg-[var(--surface-muted)] flex items-center justify-center" title={task.assignee.name}>
              <span className="text-[var(--text-primary)] text-xs font-bold">{task.assignee.name.charAt(0)}</span>
            </div>
          )}
        </div>
      </div>

      {/* أكورديون المهام الفرعية */}
      {expandedSubtasks === task.id && subtasksData[task.id] && (
        <SubtasksAccordion
          taskId={task.id}
          subtasks={subtasksData[task.id]}
          onOpenTaskModal={onOpenTaskModal}
          onUpdateSubtaskStatus={onUpdateSubtaskStatus}
        />
      )}
    </div>
  );
});

interface SubtasksAccordionProps {
  taskId: number;
  subtasks: SubTask[];
  onOpenTaskModal: (taskId: number) => void;
  onUpdateSubtaskStatus: (e: React.MouseEvent, parentId: number, subtaskId: number, newStatus: string) => void;
}

const SubtasksAccordion = memo<SubtasksAccordionProps>(({
  taskId,
  subtasks,
  onOpenTaskModal,
  onUpdateSubtaskStatus,
}) => {
  const { t } = useTranslation();
  const subtaskStatuses = ['todo', 'in_progress', 'in_review', 'completed'] as const;

  return (
    <div className="mt-2 pt-2 border-t border-[var(--border-default)] space-y-1">
      {subtasks.length === 0 ? (
        <div className="text-center text-xs text-[var(--text-tertiary)] py-2">
          {t('tasks.no_subtasks')}
        </div>
      ) : (
        subtasks.map((subtask) => {
          const SubtaskIcon = statusIcons[subtask.status] || IconCircle;
          return (
            <div
              key={subtask.id}
              className="flex items-center gap-2 p-1 rounded hover:bg-[var(--surface-subtle)] transition-colors"
            >
              <button
                onClick={(e) => {
                  e.stopPropagation();
                  onOpenTaskModal(subtask.id);
                }}
                className="flex-1 flex items-center gap-2 text-start min-w-0"
              >
                <div className={`p-1 rounded ${TASK_STATUS_CLASS[subtask.status as TaskStatusKey]}`}>
                  <SubtaskIcon className="h-3 w-3" />
                </div>
                <span className="flex-1 text-xs text-[var(--text-primary)] truncate">
                  {subtask.title}
                </span>
              </button>
              {/* Status Change Buttons */}
              <div className="flex items-center gap-0 shrink-0">
                {subtaskStatuses.map((st) => {
                  const StIcon = statusIcons[st];
                  const isActive = subtask.status === st;
                  return (
                    <button
                      key={st}
                      onClick={(e) => onUpdateSubtaskStatus(e, taskId, subtask.id, st)}
                      className={`p-1 rounded transition-colors ${
                        isActive
                          ? TASK_STATUS_CLASS[st as TaskStatusKey]
                          : 'text-[var(--text-tertiary)] hover:text-[var(--text-secondary)] hover:bg-[var(--surface-muted)]'
                      }`}
                      title={t(statusLabels[st])}
                      aria-label={t(statusLabels[st])}
                    >
                      <StIcon className="h-3 w-3" />
                    </button>
                  );
                })}
              </div>
            </div>
          );
        })
      )}
    </div>
  );
});

KanbanView.displayName = 'KanbanView';
KanbanTaskCard.displayName = 'KanbanTaskCard';
SubtasksAccordion.displayName = 'SubtasksAccordion';

export default KanbanView;
