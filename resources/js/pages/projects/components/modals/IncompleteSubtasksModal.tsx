import React, { useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import {IconAlertTriangle} from '@tabler/icons-react';

interface IncompleteSubtasksModalProps {
  isOpen: boolean;
  onClose: () => void;
  taskTitle: string;
  incompleteSubtasks: { id: number; title: string; status: string }[];
  targetStatus: string;
}

const statusColorMap: Record<string, string> = {
  todo: 'bg-[var(--border-strong)]',
  in_progress: 'bg-[var(--accent-default)]',
  in_review: 'bg-[var(--status-warning)]',
};

const IncompleteSubtasksModal: React.FC<IncompleteSubtasksModalProps> = ({
  isOpen,
  onClose,
  taskTitle,
  incompleteSubtasks,
  targetStatus,
}) => {
  const { t } = useTranslation();
  const titleId = React.useId();

  useEffect(() => {
    if (!isOpen) return;
    const handleKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose();
    };
    document.addEventListener('keydown', handleKey);
    return () => document.removeEventListener('keydown', handleKey);
  }, [isOpen, onClose]);

  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 z-[100] flex items-center justify-center animate-in fade-in duration-200">
      <div data-testid="modal-backdrop" className="absolute inset-0 bg-[var(--surface-overlay)]" onClick={onClose} />
      <div
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        className="relative bg-[var(--surface-base)] rounded-xl sm:rounded-2xl shadow-2xl max-w-md w-full mx-4 overflow-hidden animate-in zoom-in-95 duration-200"
      >
        {/* Header */}
        <div className="bg-[var(--status-danger-subtle)] px-6 py-4 border-b border-[var(--status-danger-subtle)]">
          <div className="flex items-center gap-3">
            <div className="p-2 bg-[var(--status-danger-subtle)] rounded-xl">
              <IconAlertTriangle className="h-6 w-6 text-[var(--status-danger)]" />
            </div>
            <div>
              <h3 id={titleId} className="text-base sm:text-lg font-bold text-[var(--text-primary)]">{t('common.warning')}</h3>
              <p className="text-sm text-[var(--status-danger)]">{t('projects.incomplete_subtasks_exist')}</p>
            </div>
          </div>
        </div>

        {/* Content */}
        <div className="px-6 py-5">
          <div className="p-3 bg-[var(--surface-subtle)] rounded-xl mb-4">
            <p className="text-sm text-[var(--text-tertiary)] mb-1">{t('projects.task_label')}:</p>
            <p className="font-medium text-[var(--text-primary)]">{taskTitle}</p>
          </div>

          <p className="text-[var(--text-secondary)] mb-4 leading-relaxed">
            {t('projects.cannot_move_task_to')} <span className="font-bold text-[var(--text-primary)]">{targetStatus}</span> {t('projects.because_incomplete_subtasks', { count: incompleteSubtasks.length })}
          </p>

          {/* قائمة المهام الفرعية غير المكتملة */}
          <div className="bg-[var(--surface-subtle)] rounded-xl p-3 max-h-40 overflow-y-auto">
            <p className="text-xs font-medium text-[var(--text-tertiary)] mb-2">{t('projects.remaining_subtasks')}:</p>
            <ul className="space-y-1">
              {incompleteSubtasks.slice(0, 5).map((st) => (
                <li key={st.id} className="flex items-center gap-2 text-sm text-[var(--text-secondary)]">
                  <span className={`w-2 h-2 rounded-full ${statusColorMap[st.status] || 'bg-[var(--border-strong)]'}`} />
                  <span className="truncate">{st.title}</span>
                </li>
              ))}
                {incompleteSubtasks.length > 5 && (
                <li className="text-xs text-[var(--text-tertiary)] pr-4">
                  {t('projects.and_more_tasks', { count: incompleteSubtasks.length - 5 })}
                </li>
              )}
            </ul>
          </div>
        </div>

        {/* Footer */}
        <div className="px-6 py-4 bg-[var(--surface-subtle)] border-t border-[var(--border-default)] flex justify-end">
          <button
            onClick={onClose}
            className="px-5 py-2 bg-[var(--status-danger)] text-[var(--text-inverse)] rounded-xl font-medium hover:opacity-90 transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--status-danger)] focus-visible:ring-offset-2"
          >
            {t('projects.understood')}
          </button>
        </div>
      </div>
    </div>
  );
};

export default IncompleteSubtasksModal;
