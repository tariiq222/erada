import React, { useState, useRef, useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { tasksApi } from '@entities/task';
import {IconCircle, IconCircleCheck, IconPlayerPause, IconCircleX, IconPlayerPlay, IconEye, IconChevronDown, IconLoader, IconInfoCircle, IconAlertTriangle} from '@tabler/icons-react';
import { TASK_STATUS_TOKENS } from '@shared/lib/statusTokens';
import { useToast } from '@shared/ui/Toast';

const StatusTransitionModal = React.lazy(() => import('@pages/projects/components/modals/StatusTransitionModal'));

interface Subtask {
  id: number;
  title: string;
  status: string;
}

interface TaskStatusChangerProps {
  taskId: number;
  currentStatus: string;
  onStatusChange?: (newStatus: string) => void;
  size?: 'sm' | 'md' | 'lg';
  showLabel?: boolean;
  disabled?: boolean;
  hasProject?: boolean; // هل المهمة مرتبطة بمشروع
  subtasks?: Subtask[]; // المهام الفرعية للتحقق منها
  isImprovement?: boolean; // مشروع تحسيني — يفرض توثيق PDCA
  taskTitle?: string; // عنوان المهمة للعرض في مودال التوثيق
}

// التسميات والأيقونات (ليست ألواناً) – تبقى هنا
const statusMeta = {
  todo: { label: 'للتنفيذ', icon: IconCircle },
  in_progress: { label: 'قيد التنفيذ', icon: IconPlayerPlay },
  in_review: { label: 'قيد المراجعة', icon: IconEye },
  completed: { label: 'مكتملة', icon: IconCircleCheck },
  on_hold: { label: 'معلقة', icon: IconPlayerPause },
  cancelled: { label: 'ملغاة', icon: IconCircleX },
} as const;

// دمج التسميات والأيقونات مع رموز الألوان من المصدر الموحّد
const statusConfig: Record<string, {
  label: string;
  icon: React.FC<{ className?: string }>;
  bg: string;
  text: string;
  border: string;
  hoverBg: string;
}> = {
  todo: { ...statusMeta.todo, ...TASK_STATUS_TOKENS.todo },
  in_progress: { ...statusMeta.in_progress, ...TASK_STATUS_TOKENS.in_progress },
  in_review: { ...statusMeta.in_review, ...TASK_STATUS_TOKENS.in_review },
  completed: { ...statusMeta.completed, ...TASK_STATUS_TOKENS.completed },
  on_hold: { ...statusMeta.on_hold, ...TASK_STATUS_TOKENS.on_hold },
  cancelled: { ...statusMeta.cancelled, ...TASK_STATUS_TOKENS.cancelled },
};

const statusOrder = ['todo', 'in_progress', 'in_review', 'completed'] as const;

// رسائل توضيحية لتغيير الحالة
const statusChangeNotes: Record<string, string> = {
  in_review: 'بعد الإرسال للمراجعة، سيتولى مدير المشروع التحكم في المهمة',
  completed: 'بعد اعتماد المهمة كمكتملة، سيتولى مكتب المشاريع التحكم فيها',
};

const sizeClasses = {
  sm: {
    button: 'px-2 py-1 text-xs gap-1',
    icon: 'h-3.5 w-3.5',
    dropdown: 'py-1',
    item: 'px-3 py-1 text-xs',
  },
  md: {
    button: 'px-3 py-2 text-sm gap-2',
    icon: 'h-4 w-4',
    dropdown: 'py-1',
    item: 'px-4 py-2 text-sm',
  },
  lg: {
    button: 'px-4 py-2 text-sm gap-2',
    icon: 'h-5 w-5',
    dropdown: 'py-2',
    item: 'px-5 py-2 text-sm',
  },
};

const TaskStatusChanger: React.FC<TaskStatusChangerProps> = ({
  taskId,
  currentStatus,
  onStatusChange,
  size = 'md',
  showLabel = true,
  disabled = false,
  hasProject = false,
  subtasks = [],
  isImprovement = false,
  taskTitle = '',
}) => {
  const { t } = useTranslation();
  const { showToast } = useToast();
  const [isOpen, setIsOpen] = useState(false);
  const [loading, setLoading] = useState(false);
  const [status, setStatus] = useState(currentStatus);
  const [showWarning, setShowWarning] = useState(false);
  const [pendingStatus, setPendingStatus] = useState<string | null>(null);
  const [transitionStatus, setTransitionStatus] = useState<string | null>(null);
  const dropdownRef = useRef<HTMLDivElement>(null);

  // حساب المهام الفرعية غير المكتملة
  const incompleteSubtasks = subtasks.filter(st => st.status !== 'completed');
  const hasIncompleteSubtasks = incompleteSubtasks.length > 0;

  useEffect(() => {
    setStatus(currentStatus);
  }, [currentStatus]);

  useEffect(() => {
    const handleClickOutside = (event: MouseEvent) => {
      if (dropdownRef.current && !dropdownRef.current.contains(event.target as Node)) {
        setIsOpen(false);
      }
    };
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  const handleStatusChange = async (newStatus: string) => {
    if (newStatus === status || loading) return;

    // التحقق من المهام الفرعية غير المكتملة قبل تغيير الحالة
    // الاستثناء: يُسمح بالنقل إلى "قيد التنفيذ" في أي حالة
    if (newStatus !== 'in_progress' && hasIncompleteSubtasks) {
      setPendingStatus(newStatus);
      setShowWarning(true);
      setIsOpen(false);
      return;
    }

    // مشاريع التحسين: توثيق PDCA إلزامي عند المراجعة/الإكمال — افتح المودال
    if (isImprovement && (newStatus === 'in_review' || newStatus === 'completed')) {
      setTransitionStatus(newStatus);
      setIsOpen(false);
      return;
    }

    await executeStatusChange(newStatus);
  };

  const executeStatusChange = async (newStatus: string, docs?: { status_comment?: string; lessons_learned?: string }) => {
    setLoading(true);
    setIsOpen(false);
    setShowWarning(false);
    setPendingStatus(null);

    try {
      if (docs) {
        await tasksApi.updateStatus(taskId, newStatus, docs);
      } else {
        await tasksApi.updateStatus(taskId, newStatus);
      }
      setStatus(newStatus);
      onStatusChange?.(newStatus);
      setTransitionStatus(null);
    } catch (error: any) {
      const message = error?.message || error?.response?.data?.message || t('tasks.status_update_failed');
      showToast('error', message);
    } finally {
      setLoading(false);
    }
  };

  const handleConfirmTransition = (comment: string, completionData?: { lessonsLearned?: string }) => {
    if (!transitionStatus) return;
    void executeStatusChange(transitionStatus, {
      ...(comment ? { status_comment: comment } : {}),
      ...(completionData?.lessonsLearned ? { lessons_learned: completionData.lessonsLearned } : {}),
    });
  };

  const handleCloseWarning = () => {
    setShowWarning(false);
    setPendingStatus(null);
  };

  const config = statusConfig[status] || statusConfig.todo;
  const StatusIcon = config.icon;
  const classes = sizeClasses[size];

  return (
    <div className="relative" ref={dropdownRef}>
      <button
        onClick={() => !disabled && setIsOpen(!isOpen)}
        disabled={disabled || loading}
        className={`
          inline-flex items-center justify-between rounded-lg border font-medium
          transition-colors duration-200
          focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--accent-default)] focus-visible:ring-offset-2 focus-visible:ring-offset-[var(--surface-base)]
          ${classes.button}
          ${config.bg} ${config.text} ${config.border}
          ${!disabled && !loading ? config.hoverBg + ' cursor-pointer' : 'cursor-not-allowed opacity-70'}
        `}
      >
        <span className="flex items-center gap-2">
          {loading ? (
            <IconLoader className={`${classes.icon} animate-spin`} />
          ) : (
            <StatusIcon className={classes.icon} />
          )}
          {showLabel && <span>{config.label}</span>}
        </span>
        {!disabled && (
          <IconChevronDown className={`${classes.icon} transition-transform ${isOpen ? 'rotate-180' : ''}`} />
        )}
      </button>

      {isOpen && !disabled && (
        <div className={`
          absolute top-full mt-2 right-0 z-50
          min-w-[220px] bg-[var(--surface-base)]
          rounded-xl shadow-xl border border-[var(--border-default)]
          overflow-hidden animate-in fade-in slide-in-from-top-2 duration-200
          ${classes.dropdown}
        `}>
          {statusOrder.map((s) => {
            const cfg = statusConfig[s];
            const Icon = cfg.icon;
            const isActive = s === status;
            const note = statusChangeNotes[s];
            // التحقق من وجود مهام فرعية غير مكتملة (للحالات غير in_progress)
            const isBlockedBySubtasks = s !== 'in_progress' && hasIncompleteSubtasks && !isActive;

            return (
              <div key={s}>
                <button
                  onClick={() => handleStatusChange(s)}
                  className={`
                    w-full flex items-center gap-3 transition-colors
                    focus-visible:outline-none focus-visible:bg-[var(--accent-subtle)]
                    ${classes.item}
                    ${isActive
                      ? `${cfg.bg} ${cfg.text} font-medium`
                      : 'text-[var(--text-secondary)] hover:bg-[var(--surface-muted)]'
                    }
                  `}
                >
                  <Icon className={`${classes.icon} ${isActive ? cfg.text : 'text-[var(--text-tertiary)]'}`} />
                  <span>{cfg.label}</span>
                  {isActive && (
                    <IconCircleCheck className={`${classes.icon} mr-auto`} />
                  )}
                  {isBlockedBySubtasks && (
                    <IconAlertTriangle className={`${classes.icon} mr-auto text-[var(--status-warning)]`} />
                  )}
                </button>
                {note && !isActive && hasProject && (
                  <div className="px-4 pb-2 -mt-1">
                    <div className="flex items-start gap-1 text-[11px] text-[var(--text-tertiary)] leading-tight">
                      <IconInfoCircle className="h-3 w-3 shrink-0 mt-0" />
                      <span>{note}</span>
                    </div>
                  </div>
                )}
              </div>
            );
          })}
        </div>
      )}

      {/* نافذة تحذير المهام الفرعية غير المكتملة */}
      {showWarning && pendingStatus && (
        <div className="fixed inset-0 z-[100] flex items-center justify-center bg-[var(--surface-overlay)] animate-in fade-in duration-200">
          <div className="bg-[var(--surface-base)] rounded-2xl shadow-2xl max-w-md w-full mx-4 overflow-hidden animate-in zoom-in-95 duration-200">
            {/* Header */}
            <div className="bg-[var(--status-danger-subtle)] px-6 py-4 border-b border-[var(--status-danger-subtle)]">
              <div className="flex items-center gap-3">
                <div className="p-2 bg-[var(--status-danger-subtle)] rounded-xl">
                  <IconAlertTriangle className="h-6 w-6 text-[var(--status-danger)]" />
                </div>
                <div>
                  <h3 className="font-bold text-[var(--text-primary)] text-lg">تنبيه</h3>
                  <p className="text-sm text-[var(--status-danger-text)]">توجد مهام فرعية غير مكتملة</p>
                </div>
              </div>
            </div>

            {/* Content */}
            <div className="px-6 py-5">
              <p className="text-[var(--text-secondary)] mb-4 leading-relaxed">
                لا يمكن تغيير حالة المهمة إلى <span className="font-bold text-[var(--text-primary)]">{statusConfig[pendingStatus]?.label}</span> لأنه يوجد{' '}
                <span className="font-bold text-[var(--status-danger)]">{incompleteSubtasks.length}</span> مهام فرعية غير مكتملة.
              </p>

              {/* قائمة المهام الفرعية غير المكتملة */}
              <div className="bg-[var(--surface-subtle)] rounded-xl p-3 max-h-40 overflow-y-auto">
                <p className="text-xs font-medium text-[var(--text-tertiary)] mb-2">المهام الفرعية المتبقية:</p>
                <ul className="space-y-1">
                  {incompleteSubtasks.slice(0, 5).map((st) => (
                    <li key={st.id} className="flex items-center gap-2 text-sm text-[var(--text-secondary)]">
                      <span className={`w-2 h-2 rounded-full ${
                        st.status === 'in_progress' ? 'bg-[var(--accent-default)]' :
                        st.status === 'in_review' ? 'bg-[var(--status-warning)]' :
                        'bg-[var(--surface-muted)]'
                      }`} />
                      <span className="truncate">{st.title}</span>
                    </li>
                  ))}
                  {incompleteSubtasks.length > 5 && (
                    <li className="text-xs text-[var(--text-tertiary)] pr-4">
                      و {incompleteSubtasks.length - 5} مهام أخرى...
                    </li>
                  )}
                </ul>
              </div>
            </div>

            {/* Footer */}
            <div className="px-6 py-4 bg-[var(--surface-subtle)] border-t border-[var(--border-default)] flex justify-end">
              <button
                onClick={handleCloseWarning}
                className="px-5 py-2 bg-[var(--status-danger)] text-[var(--text-inverse)] rounded-xl font-medium hover:bg-[var(--status-danger)] transition-colors"
              >
                فهمت
              </button>
            </div>
          </div>
        </div>
      )}

      {transitionStatus && (
        <React.Suspense fallback={null}>
          <StatusTransitionModal
            isOpen
            onClose={() => setTransitionStatus(null)}
            onConfirm={handleConfirmTransition}
            taskTitle={taskTitle}
            newStatus={transitionStatus}
            confirmationMessage={t('projects.confirm_status_change')}
            isCompleting={transitionStatus === 'completed'}
            isImprovement
          />
        </React.Suspense>
      )}
    </div>
  );
};

export default TaskStatusChanger;
