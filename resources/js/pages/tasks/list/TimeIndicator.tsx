import { memo } from 'react';
import { useTranslation } from 'react-i18next';
import type { TimeIndicator as TimeIndicatorType } from './types';
import { TIME_INDICATOR_TOKENS, type TimeIndicatorKey } from '@shared/lib/statusTokens';

// مصدر واحد للحقيقة: @shared/lib/statusTokens
const timeIndicatorColors: Record<TimeIndicatorKey, { bg: string; fill: string; text: string }> = TIME_INDICATOR_TOKENS;

interface TimeIndicatorProps {
  indicator: TimeIndicatorType | undefined;
  taskStatus: string;
}

const TimeIndicator = memo<TimeIndicatorProps>(({ indicator, taskStatus }) => {
  const { t } = useTranslation();
  if (!indicator || !indicator.has_due_date) {
    return null;
  }

  const colors = timeIndicatorColors[indicator.status as TimeIndicatorKey] || timeIndicatorColors.normal;
  const progress = indicator.time_progress ?? 0;
  const daysRemaining = indicator.days_remaining;
  const hasProgress = indicator.total_days !== null;

  // نص الأيام المتبقية
  let daysText = '';
  if (taskStatus === 'completed') {
    daysText = t('status.completed');
  } else if (daysRemaining === null) {
    daysText = '-';
  } else if (daysRemaining < 0) {
    daysText = t('tasks.overdue_days', { count: Math.abs(daysRemaining) });
  } else if (daysRemaining === 0) {
    daysText = t('tasks.today');
  } else if (daysRemaining === 1) {
    daysText = t('tasks.tomorrow');
  } else {
    daysText = t('tasks.days_remaining', { count: daysRemaining });
  }

  return (
    <div className="space-y-1">
      <span className="text-[length:var(--text-caption)] text-[var(--text-tertiary)] font-medium">{t('tasks.time_remaining')}</span>
      <div className="flex items-center gap-2">
        {hasProgress ? (
          <div data-testid="time-indicator-track" className={`flex-1 h-1.5 ${colors.bg} rounded-full overflow-hidden min-w-[60px]`}>
            <div
              data-testid="time-indicator-fill"
              className={`h-full ${colors.fill} rounded-full transition-[width]`}
              style={{ width: `${Math.min(progress, 100)}%` }}
            />
          </div>
        ) : (
          <div className="flex-1" />
        )}
        <span className={`text-xs font-medium whitespace-nowrap ${colors.text}`}>
          {daysText}
        </span>
      </div>
    </div>
  );
});

TimeIndicator.displayName = 'TimeIndicator';

export default TimeIndicator;
