import React from 'react';
import {IconClock, IconAlertTriangle, IconCircleCheck, IconClockHour4, IconTrendingUp, IconFlame} from '@tabler/icons-react';

interface TimeIndicatorData {
  days_remaining: number | null;
  days_elapsed: number | null;
  total_days: number | null;
  time_progress: number | null;
  status: 'normal' | 'warning' | 'urgent' | 'overdue' | 'completed';
  has_due_date: boolean;
}

interface TaskTimeIndicatorProps {
  indicator: TimeIndicatorData;
  taskStatus: string;
  variant?: 'compact' | 'standard' | 'detailed';
  showProgress?: boolean;
  className?: string;
}

const statusStyles: Record<string, {
  bg: string;
  fill: string;
  text: string;
  icon: React.FC<{ className?: string }>;
  ringColor: string;
}> = {
  normal: {
    bg: 'bg-[var(--surface-muted)]',
    fill: 'bg-[var(--accent-default)]',
    text: 'text-[var(--text-secondary)]',
    icon: IconClock,
    ringColor: 'ring-[var(--border-default)]',
  },
  warning: {
    bg: 'bg-[var(--status-warning-subtle)]',
    fill: 'bg-[var(--status-warning)]',
    text: 'text-[var(--status-warning)]',
    icon: IconClockHour4,
    ringColor: 'ring-[var(--status-warning)]',
  },
  urgent: {
    bg: 'bg-[var(--status-warning-subtle)]',
    fill: 'bg-[var(--status-warning)]',
    text: 'text-[var(--status-warning)]',
    icon: IconFlame,
    ringColor: 'ring-[var(--status-warning)]',
  },
  overdue: {
    bg: 'bg-[var(--status-danger-subtle)]',
    fill: 'bg-[var(--status-danger)]',
    text: 'text-[var(--status-danger)]',
    icon: IconAlertTriangle,
    ringColor: 'ring-[var(--status-danger)]',
  },
  completed: {
    bg: 'bg-[var(--status-success-subtle)]',
    fill: 'bg-[var(--status-success)]',
    text: 'text-[var(--status-success)]',
    icon: IconCircleCheck,
    ringColor: 'ring-[var(--status-success)]',
  },
};

const TaskTimeIndicator: React.FC<TaskTimeIndicatorProps> = ({
  indicator,
  taskStatus,
  variant = 'standard',
  showProgress = true,
  className = '',
}) => {
  if (!indicator || !indicator.has_due_date) {
    return null;
  }

  const style = statusStyles[indicator.status] || statusStyles.normal;
  const Icon = style.icon;
  const progress = Math.min(indicator.time_progress ?? 0, 100);
  const daysRemaining = indicator.days_remaining;
  const daysElapsed = indicator.days_elapsed ?? 0;
  const totalDays = indicator.total_days ?? 0;

  // تحديد نص الأيام المتبقية
  const getDaysText = () => {
    if (taskStatus === 'completed') return 'مكتملة';
    if (daysRemaining === null) return '-';
    if (daysRemaining < 0) return `متأخر ${Math.abs(daysRemaining)} يوم`;
    if (daysRemaining === 0) return 'اليوم';
    if (daysRemaining === 1) return 'غداً';
    if (daysRemaining <= 7) return `${daysRemaining} أيام`;
    return `${daysRemaining} يوم`;
  };

  // عرض مختصر
  if (variant === 'compact') {
    return (
      <div className={`space-y-1 ${className}`}>
        <span className="text-[11px] text-[var(--text-tertiary)] font-medium">المدة المتبقية للاستحقاق</span>
        <div className="flex items-center gap-2">
          {totalDays > 0 && showProgress && (
            <div className={`flex-1 h-1.5 ${style.bg} rounded-full overflow-hidden min-w-[40px]`}>
              <div
                className={`h-full ${style.fill} rounded-full transition-[width] duration-500`}
                style={{ width: `${progress}%` }}
              />
            </div>
          )}
          <span className={`text-xs font-medium whitespace-nowrap ${style.text}`}>
            {getDaysText()}
          </span>
        </div>
      </div>
    );
  }

  // عرض قياسي
  if (variant === 'standard') {
    return (
      <div className={`${style.bg} rounded-xl p-3 ${className}`}>
        <div className="flex items-center justify-between mb-2">
          <div className={`flex items-center gap-2 ${style.text}`}>
            <Icon className="h-4 w-4" />
            <span className="text-xs font-medium">الوقت المتبقي</span>
          </div>
          <span className={`text-sm font-bold ${style.text}`}>
            {getDaysText()}
          </span>
        </div>
        {totalDays > 0 && showProgress && (
          <div data-testid="task-progress-track" className={`h-2 bg-[var(--surface-overlay)] rounded-full overflow-hidden`}>
            <div
              className={`h-full ${style.fill} rounded-full transition-[width] duration-500`}
              style={{ width: `${progress}%` }}
            />
          </div>
        )}
      </div>
    );
  }

  // عرض تفصيلي
  return (
    <div className={`${style.bg} rounded-2xl p-4 ring-1 ${style.ringColor} ${className}`}>
      {/* Header */}
      <div className="flex items-center justify-between mb-4">
        <div className={`flex items-center gap-2 ${style.text}`}>
          <div className={`p-2 rounded-xl ${style.bg} ring-2 ${style.ringColor}`}>
            <Icon className="h-5 w-5" />
          </div>
          <div>
            <p className="text-xs opacity-70">المؤشر الزمني</p>
            <p className="text-sm font-bold">{getDaysText()}</p>
          </div>
        </div>

        {/* Circular Progress */}
        {totalDays > 0 && (
          <div className="relative h-14 w-14">
            <svg className="h-14 w-14 -rotate-90" viewBox="0 0 56 56">
              <circle
                cx="28"
                cy="28"
                r="24"
                fill="none"
                stroke="currentColor"
                strokeWidth="4"
                className="text-[var(--text-tertiary)]"
              />
              <circle
                cx="28"
                cy="28"
                r="24"
                fill="none"
                stroke="currentColor"
                strokeWidth="4"
                strokeLinecap="round"
                strokeDasharray={`${progress * 1.5} 150`}
                className={style.text}
              />
            </svg>
            <div className="absolute inset-0 flex items-center justify-center">
              <span className={`text-xs font-bold ${style.text}`}>
                {Math.round(progress)}%
              </span>
            </div>
          </div>
        )}
      </div>

      {/* Progress Bar */}
      {totalDays > 0 && showProgress && (
        <>
          <div data-testid="task-progress-track" className="h-3 bg-[var(--surface-overlay)] rounded-full overflow-hidden mb-2">
            <div
              className={`h-full ${style.fill} rounded-full transition-[width] duration-500`}
              style={{ width: `${progress}%` }}
            />
          </div>

          {/* Stats */}
          <div className="flex justify-between text-xs">
            <span className={`${style.text} opacity-70`}>
              مضى {daysElapsed} يوم
            </span>
            <span className={`${style.text} opacity-70`}>
              من أصل {totalDays} يوم
            </span>
          </div>
        </>
      )}

      {/* Status Badge */}
      {indicator.status !== 'normal' && indicator.status !== 'completed' && (
        <div className={`mt-3 flex items-center gap-2 px-3 py-1 rounded-lg ${style.bg} ring-1 ${style.ringColor}`}>
          <IconTrendingUp className={`h-3.5 w-3.5 ${style.text}`} />
          <span className={`text-xs font-medium ${style.text}`}>
            {indicator.status === 'warning' && 'تحتاج إلى متابعة'}
            {indicator.status === 'urgent' && 'تحتاج إلى إجراء عاجل'}
            {indicator.status === 'overdue' && 'تجاوزت الموعد المحدد'}
          </span>
        </div>
      )}
    </div>
  );
};

export default TaskTimeIndicator;
