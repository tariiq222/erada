import { useState, useRef, useEffect, memo } from 'react';
import { useTranslation } from 'react-i18next';
import { Button, Select } from '@shared/ui';
import {IconEdit, IconTrash, IconFlag, IconUser, IconCalendar, IconDotsVertical, IconDeviceFloppy, IconCircleCheck, IconCircle} from '@tabler/icons-react';
import {
  statusIcons,
  statusColors,
  statusLabels,
  priorityLabels,
  priorityColors,
} from './constants';
import type { SubtaskDetails, UserOption } from './types';

export interface SubtaskCardProps {
  subtask: SubtaskDetails;
  users: UserOption[];
  onStatusChange: (subtaskId: number, newStatus: string) => void | Promise<void>;
  onUpdate: (subtaskId: number, data: { title?: string; assignee?: number | null; priority?: string }) => void | Promise<void>;
  onDelete: (subtaskId: number) => void | Promise<void>;
}

const SubtaskCard = memo<SubtaskCardProps>(({
  subtask,
  users,
  onStatusChange,
  onUpdate,
  onDelete,
}) => {
  const { t } = useTranslation();
  const [isEditing, setIsEditing] = useState(false);
  const [editTitle, setEditTitle] = useState(subtask.title);
  const [editAssignee, setEditAssignee] = useState<string>(subtask.assignee?.id?.toString() || '');
  const [editPriority, setEditPriority] = useState(subtask.priority || 'medium');
  const [isUpdating, setIsUpdating] = useState(false);
  const [showStatusMenu, setShowStatusMenu] = useState(false);
  const [showActions, setShowActions] = useState(false);
  const statusMenuRef = useRef<HTMLDivElement>(null);
  const actionsMenuRef = useRef<HTMLDivElement>(null);

  // Close menus when clicking outside
  useEffect(() => {
    const handleClickOutside = (event: MouseEvent) => {
      if (statusMenuRef.current && !statusMenuRef.current.contains(event.target as Node)) {
        setShowStatusMenu(false);
      }
      if (actionsMenuRef.current && !actionsMenuRef.current.contains(event.target as Node)) {
        setShowActions(false);
      }
    };
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  const handleStatusChange = async (newStatus: string) => {
    setIsUpdating(true);
    try {
      await onStatusChange(subtask.id, newStatus);
      setShowStatusMenu(false);
    } finally {
      setIsUpdating(false);
    }
  };

  const handleSave = async () => {
    if (!editTitle.trim()) return;
    setIsUpdating(true);
    try {
      await onUpdate(subtask.id, {
        title: editTitle.trim(),
        assignee: editAssignee ? Number(editAssignee) : null,
        priority: editPriority,
      });
      setIsEditing(false);
    } finally {
      setIsUpdating(false);
    }
  };

  const handleDelete = async () => {
    if (!confirm(t('tasks.confirm_delete_subtask'))) return;
    setIsUpdating(true);
    try {
      await onDelete(subtask.id);
    } finally {
      setIsUpdating(false);
    }
  };

  const handleCancel = () => {
    setEditTitle(subtask.title);
    setEditAssignee(subtask.assignee?.id?.toString() || '');
    setEditPriority(subtask.priority || 'medium');
    setIsEditing(false);
  };

  const StatusIcon = statusIcons[subtask.status] || IconCircle;
  // الحالات المتاحة للمهام الفرعية
  const availableStatuses = ['todo', 'in_progress', 'in_review', 'completed'];

  if (isEditing) {
    return (
      <div className="bg-[var(--surface-base)] rounded-xl border-2 border-[var(--accent-subtle)] p-4 shadow-lg space-y-3">
        <input
          type="text"
          value={editTitle}
          onChange={(e) => setEditTitle(e.target.value)}
          className="w-full px-3 py-2 border border-[var(--border-default)] rounded-lg focus:ring-2 focus:ring-[var(--accent-subtle)] focus:border-[var(--accent-default)] text-sm bg-[var(--surface-base)] text-[var(--text-primary)]"
          dir="auto"
          autoFocus
        />
        <div className="grid grid-cols-2 gap-3">
          <div>
            <label className="block text-xs text-[var(--text-tertiary)] mb-1">{t('tasks.assignee')}</label>
            <Select
              value={editAssignee}
              onChange={(e) => setEditAssignee(e.target.value)}
              placeholder={t('common.not_specified')}
              options={users.map((user) => ({
                value: String(user.id),
                label: user.name,
              }))}
            />
          </div>
          <div>
            <label className="block text-xs text-[var(--text-tertiary)] mb-1">{t('common.priority')}</label>
            <Select
              value={editPriority}
              onChange={(e) => setEditPriority(e.target.value)}
              options={[
                { value: 'low', label: t(priorityLabels.low) },
                { value: 'medium', label: t(priorityLabels.medium) },
                { value: 'high', label: t(priorityLabels.high) },
                { value: 'urgent', label: t(priorityLabels.urgent) },
              ]}
            />
          </div>
        </div>
        <div className="flex items-center gap-2 justify-end pt-2 border-t border-[var(--border-default)]">
          <Button
            onClick={handleCancel}
            variant="ghost"
            size="sm"
            disabled={isUpdating}
          >
            {t('common.cancel')}
          </Button>
          <Button
            onClick={handleSave}
            size="sm"
            disabled={!editTitle.trim() || isUpdating}
            leftIcon={<IconDeviceFloppy className="h-3.5 w-3.5" />}
          >
            {isUpdating ? t('common.saving') : t('common.save')}
          </Button>
        </div>
      </div>
    );
  }

  return (
    <div className={`group bg-[var(--surface-base)] rounded-xl border border-[var(--border-default)] hover:border-[var(--accent-subtle)] hover:shadow-md transition-[border-color,box-shadow] ${isUpdating ? 'opacity-60' : ''}`}>
      {/* Card Content */}
      <div className="p-4">
        {/* Header Row - Actions (left) | Title (center) | Status (right) */}
        <div className="flex items-center gap-3 mb-3">
          {/* Actions Menu - Left */}
          <div className="relative" ref={actionsMenuRef}>
            <button
              onClick={() => setShowActions(!showActions)}
              aria-label={t('common.actions')}
              className="p-1 rounded-lg text-[var(--text-tertiary)] hover:text-[var(--text-secondary)] hover:bg-[var(--surface-muted)] transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--accent-default)]"
            >
              <IconDotsVertical className="h-4 w-4" />
            </button>

            {showActions && (
              <div className="absolute bottom-full mb-1 left-0 bg-[var(--surface-base)] rounded-xl shadow-xl border border-[var(--border-default)] py-1 z-[9999] min-w-[140px]">
                <button
                  onClick={() => {
                    setShowActions(false);
                    setIsEditing(true);
                  }}
                  className="w-full px-3 py-2 text-start flex items-center gap-2 hover:bg-[var(--surface-subtle)] text-[var(--text-primary)] text-sm"
                >
                  <IconEdit className="h-3.5 w-3.5" />
                  {t('common.edit')}
                </button>
                <button
                  onClick={() => {
                    setShowActions(false);
                    handleDelete();
                  }}
                  className="w-full px-3 py-2 text-start flex items-center gap-2 hover:bg-[var(--status-danger-subtle)] text-[var(--status-danger)] text-sm"
                >
                  <IconTrash className="h-3.5 w-3.5" />
                  {t('common.delete')}
                </button>
              </div>
            )}
          </div>

          {/* Title - Center */}
          <h4 className={`font-medium text-[var(--text-primary)] text-sm flex-1 text-start ${subtask.status === 'completed' ? 'line-through text-[var(--text-tertiary)]' : ''}`}>
            {subtask.title}
          </h4>

          {/* Status Dropdown - Right */}
          <div className="relative" ref={statusMenuRef}>
            <button
              onClick={() => setShowStatusMenu(!showStatusMenu)}
              disabled={isUpdating}
              className={`flex items-center gap-2 px-3 py-1 rounded-lg transition-colors border ${statusColors[subtask.status]} hover:ring-2 hover:ring-offset-1 hover:ring-[var(--accent-subtle)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-1 focus-visible:ring-[var(--accent-default)]`}
            >
              <StatusIcon className="h-4 w-4" />
              <span className="text-xs font-medium whitespace-nowrap">{t(statusLabels[subtask.status]) || t('common.not_specified')}</span>
              <svg className="h-3.5 w-3.5 opacity-60" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
              </svg>
            </button>

            {/* Status Dropdown - يظهر للأعلى لتجنب القص بسبب overflow */}
            {showStatusMenu && (
              <div className="absolute bottom-full mb-1 right-0 bg-[var(--surface-base)] rounded-xl shadow-xl border border-[var(--border-default)] py-1 z-[9999] min-w-[160px]">
                <div className="px-3 py-1 text-xs font-medium text-[var(--text-tertiary)] border-b border-[var(--border-default)]">
                  {t('tasks.change_status')}
                </div>
                {availableStatuses.map((status) => {
                  const Icon = statusIcons[status];
                  const isActive = subtask.status === status;
                  return (
                    <button
                      key={status}
                      onClick={() => handleStatusChange(status)}
                      disabled={isActive}
                      className={`w-full px-3 py-2 text-start flex items-center gap-2 transition-colors ${
                        isActive
                          ? 'bg-[var(--accent-subtle)] text-[var(--accent-default)]'
                          : 'hover:bg-[var(--surface-subtle)] text-[var(--text-primary)]'
                      }`}
                    >
                      <div className={`p-1 rounded-lg ${statusColors[status]}`}>
                        <Icon className="h-3.5 w-3.5" />
                      </div>
                      <span className="text-sm font-medium">{t(statusLabels[status])}</span>
                      {isActive && <IconCircleCheck className="h-4 w-4 me-auto text-[var(--accent-default)]" />}
                    </button>
                  );
                })}
              </div>
            )}
          </div>
        </div>

        {/* Meta Info */}
        <div className="flex flex-wrap items-center gap-2 justify-end">
          {/* Priority */}
          {subtask.priority && (
            <span className={`inline-flex items-center gap-1 px-2 py-1 rounded-md text-xs border ${priorityColors[subtask.priority]}`}>
              <IconFlag className="h-3 w-3" />
              {t(priorityLabels[subtask.priority])}
            </span>
          )}

          {/* Assignee */}
          {subtask.assignee && (
            <span className="inline-flex items-center gap-1 px-2 py-1 rounded-md text-xs bg-[var(--surface-muted)] text-[var(--text-secondary)]">
              <IconUser className="h-3 w-3" />
              {subtask.assignee.name}
            </span>
          )}

          {/* Due Date */}
          {subtask.due_date && (
            <span className="inline-flex items-center gap-1 px-2 py-1 rounded-md text-xs bg-[var(--surface-muted)] text-[var(--text-secondary)]">
              <IconCalendar className="h-3 w-3" />
              {new Date(subtask.due_date).toLocaleDateString('ar-EG-u-nu-latn')}
            </span>
          )}
        </div>
      </div>
    </div>
  );
});

SubtaskCard.displayName = 'SubtaskCard';

export default SubtaskCard;
