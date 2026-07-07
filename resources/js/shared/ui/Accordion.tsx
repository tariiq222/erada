import * as React from 'react';
import { cn } from '@shared/lib/utils';
import {IconChevronDown} from '@tabler/icons-react';

interface AccordionContextValue {
  expandedItems: Set<string>;
  toggleItem: (value: string) => void;
  type: 'single' | 'multiple';
}

const AccordionContext = React.createContext<AccordionContextValue | undefined>(
  undefined
);

function useAccordionContext() {
  const context = React.useContext(AccordionContext);
  if (!context) {
    throw new Error('Accordion components must be used within an Accordion provider');
  }
  return context;
}

export interface AccordionProps extends React.HTMLAttributes<HTMLDivElement> {
  type?: 'single' | 'multiple';
  defaultValue?: string | string[];
  collapsible?: boolean;
}

const Accordion = React.forwardRef<HTMLDivElement, AccordionProps>(
  (
    {
      className,
      type = 'single',
      defaultValue,
      collapsible = true,
      children,
      ...props
    },
    ref
  ) => {
    const [expandedItems, setExpandedItems] = React.useState<Set<string>>(() => {
      if (defaultValue) {
        return new Set(Array.isArray(defaultValue) ? defaultValue : [defaultValue]);
      }
      return new Set();
    });

    const toggleItem = React.useCallback(
      (value: string) => {
        setExpandedItems((prev) => {
          const newSet = new Set(prev);
          if (newSet.has(value)) {
            if (collapsible || type === 'multiple') {
              newSet.delete(value);
            }
          } else {
            if (type === 'single') {
              newSet.clear();
            }
            newSet.add(value);
          }
          return newSet;
        });
      },
      [type, collapsible]
    );

    return (
      <AccordionContext.Provider value={{ expandedItems, toggleItem, type }}>
        <div
          ref={ref}
          className={cn('divide-y divide-[var(--border-default)] rounded-xl border border-[var(--border-default)]', className)}
          {...props}
        >
          {children}
        </div>
      </AccordionContext.Provider>
    );
  }
);

Accordion.displayName = 'Accordion';

export interface AccordionItemProps extends React.HTMLAttributes<HTMLDivElement> {
  value: string;
  disabled?: boolean;
}

const AccordionItem = React.forwardRef<HTMLDivElement, AccordionItemProps>(
  ({ className, value, disabled, children, ...props }, ref) => {
    const { expandedItems } = useAccordionContext();
    const isExpanded = expandedItems.has(value);

    return (
      <div
        ref={ref}
        data-state={isExpanded ? 'open' : 'closed'}
        data-disabled={disabled || undefined}
        className={cn(
          'overflow-hidden first:rounded-t-xl last:rounded-b-xl',
          disabled && 'opacity-50',
          className
        )}
        {...props}
      >
        {React.Children.map(children, (child) => {
          if (React.isValidElement(child)) {
            return React.cloneElement(child as React.ReactElement<any>, {
              value,
              disabled,
              isExpanded,
            });
          }
          return child;
        })}
      </div>
    );
  }
);

AccordionItem.displayName = 'AccordionItem';

export interface AccordionTriggerProps
  extends React.ButtonHTMLAttributes<HTMLButtonElement> {
  value?: string;
  isExpanded?: boolean;
  icon?: React.ReactNode;
}

const AccordionTrigger = React.forwardRef<HTMLButtonElement, AccordionTriggerProps>(
  ({ className, value, isExpanded, disabled, icon, children, ...props }, ref) => {
    const { toggleItem } = useAccordionContext();

    return (
      <button
        ref={ref}
        type="button"
        aria-expanded={isExpanded}
        disabled={disabled}
        onClick={() => value && toggleItem(value)}
        className={cn(
          'flex w-full items-center justify-between px-4 py-4 text-start',
          'font-medium text-[var(--text-primary)] hover:bg-[var(--surface-subtle)] transition-colors duration-200',
          'focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-[var(--accent-default)]',
          'disabled:cursor-not-allowed disabled:hover:bg-transparent',
          className
        )}
        {...props}
      >
        <span className="flex items-center gap-3">
          {icon}
          {children}
        </span>
        <IconChevronDown
          className={cn(
            'h-5 w-5 text-[var(--text-tertiary)] transition-transform duration-200',
            isExpanded && 'rotate-180'
          )}
        />
      </button>
    );
  }
);

AccordionTrigger.displayName = 'AccordionTrigger';

export interface AccordionContentProps
  extends React.HTMLAttributes<HTMLDivElement> {
  isExpanded?: boolean;
}

const AccordionContent = React.forwardRef<HTMLDivElement, AccordionContentProps>(
  ({ className, isExpanded, children, ...props }, ref) => {
    // Use CSS Grid for height animation - no JS measurement, no reflow
    return (
      <div
        ref={ref}
        className={cn(
          'grid transition-[grid-template-rows] duration-200 ease-out',
          isExpanded ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]',
          className
        )}
        {...props}
      >
        <div className="overflow-hidden">
          <div className="px-4 pb-4 pt-0 text-[var(--text-secondary)] text-sm">
            {children}
          </div>
        </div>
      </div>
    );
  }
);

AccordionContent.displayName = 'AccordionContent';

export { Accordion, AccordionItem, AccordionTrigger, AccordionContent };
