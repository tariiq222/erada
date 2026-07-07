import * as React from 'react';
import { cn } from '@shared/lib/utils';
import { RequiredIndicator } from './RequiredIndicator';
import { FieldHelp } from './FieldHelp';

export interface TextareaProps
  extends React.TextareaHTMLAttributes<HTMLTextAreaElement> {
  label?: string;
  error?: string;
  hint?: string;
  /** Optional "?" tooltip shown next to the label explaining the field. */
  help?: React.ReactNode;
}

const Textarea = React.forwardRef<HTMLTextAreaElement, TextareaProps>(
  ({ className, label, error, hint, help, id, ...props }, ref) => {
    const generatedId = React.useId();
    const textareaId = id ?? generatedId;

    return (
      <div className="w-full">
        {label && (
          <div className="mb-1 flex items-center gap-1">
            <label
              htmlFor={textareaId}
              className="block text-sm font-medium text-[var(--text-secondary)]"
            >
              {label}
              {props.required && <RequiredIndicator className="ms-1" />}
            </label>
            {help && <FieldHelp content={help} />}
          </div>
        )}
        <textarea
          ref={ref}
          id={textareaId}
          className={cn(
            'block w-full min-h-9 px-3 py-2 text-sm rounded-lg',
            'bg-[var(--surface-base)] text-[var(--text-primary)]',
            'border transition-colors duration-150',
            'placeholder:text-[var(--text-secondary)]',
            'resize-y min-h-[100px]',
            'focus-visible:outline-none',
            'focus-visible:border-[var(--accent-default)] focus-visible:shadow-[0_0_0_2px_var(--accent-subtle)]',
            'disabled:bg-[var(--surface-muted)] disabled:text-[var(--text-disabled)] disabled:cursor-not-allowed',
            error
              ? 'border-[var(--status-danger)] focus-visible:border-[var(--status-danger)] focus-visible:shadow-[0_0_0_2px_var(--status-danger-subtle)]'
              : 'border-[var(--border-default)] hover:border-[var(--border-strong)]',
            className
          )}
          aria-invalid={error ? 'true' : 'false'}
          aria-describedby={
            error ? `${textareaId}-error` : hint ? `${textareaId}-hint` : undefined
          }
          {...props}
        />
        {error && (
          <p id={`${textareaId}-error`} className="mt-1 text-sm text-[var(--status-danger)] flex items-center gap-1">
            <svg className="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
              <path fillRule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clipRule="evenodd" />
            </svg>
            {error}
          </p>
        )}
        {hint && !error && (
          <p id={`${textareaId}-hint`} className="mt-1 text-sm text-[var(--text-secondary)]">
            {hint}
          </p>
        )}
      </div>
    );
  }
);

Textarea.displayName = 'Textarea';

export { Textarea };
