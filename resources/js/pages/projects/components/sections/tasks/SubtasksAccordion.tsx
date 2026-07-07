import React from 'react';
import { useTranslation } from 'react-i18next';
import {IconCircle, IconChevronDown, IconCircleCheck} from '@tabler/icons-react';
import { taskStatusLabels, taskStatusIcons } from '../../../constants';
import type { SubTask } from '../../../types';

export interface SubtasksAccordionProps {
  taskId: number;
  subtasks: SubTask[];
  openSubtaskStatusMenu: number | null;
  onUpdateSubtaskStatus: (e: React.MouseEvent, parentId: number, subtaskId: number, newStatus: string) => void;
  onOpenSubtaskStatusMenu: (subtaskId: number | null) => void;
}

const statusColorMap: Record<string, { border: string; bg: string; text: string; iconColor: string }> = {
  todo: { border: 'border-[var(--border-strong)]', bg: 'bg-[var(--surface-subtle)]', text: 'text-[var(--text-secondary)]', iconColor: 'text-[var(--text-tertiary)]' },
  in_progress: { border: 'border-[var(--accent-default)]', bg: 'bg-[var(--accent-subtle)]', text: 'text-[var(--accent-default)]', iconColor: 'text-[var(--accent-default)]' },
  in_review: { border: 'border-[var(--status-warning)]', bg: 'bg-[var(--status-warning-subtle)]', text: 'text-[var(--status-warning-text)]', iconColor: 'text-[var(--status-warning)]' },
  completed: { border: 'border-[var(--status-success)]', bg: 'bg-[var(--status-success-subtle)]', text: 'text-[var(--status-success-text)]', iconColor: 'text-[var(--status-success)]' },
};

const SubtasksAccordion: React.FC<SubtasksAccordionProps> = ({
  taskId,
  subtasks,
  openSubtaskStatusMenu,
  onUpdateSubtaskStatus,
  onOpenSubtaskStatusMenu,
}) => {
  const { t } = useTranslation();
  return (
    <div className="mt-2 pt-2 border-t border-[var(--border-default)]">
      <div className="flex items-center justify-between mb-2">
        <span className="text-xs font-semibold text-[var(--text-secondary)]">
          {t('projects.subtasks')} ({subtasks.length})
        </span>
        <span className="text-[length:var(--text-caption)] text-[var(--status-success-text)]">
          {subtasks.filter(s => s.status === 'completed').length} {t('projects.completed_label')}
        </span>
      </div>
      <div className="space-y-1">
        {subtasks.map((subtask) => {
          const SubtaskIcon = taskStatusIcons[subtask.status] || IconCircle;
          const currentStatusColor = statusColorMap[subtask.status] || statusColorMap.todo;
          return (
            <div
              key={subtask.id}
              className="bg-[var(--surface-subtle)] rounded-lg p-2 hover:bg-[var(--surface-muted)] transition-colors"
            >
              <div className="flex items-center gap-2">
                <span className="flex-1 text-xs text-[var(--text-secondary)] line-clamp-1">
                  {subtask.title}
                </span>
                <div className="relative">
                  <button
                    data-testid="subtask-status-button"
                    data-subtask-status={subtask.status}
                    onClick={(e) => {
                      e.stopPropagation();
                      onOpenSubtaskStatusMenu(openSubtaskStatusMenu === subtask.id ? null : subtask.id);
                    }}
                    className={`
                      flex items-center gap-1 px-2 py-1 rounded-full text-[11px] font-medium
                      border ${currentStatusColor.border} ${currentStatusColor.bg} ${currentStatusColor.text}
                      cursor-pointer transition-shadow hover:shadow-sm
                    `}
                  >
                    <SubtaskIcon className={`h-3 w-3 ${currentStatusColor.iconColor}`} />
                    <span>{taskStatusLabels[subtask.status]}</span>
                    <IconChevronDown className={`h-3 w-3 opacity-60 transition-transform ${openSubtaskStatusMenu === subtask.id ? 'rotate-180' : ''}`} />
                  </button>
                  {openSubtaskStatusMenu === subtask.id && (
                    <div className="absolute left-0 top-full mt-1 z-50 min-w-[140px] bg-[var(--surface-base)] rounded-xl shadow-lg border border-[var(--border-default)] py-1">
                      <div className="px-2 py-1 text-[length:var(--text-caption)] text-[var(--text-tertiary)] font-medium">{t('projects.change_status')}</div>
                      {(['todo', 'in_progress', 'in_review', 'completed'] as const).map((st) => {
                        const StatusIcon = taskStatusIcons[st] || IconCircle;
                        const optionColors = statusColorMap[st];
                        const isSelected = subtask.status === st;
                        return (
                          <button
                            key={st}
                            onClick={(e) => {
                              e.stopPropagation();
                              if (st !== subtask.status) {
                                onUpdateSubtaskStatus(e, taskId, subtask.id, st);
                              }
                              onOpenSubtaskStatusMenu(null);
                            }}
                            className={`w-full flex items-center gap-2 px-3 py-1 text-xs hover:bg-[var(--surface-subtle)] transition-colors ${isSelected ? 'bg-[var(--surface-subtle)]' : ''}`}
                          >
                            <StatusIcon className={`h-4 w-4 ${optionColors.iconColor}`} />
                            <span className={`flex-1 text-right ${optionColors.text}`}>{taskStatusLabels[st]}</span>
                            {isSelected && (
                              <IconCircleCheck className="h-3.5 w-3.5 text-[var(--accent-default)]" />
                            )}
                          </button>
                        );
                      })}
                    </div>
                  )}
                </div>
              </div>
              {subtask.assignee && (
                <div className="flex items-center gap-1 mt-1 mr-1">
                  <div className="h-4 w-4 rounded-full bg-[var(--surface-muted)] flex items-center justify-center">
                    <span className="text-[var(--text-secondary)] text-[length:var(--text-caption)] font-bold">{subtask.assignee.name.charAt(0)}</span>
                  </div>
                  <span className="text-[length:var(--text-caption)] text-[var(--text-tertiary)]">{subtask.assignee.name}</span>
                </div>
              )}
            </div>
          );
        })}
      </div>
    </div>
  );
};

export default SubtasksAccordion;
