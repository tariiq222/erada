import * as React from 'react';
import { cn } from '@shared/lib/utils';

export interface ProgressProps extends React.HTMLAttributes<HTMLDivElement> {
  value: number;
  max?: number;
  size?: 'sm' | 'md';
  showValue?: boolean;
  /** لون شريط التقدّم — افتراضي accent. استخدم success/warning/danger للدلالة الوظيفية. */
  tone?: 'accent' | 'success' | 'warning' | 'danger';
  /** اسم وصفي لقارئ الشاشة (افتراضي: نسبة الإنجاز). */
  label?: string;
}

const toneClass: Record<NonNullable<ProgressProps['tone']>, string> = {
  accent: 'bg-[var(--accent-default)]',
  success: 'bg-[var(--status-success)]',
  warning: 'bg-[var(--status-warning)]',
  danger: 'bg-[var(--status-danger)]',
};

// ONE VARIANT ONLY - FLAT BLUE ACCENT, TOKEN-BASED
const Progress = React.forwardRef<HTMLDivElement, ProgressProps>(
  (
    {
      className,
      value,
      max = 100,
      size = 'md',
      showValue = false,
      tone = 'accent',
      label = 'نسبة الإنجاز',
      ...props
    },
    ref
  ) => {
    const percentage = Math.min(Math.max((value / max) * 100, 0), 100);

    const sizes = {
      sm: 'h-1.5',
      md: 'h-2',
    };

    return (
      <div className={cn('w-full', className)} {...props}>
        <div
          ref={ref}
          role="progressbar"
          aria-label={label}
          aria-valuenow={value}
          aria-valuemin={0}
          aria-valuemax={max}
          aria-valuetext={`${Math.round(percentage)}%`}
          className={cn(
            'w-full bg-[var(--surface-muted)] rounded-full overflow-hidden',
            sizes[size]
          )}
        >
          <div
            data-testid="progress-fill"
            className={cn(
              'h-full rounded-full transition-[width] duration-300 ease-out motion-reduce:transition-none',
              toneClass[tone]
            )}
            style={{ width: `${percentage}%` }}
          />
        </div>
        {showValue && (
          <div className="flex justify-between mt-1">
            <span className="text-xs text-[var(--text-tertiary)]">التقدم</span>
            <span className="text-xs font-medium text-[var(--text-secondary)]">
              {Math.round(percentage)}%
            </span>
          </div>
        )}
      </div>
    );
  }
);

Progress.displayName = 'Progress';

export { Progress };
