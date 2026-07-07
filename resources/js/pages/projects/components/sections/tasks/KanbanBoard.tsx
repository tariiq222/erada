import React from 'react';
import { useTranslation } from 'react-i18next';
import {IconCircle} from '@tabler/icons-react';
import {
  taskStatusLabels,
  taskStatusIcons,
  kanbanColumnStyles,
  STATUS_ORDER,
} from '../../../constants';
import type { TaskType, SubTask } from '../../../types';
import TaskCard from './TaskCard';

export interface KanbanBoardProps {
  tasks: TaskType[];
  draggedTask: TaskType | null;
  dragOverColumn: string | null;
  expandedSubtasks: number | null;
  loadingSubtasks: number | null;
  subtasksData: Record<number, SubTask[]>;
  openSubtaskStatusMenu: number | null;
  onDragStart: (e: React.DragEvent, task: TaskType) => void;
  onDragEnd: (e: React.DragEvent) => void;
  onDragOver: (e: React.DragEvent, status: string) => void;
  onDragLeave: () => void;
  onDrop: (e: React.DragEvent, status: string) => void;
  onTaskClick: (task: TaskType) => void;
  onToggleSubtasks: (e: React.MouseEvent, taskId: number) => void;
  onUpdateSubtaskStatus: (e: React.MouseEvent, parentId: number, subtaskId: number, newStatus: string) => void;
  onOpenSubtaskStatusMenu: (subtaskId: number | null) => void;
}

const KanbanBoard: React.FC<KanbanBoardProps> = ({
  tasks,
  draggedTask,
  dragOverColumn,
  expandedSubtasks,
  loadingSubtasks,
  subtasksData,
  openSubtaskStatusMenu,
  onDragStart,
  onDragEnd,
  onDragOver,
  onDragLeave,
  onDrop,
  onTaskClick,
  onToggleSubtasks,
  onUpdateSubtaskStatus,
  onOpenSubtaskStatusMenu,
}) => {
  const { t } = useTranslation();
  const isOverdue = (dueDate: string | null): boolean => {
    return dueDate !== null && new Date(dueDate) < new Date();
  };

  return (
    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
      {STATUS_ORDER.map((status) => {
        const StatusIcon = taskStatusIcons[status] || IconCircle;
        const statusTasks = tasks.filter(t => t.status === status);
        const columnStyle = kanbanColumnStyles[status] || kanbanColumnStyles.todo;
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
                    {taskStatusLabels[status]}
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
                <TaskCard
                  key={task.id}
                  task={task}
                  draggedTask={draggedTask}
                  expandedSubtasks={expandedSubtasks}
                  loadingSubtasks={loadingSubtasks}
                  subtasksData={subtasksData}
                  openSubtaskStatusMenu={openSubtaskStatusMenu}
                  onDragStart={onDragStart}
                  onDragEnd={onDragEnd}
                  onTaskClick={onTaskClick}
                  onToggleSubtasks={onToggleSubtasks}
                  onUpdateSubtaskStatus={onUpdateSubtaskStatus}
                  onOpenSubtaskStatusMenu={onOpenSubtaskStatusMenu}
                  isOverdue={isOverdue}
                />
              ))}

              {/* Empty State */}
              {statusTasks.length === 0 && (
                <div className={`text-center py-8 rounded-lg border border-dashed ${columnStyle.border}`}>
                  <StatusIcon className={`h-6 w-6 mx-auto mb-2 ${columnStyle.headerText} opacity-40`} />
                  <p className={`text-sm ${columnStyle.headerText} opacity-60`}>{t('projects.no_tasks')}</p>
                </div>
              )}

              {/* Drop Zone */}
              {isDropTarget && (
                <div className="border-2 border-dashed border-[var(--border-strong)] rounded-lg p-3 bg-[var(--surface-subtle)] text-center">
                  <p className="text-sm font-medium text-[var(--text-secondary)]">{t('projects.drop_here')}</p>
                </div>
              )}
            </div>
          </div>
        );
      })}
    </div>
  );
};

export default KanbanBoard;
