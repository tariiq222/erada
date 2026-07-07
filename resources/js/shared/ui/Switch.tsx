import * as React from 'react';
import { cn } from '@shared/lib/utils';

export interface SwitchProps
  extends Omit<React.InputHTMLAttributes<HTMLInputElement>, 'type' | 'size'> {
  label?: string;
  description?: string;
  size?: 'sm' | 'md' | 'lg';
}

const Switch = React.forwardRef<HTMLInputElement, SwitchProps>(
  (
    { className, label, description, size = 'md', id, checked, disabled, ...props },
    ref
  ) => {
    const generatedId = React.useId();
    const switchId = id ?? generatedId;

    const sizes = {
      sm: {
        track: 'h-5 w-9',
        thumb: 'h-4 w-4',
        translate: 'translate-x-4 rtl:-translate-x-4',
      },
      md: {
        track: 'h-6 w-11',
        thumb: 'h-5 w-5',
        translate: 'translate-x-5 rtl:-translate-x-5',
      },
      lg: {
        track: 'h-7 w-14',
        thumb: 'h-6 w-6',
        translate: 'translate-x-7 rtl:-translate-x-7',
      },
    };

    const sizeConfig = sizes[size];

    return (
      <div className="flex items-start gap-3">
        <div className="relative flex items-center">
          <input
            ref={ref}
            type="checkbox"
            id={switchId}
            checked={checked}
            disabled={disabled}
            className="sr-only peer"
            role="switch"
            aria-checked={checked}
            {...props}
          />
          <div
            className={cn(
              'relative rounded-full transition-colors duration-200 cursor-pointer',
              sizeConfig.track,
              checked ? 'bg-[var(--accent-default)]' : 'bg-[var(--border-default)]',
              'peer-hover:opacity-90',
              'peer-focus-visible:ring-2 peer-focus-visible:ring-[var(--accent-subtle)] peer-focus-visible:ring-offset-2',
              'peer-disabled:opacity-50 peer-disabled:cursor-not-allowed',
              className
            )}
            onClick={() => {
              if (!disabled) {
                const input = document.getElementById(switchId) as HTMLInputElement;
                input?.click();
              }
            }}
          >
            <span
              className={cn(
                'absolute top-0.5 start-0.5 bg-[var(--control-knob)] rounded-full shadow-sm transition-transform duration-200 motion-reduce:transition-none',
                sizeConfig.thumb,
                checked && sizeConfig.translate
              )}
            />
          </div>
        </div>
        {(label || description) && (
          <div className="flex flex-col gap-0">
            {label && (
              <label
                htmlFor={switchId}
                className={cn(
                  'text-sm font-medium text-[var(--text-primary)] cursor-pointer select-none',
                  disabled && 'opacity-50 cursor-not-allowed'
                )}
              >
                {label}
              </label>
            )}
            {description && (
              <p className="text-sm text-[var(--text-secondary)]">{description}</p>
            )}
          </div>
        )}
      </div>
    );
  }
);

Switch.displayName = 'Switch';

export { Switch };
