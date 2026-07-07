import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { tasksApi } from '@entities/task';
import {IconEdit, IconExternalLink, IconCopy, IconTrash, IconDots, IconLoader} from '@tabler/icons-react';
import { IconButton } from '@shared/ui/IconButton';

interface TaskQuickActionsProps {
  taskId: number;
  onDelete?: () => void;
  showDelete?: boolean;
  showFullPageLink?: boolean;
  variant?: 'buttons' | 'dropdown';
}

const TaskQuickActions: React.FC<TaskQuickActionsProps> = ({
  taskId,
  onDelete,
  showDelete = false,
  showFullPageLink = true,
  variant = 'buttons',
}) => {
  const [isOpen, setIsOpen] = useState(false);
  const [deleting, setDeleting] = useState(false);

  const handleDelete = async () => {
    if (!confirm('هل أنت متأكد من حذف هذه المهمة؟')) return;

    setDeleting(true);
    try {
      await tasksApi.delete(taskId);
      onDelete?.();
    } catch (error) {
      console.error('Failed to delete task:', error);
    } finally {
      setDeleting(false);
    }
  };

  const handleCopyLink = () => {
    const url = `${window.location.origin}/tasks/${taskId}`;
    navigator.clipboard.writeText(url);
    setIsOpen(false);
  };

  if (variant === 'dropdown') {
    return (
      <div className="relative">
        <button
          onClick={() => setIsOpen(!isOpen)}
          className="p-2 rounded-lg text-[var(--text-tertiary)] hover:text-[var(--text-secondary)] hover:bg-[var(--surface-muted)] transition-colors"
        >
          <IconDots className="h-5 w-5" />
        </button>

        {isOpen && (
          <>
            <div className="fixed inset-0 z-40" onClick={() => setIsOpen(false)} />
            <div className="absolute left-0 top-full mt-2 z-50 w-48 bg-[var(--surface-base)] rounded-xl shadow-xl border border-[var(--border-default)] py-1 animate-in fade-in slide-in-from-top-2">
              <Link
                to={`/tasks/${taskId}/edit`}
                onClick={() => setIsOpen(false)}
                className="flex items-center gap-3 px-4 py-2 text-sm text-[var(--text-secondary)] hover:bg-[var(--surface-muted)]"
              >
                <IconEdit className="h-4 w-4" />
                تعديل المهمة
              </Link>

              {showFullPageLink && (
                <Link
                  to={`/tasks/${taskId}`}
                  onClick={() => setIsOpen(false)}
                  className="flex items-center gap-3 px-4 py-2 text-sm text-[var(--text-secondary)] hover:bg-[var(--surface-muted)]"
                >
                  <IconExternalLink className="h-4 w-4" />
                  فتح في صفحة كاملة
                </Link>
              )}

              <button
                onClick={handleCopyLink}
                className="w-full flex items-center gap-3 px-4 py-2 text-sm text-[var(--text-secondary)] hover:bg-[var(--surface-muted)]"
              >
                <IconCopy className="h-4 w-4" />
                نسخ الرابط
              </button>

              {showDelete && (
                <>
                  <div className="my-1 border-t border-[var(--border-default)]" />
                  <button
                    onClick={handleDelete}
                    disabled={deleting}
                    className="w-full flex items-center gap-3 px-4 py-2 text-sm text-[var(--status-danger)] hover:bg-[var(--status-danger-subtle)]"
                  >
                    {deleting ? (
                      <IconLoader className="h-4 w-4 animate-spin" />
                    ) : (
                      <IconTrash className="h-4 w-4" />
                    )}
                    حذف المهمة
        </button>
                </>
              )}
            </div>
          </>
        )}
      </div>
    );
  }

  // Buttons variant
  return (
    <div className="flex items-center gap-1">
      <Link
        to={`/tasks/${taskId}/edit`}
        className="p-2 rounded-lg text-[var(--text-tertiary)] hover:text-[var(--status-warning)] hover:bg-[var(--status-warning-subtle)] transition-colors"
        title="تعديل"
      >
        <IconEdit className="h-4 w-4" />
      </Link>

      {showFullPageLink && (
        <Link
          to={`/tasks/${taskId}`}
          className="p-2 rounded-lg text-[var(--text-tertiary)] hover:text-[var(--accent-default)] hover:bg-[var(--accent-subtle)] transition-colors"
          title="فتح في صفحة كاملة"
        >
          <IconExternalLink className="h-4 w-4" />
        </Link>
      )}

      {showDelete && (
        <IconButton
          variant="danger"
          onClick={handleDelete}
          disabled={deleting}
          title="حذف"
        >
          {deleting ? (
            <IconLoader className="h-4 w-4 animate-spin" />
          ) : (
            <IconTrash className="h-4 w-4" />
          )}
        </IconButton>
      )}
    </div>
  );
};

export default TaskQuickActions;
