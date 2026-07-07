import * as React from 'react';
import { cn } from '@shared/lib/utils';

interface RadioContextValue {
  name: string;
  value: string;
  onChange: (value: string) => void;
  disabled?: boolean;
}

const RadioContext = React.createContext<RadioContextValue | undefined>(undefined);

function useRadioContext() {
  const context = React.useContext(RadioContext);
  if (!context) {
    throw new Error('Radio components must be used within a RadioGroup provider');
  }
  return context;
}

export interface RadioGroupProps {
  name: string;
  value: string;
  onChange: (value: string) => void;
  disabled?: boolean;
  children: React.ReactNode;
  className?: string;
  orientation?: 'horizontal' | 'vertical';
  id?: string;
  'aria-labelledby'?: string;
  'aria-describedby'?: string;
  'aria-invalid'?: boolean | 'false' | 'true' | 'grammar' | 'spelling';
  'aria-required'?: boolean;
  'aria-errormessage'?: string;
}

const RadioGroup: React.FC<RadioGroupProps> = ({
  name,
  value,
  onChange,
  disabled,
  children,
  className,
  orientation = 'vertical',
  id,
  'aria-labelledby': ariaLabelledBy,
  'aria-describedby': ariaDescribedBy,
  'aria-invalid': ariaInvalid,
  'aria-required': ariaRequired,
  'aria-errormessage': ariaErrorMessage,
}) => {
  return (
    <RadioContext.Provider value={{ name, value, onChange, disabled }}>
      <div
        id={id}
        role="radiogroup"
        aria-labelledby={ariaLabelledBy}
        aria-describedby={ariaDescribedBy}
        aria-invalid={ariaInvalid}
        aria-required={ariaRequired}
        aria-errormessage={ariaErrorMessage}
        className={cn(
          'flex',
          orientation === 'vertical' ? 'flex-col gap-3' : 'flex-row gap-6 flex-wrap',
          className
        )}
      >
        {children}
      </div>
    </RadioContext.Provider>
  );
};

RadioGroup.displayName = 'RadioGroup';

export interface RadioProps
  extends Omit<React.InputHTMLAttributes<HTMLInputElement>, 'type' | 'name' | 'checked' | 'onChange'> {
  value: string;
  label?: string;
  description?: string;
}

const Radio = React.forwardRef<HTMLInputElement, RadioProps>(
  ({ className, value, label, description, id, disabled: itemDisabled, ...props }, ref) => {
    const context = useRadioContext();
    const generatedId = React.useId();
    const radioId = id ?? generatedId;
    const isChecked = context.value === value;
    const isDisabled = itemDisabled || context.disabled;

    return (
      <div className="flex items-start gap-3">
        <div className="relative flex items-center">
          <input
            ref={ref}
            type="radio"
            id={radioId}
            name={context.name}
            value={value}
            checked={isChecked}
            disabled={isDisabled}
            onChange={() => context.onChange(value)}
            className="sr-only peer"
            {...props}
          />
          <div
            className={cn(
              'h-5 w-5 rounded-full border-2 transition-all duration-200 cursor-pointer',
              'flex items-center justify-center',
              isChecked
                ? 'border-[var(--accent-default)]'
                : 'border-[var(--border-default)]',
              'peer-hover:border-[var(--accent-default)]',
              'peer-focus-visible:ring-2 peer-focus-visible:ring-[var(--accent-subtle)] peer-focus-visible:ring-offset-2',
              'peer-disabled:opacity-50 peer-disabled:cursor-not-allowed',
              className
            )}
            onClick={() => !isDisabled && context.onChange(value)}
          >
            {isChecked && (
              <div className="h-2.5 w-2.5 rounded-full bg-[var(--accent-default)]" />
            )}
          </div>
        </div>
        {(label || description) && (
          <div className="flex flex-col gap-0">
            {label && (
              <label
                htmlFor={radioId}
                className={cn(
                  'text-sm font-medium text-[var(--text-primary)] cursor-pointer select-none',
                  isDisabled && 'opacity-50 cursor-not-allowed'
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

Radio.displayName = 'Radio';

export { RadioGroup, Radio };
