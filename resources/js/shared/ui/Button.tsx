import * as React from 'react';
import { cn } from '@shared/lib/utils';
import {IconLoader} from '@tabler/icons-react';

export interface ButtonProps
  extends React.ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: 'primary' | 'secondary' | 'outline' | 'ghost' | 'danger';
  size?: 'sm' | 'md';
  loading?: boolean;
  leftIcon?: React.ReactNode;
  rightIcon?: React.ReactNode;
}

const Button = React.forwardRef<HTMLButtonElement, ButtonProps>(
  (
    {
      className,
      variant = 'primary',
      size = 'md',
      loading = false,
      disabled,
      leftIcon,
      rightIcon,
      children,
      ...props
    },
    ref
  ) => {
    // FLAT COLORS ONLY - NO GRADIENTS
    const variants = {
      primary:
        'bg-[var(--accent-hover)] text-[var(--text-inverse)] hover:bg-[var(--accent-hover-deep)]',
      secondary:
        'bg-[var(--surface-muted)] text-[var(--text-primary)] hover:bg-[var(--border-default)] border border-[var(--border-default)]',
      outline:
        'bg-[var(--surface-base)] text-[var(--text-primary)] border border-[var(--border-default)] hover:bg-[var(--surface-subtle)] hover:border-[var(--border-strong)]',
      ghost:
        'bg-transparent text-[var(--text-secondary)] hover:bg-[var(--surface-muted)] hover:text-[var(--text-primary)]',
      danger:
        'bg-[var(--status-danger)] text-[var(--text-inverse)] hover:bg-[var(--status-danger)]',
    };

    const sizes = {
      sm: 'h-8 px-3 text-[0.8125rem] gap-1 rounded-lg',
      md: 'py-2 px-4 text-[0.8125rem] gap-2 rounded-lg',
    };

    return (
      <button
        ref={ref}
        disabled={disabled || loading}
        className={cn(
          'inline-flex items-center justify-center font-semibold transition-colors duration-150',
          'focus:outline-none focus-visible:shadow-[0_0_0_2px_var(--accent-default)]',
          'disabled:opacity-50 disabled:cursor-not-allowed',
          variants[variant],
          sizes[size],
          className
        )}
        {...props}
      >
        {loading ? (
          <IconLoader className="h-4 w-4 animate-spin" />
        ) : (
          leftIcon
        )}
        {children}
        {!loading && rightIcon}
      </button>
    );
  }
);

Button.displayName = 'Button';

export { Button };
