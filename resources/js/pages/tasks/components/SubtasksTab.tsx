import React from 'react';
import { useTranslation } from 'react-i18next';
import { Button, Select, EmptyState } from '@shared/ui';
import {IconListTree} from '@tabler/icons-react';
import { SubtaskCard } from './index';
import type { SubtaskDetails, UserOption } from './types';

interface SubtasksTabProps {
  subtasks: SubtaskDetails[];
  users: UserOption[];
  showForm: boolean;
  subtaskTitle: string;
  subtaskAssignee: string;
  isAdding: boolean;
  onShowForm: () => void;
  onHideForm: () => void;
  onTitleChange: (value: string) => void;
  onAssigneeChange: (value: string) => void;
  onAdd: () => void;
  onStatusChange: (subtaskId: number, newStatus: string) => void;
  onUpdate: (subtaskId: number, data: { title?: string; assignee?: number | null; priority?: string }) => void;
  onDelete: (subtaskId: number) => void;
}

const SubtasksTab: React.FC<SubtasksTabProps> = ({
  subtasks,
  users,
  showForm,
  subtaskTitle,
  subtaskAssignee,
  isAdding,
  onShowForm,
  onHideForm,
  onTitleChange,
  onAssigneeChange,
  onAdd,
  onStatusChange,
  onUpdate,
  onDelete,
}) => {
  const { t } = useTranslation();
  return (
    <div className="space-y-4">
      {/* Add Subtask Button/Form */}
      {!showForm ? (
        <Button
          onClick={onShowForm}
          variant="outline"
          size="sm"
          leftIcon={<IconListTree className="h-4 w-4" />}
          className="w-full border-dashed"
        >
          {t('tasks.add_subtask')}
        </Button>
      ) : (
        <div className="bg-[var(--surface-subtle)] rounded-xl p-4 space-y-3">
          <input
            type="text"
            value={subtaskTitle}
            onChange={(e) => onTitleChange(e.target.value)}
            placeholder={t('tasks.subtask_title_placeholder')}
            className="w-full px-4 py-2 border border-[var(--border-default)] rounded-lg focus:ring-2 focus:ring-[var(--accent-subtle)] focus:border-[var(--accent-default)] text-sm bg-[var(--surface-base)] text-[var(--text-primary)]"
            dir="auto"
            autoFocus
            onKeyDown={(e) => {
              if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                onAdd();
              }
              if (e.key === 'Escape') {
                onHideForm();
                onTitleChange('');
                onAssigneeChange('');
              }
            }}
          />
          <Select
            value={subtaskAssignee}
            onChange={(e) => onAssigneeChange(e.target.value)}
            placeholder={t('tasks.select_assignee')}
            options={users.map((user) => ({
              value: String(user.id),
              label: user.name,
            }))}
          />
          <div className="flex items-center gap-2 justify-end">
            <Button
              onClick={() => {
                onHideForm();
                onTitleChange('');
                onAssigneeChange('');
              }}
              variant="ghost"
              size="sm"
            >
              {t('common.cancel')}
            </Button>
            <Button
              onClick={onAdd}
              disabled={!subtaskTitle.trim() || isAdding}
              size="sm"
            >
              {isAdding ? t('tasks.adding') : t('common.add')}
            </Button>
          </div>
        </div>
      )}

      {/* Subtasks List */}
      {subtasks && subtasks.length > 0 ? (
        <div className="space-y-3">
          {subtasks.map((subtask) => (
            <SubtaskCard
              key={subtask.id}
              subtask={subtask}
              users={users}
              onStatusChange={onStatusChange}
              onUpdate={onUpdate}
              onDelete={onDelete}
            />
          ))}
        </div>
      ) : !showForm && (
        <EmptyState
          icon={IconListTree}
          title={t('tasks.no_subtasks')}
          description={t('tasks.add_subtasks_hint')}
          size="md"
        />
      )}
    </div>
  );
};

export default SubtasksTab;
