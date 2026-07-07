import * as React from 'react';
import { cn } from '@shared/lib/utils';
import {IconCheck, IconMinus} from '@tabler/icons-react';
import { FieldError } from './FieldError';

export interface CheckboxProps
  extends Omit<React.InputHTMLAttributes<HTMLInputElement>, 'type'> {
  label?: string;
  description?: string;
  error?: string;
  indeterminate?: boolean;
}

const Checkbox = React.forwardRef<HTMLInputElement, CheckboxProps>(
  (
    {
      className,
      label,
      description,
      error,
      indeterminate,
      id,
      checked,
      ...props
    },
    ref
  ) => {
    const inputRef = React.useRef<HTMLInputElement>(null);
    const generatedId = React.useId();
    const checkboxId = id ?? generatedId;

    React.useImperativeHandle(ref, () => inputRef.current!);

    React.useEffect(() => {
      if (inputRef.current) {
        inputRef.current.indeterminate = indeterminate ?? false;
      }
    }, [indeterminate]);

    const isChecked = checked || indeterminate;

    return (
      <div className="flex items-start gap-3">
        <label htmlFor={checkboxId} className="relative flex items-center cursor-pointer">
          <input
            ref={inputRef}
            type="checkbox"
            id={checkboxId}
            checked={checked}
            className="sr-only peer"
            aria-invalid={error ? 'true' : 'false'}
            aria-describedby={error ? `${checkboxId}-error` : undefined}
            {...props}
          />
          <span
            className={cn(
              'h-5 w-5 rounded border-2 transition-all duration-200',
              'flex items-center justify-center',
              isChecked
                ? 'bg-[var(--accent-default)] border-[var(--accent-default)] text-[var(--text-inverse)]'
                : 'bg-[var(--surface-base)] border-[var(--border-default)]',
              'peer-hover:border-[var(--accent-default)]',
              'peer-focus-visible:ring-2 peer-focus-visible:ring-[var(--accent-subtle)] peer-focus-visible:ring-offset-2',
              'peer-disabled:opacity-50 peer-disabled:cursor-not-allowed',
              error && 'border-[var(--status-danger)]',
              className
            )}
          >
            {indeterminate ? (
              <IconMinus className="h-3 w-3" strokeWidth={3} />
            ) : checked ? (
              <IconCheck className="h-3 w-3" strokeWidth={3} />
            ) : null}
          </span>
        </label>
        {(label || description || error) && (
          <div className="flex flex-col gap-0">
            {label && (
              <label
                htmlFor={checkboxId}
                className={cn(
                  'text-sm font-medium text-[var(--text-primary)] cursor-pointer select-none',
                  props.disabled && 'opacity-50 cursor-not-allowed'
                )}
              >
                {label}
              </label>
            )}
            {description && (
              <p className="text-sm text-[var(--text-secondary)]">{description}</p>
            )}
            <FieldError id={`${checkboxId}-error`} className="mt-0">{error}</FieldError>
          </div>
        )}
      </div>
    );
  }
);

Checkbox.displayName = 'Checkbox';

export { Checkbox };
