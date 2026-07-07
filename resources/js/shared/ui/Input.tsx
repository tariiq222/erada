import * as React from 'react';
import { cn } from '@shared/lib/utils';
import { FieldError } from './FieldError';
import { RequiredIndicator } from './RequiredIndicator';
import { FieldHelp } from './FieldHelp';

export interface InputProps
  extends React.InputHTMLAttributes<HTMLInputElement> {
  label?: string;
  error?: string;
  hint?: string;
  /** Optional "?" tooltip shown next to the label explaining the field. */
  help?: React.ReactNode;
  leftIcon?: React.ReactNode;
  rightIcon?: React.ReactNode;
}

const Input = React.forwardRef<HTMLInputElement, InputProps>(
  (
    {
      className,
      type = 'text',
      label,
      error,
      hint,
      help,
      leftIcon,
      rightIcon,
      id,
      ...props
    },
    ref
  ) => {
    const generatedId = React.useId();
    const inputId = id ?? generatedId;

    return (
      <div className="w-full">
        {label && (
          <div className="mb-1 flex items-center gap-1">
            <label
              htmlFor={inputId}
              className="block text-sm font-medium text-[var(--text-secondary)]"
            >
              {label}
              {props.required && <RequiredIndicator className="ms-1" />}
            </label>
            {help && <FieldHelp content={help} />}
          </div>
        )}
        <div className="relative group">
          {leftIcon && (
            <div className="absolute inset-y-0 start-0 flex items-center ps-3 pointer-events-none text-[var(--text-tertiary)] group-focus-within:text-[var(--accent-default)] transition-colors">
              {leftIcon}
            </div>
          )}
          <input
            ref={ref}
            id={inputId}
            type={type}
            className={cn(
              'block w-full min-h-9 px-3 py-2 text-sm rounded-lg',
              'bg-[var(--surface-base)] text-[var(--text-primary)]',
              'border transition-colors duration-150',
              'placeholder:text-[var(--text-secondary)]',
              'focus-visible:outline-none',
              'focus-visible:border-[var(--accent-default)] focus-visible:shadow-[0_0_0_2px_var(--accent-subtle)]',
              'disabled:bg-[var(--surface-muted)] disabled:text-[var(--text-disabled)] disabled:cursor-not-allowed',
              error
                ? 'border-[var(--status-danger)] focus-visible:border-[var(--status-danger)] focus-visible:shadow-[0_0_0_2px_var(--status-danger-subtle)]'
                : 'border-[var(--border-default)] hover:border-[var(--border-strong)]',
              leftIcon && 'ps-10',
              rightIcon && 'pe-10',
              className
            )}
            aria-invalid={error ? 'true' : 'false'}
            aria-describedby={error ? `${inputId}-error` : hint ? `${inputId}-hint` : undefined}
            {...props}
          />
          {rightIcon && (
            <div className="absolute inset-y-0 end-0 flex items-center pe-3 pointer-events-none text-[var(--text-tertiary)] group-focus-within:text-[var(--accent-default)] transition-colors">
              {rightIcon}
            </div>
          )}
        </div>
        <FieldError id={`${inputId}-error`}>{error}</FieldError>
        {hint && !error && (
          <p id={`${inputId}-hint`} className="mt-1 text-sm text-[var(--text-secondary)]">
            {hint}
          </p>
        )}
      </div>
    );
  }
);

Input.displayName = 'Input';

export { Input };
