import * as React from 'react';
import { cn } from '@shared/lib/utils';

export interface BadgeProps extends React.HTMLAttributes<HTMLSpanElement> {
  variant?: 'default' | 'success' | 'warning' | 'danger' | 'accent' | 'info';
  size?: 'sm' | 'md';
}

const Badge = React.forwardRef<HTMLSpanElement, BadgeProps>(
  ({ className, variant = 'default', size = 'md', children, ...props }, ref) => {
    // FLAT COLORS ONLY - NO GRADIENTS, NO OUTLINE, NO PULSE
    const variants = {
      default: 'bg-[var(--surface-muted)] text-[var(--text-secondary)]',
      success: 'bg-[var(--status-success-subtle)] text-[var(--status-success-text)]',
      warning: 'bg-[var(--status-warning-subtle)] text-[var(--status-warning-text)]',
      danger: 'bg-[var(--status-danger-subtle)] text-[var(--status-danger-text)]',
      accent: 'bg-[var(--accent-subtle)] text-[var(--accent-default)]',
      // alias مقصود للأزرق ضمن نظام single-blue (لا يوجد بنفسجي)
      info: 'bg-[var(--accent-subtle)] text-[var(--accent-default)]',
    };

    const sizes = {
      sm: 'px-2 py-0 text-[11px] leading-4',
      md: 'px-2 py-1 text-[11px] leading-4',
    };

    return (
      <span
        ref={ref}
        className={cn(
          'inline-flex items-center font-medium rounded-full',
          variants[variant],
          sizes[size],
          className
        )}
        {...props}
      >
        {children}
      </span>
    );
  }
);

Badge.displayName = 'Badge';

export { Badge };
