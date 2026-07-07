import i18next from 'i18next';
import { timeIndicatorColors } from '../../../constants';
import type { TaskType } from '../../../types';

/**
 * دالة عرض المؤشر الزمني للمهمة
 */
export const renderTimeIndicator = (indicator: TaskType['time_indicator'] | undefined, taskStatus: string) => {
  if (!indicator || !indicator.has_due_date) {
    return null;
  }

  const colors = timeIndicatorColors[indicator.status] || timeIndicatorColors.normal;
  const progress = indicator.time_progress ?? 0;
  const daysRemaining = indicator.days_remaining;
  const hasProgress = indicator.total_days !== null;

  const t = i18next.t.bind(i18next);
  let daysText = '';
  if (taskStatus === 'completed') {
    daysText = t('projects.time_completed');
  } else if (daysRemaining === null) {
    daysText = '-';
  } else if (daysRemaining < 0) {
    daysText = t('projects.time_overdue_days', { count: Math.abs(daysRemaining) });
  } else if (daysRemaining === 0) {
    daysText = t('projects.time_today');
  } else if (daysRemaining === 1) {
    daysText = t('projects.time_tomorrow');
  } else {
    daysText = t('projects.time_days_remaining', { count: daysRemaining });
  }

  return (
    <div className="space-y-1">
      <span className="text-[length:var(--text-caption)] text-[var(--text-tertiary)] font-medium">{t('projects.time_until_due')}</span>
      <div className="flex items-center gap-2">
        {hasProgress ? (
          <div className={`flex-1 h-1.5 ${colors.bg} rounded-full overflow-hidden`}>
            <div
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
};

export default renderTimeIndicator;
