import * as React from 'react';
import { cn } from '@shared/lib/utils';
import {IconAlertCircle, IconCircleCheck, IconInfoCircle, IconAlertTriangle, IconX} from '@tabler/icons-react';

export interface AlertProps extends React.HTMLAttributes<HTMLDivElement> {
  variant?: 'info' | 'success' | 'warning' | 'danger';
  title?: string;
  icon?: React.ReactNode;
  dismissible?: boolean;
  onDismiss?: () => void;
}

// FLAT COLORS ONLY - TOKEN-BASED
const Alert = React.forwardRef<HTMLDivElement, AlertProps>(
  (
    {
      className,
      variant = 'info',
      title,
      icon,
      dismissible,
      onDismiss,
      children,
      ...props
    },
    ref
  ) => {
    const [isVisible, setIsVisible] = React.useState(true);

    const variants = {
      info: {
        container: 'bg-[var(--accent-subtle)] border-[var(--accent-default)]/30 text-[var(--text-primary)]',
        icon: <IconInfoCircle className="h-5 w-5 text-[var(--accent-default)]" />,
      },
      success: {
        container: 'bg-[var(--status-success-subtle)] border-[var(--status-success)]/30 text-[var(--text-primary)]',
        icon: <IconCircleCheck className="h-5 w-5 text-[var(--status-success)]" />,
      },
      warning: {
        container: 'bg-[var(--status-warning-subtle)] border-[var(--status-warning)]/30 text-[var(--text-primary)]',
        icon: <IconAlertTriangle className="h-5 w-5 text-[var(--status-warning)]" />,
      },
      danger: {
        container: 'bg-[var(--status-danger-subtle)] border-[var(--status-danger)]/30 text-[var(--text-primary)]',
        icon: <IconAlertCircle className="h-5 w-5 text-[var(--status-danger)]" />,
      },
    };

    const handleDismiss = () => {
      setIsVisible(false);
      onDismiss?.();
    };

    if (!isVisible) return null;

    const variantConfig = variants[variant];

    return (
      <div
        ref={ref}
        role="alert"
        className={cn(
          'relative flex gap-3 rounded-lg border p-4',
          variantConfig.container,
          className
        )}
        {...props}
      >
        <div className="shrink-0">{icon || variantConfig.icon}</div>
        <div className="flex-1 min-w-0">
          {title && (
            <h5 className="font-semibold text-[var(--text-primary)] mb-1">{title}</h5>
          )}
          <div className="text-sm text-[var(--text-secondary)]">{children}</div>
        </div>
        {dismissible && (
          <button
            onClick={handleDismiss}
            className={cn(
              'shrink-0 rounded-md p-1 hover:bg-[var(--surface-muted)] transition-colors',
              'focus:outline-none focus:ring-2 focus:ring-[var(--accent-default)] focus:ring-offset-2'
            )}
            aria-label="Dismiss"
          >
            <IconX className="h-4 w-4 text-[var(--text-tertiary)]" />
          </button>
        )}
      </div>
    );
  }
);

Alert.displayName = 'Alert';

export { Alert };
