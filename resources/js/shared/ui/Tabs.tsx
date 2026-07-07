import * as React from 'react';
import { cn } from '@shared/lib/utils';

interface TabsContextValue {
  activeTab: string;
  setActiveTab: (value: string) => void;
  baseId: string;
}

const TabsContext = React.createContext<TabsContextValue | undefined>(undefined);

function useTabsContext() {
  const context = React.useContext(TabsContext);
  if (!context) {
    throw new Error('Tabs components must be used within a Tabs provider');
  }
  return context;
}

export interface TabsProps extends React.HTMLAttributes<HTMLDivElement> {
  defaultValue: string;
  value?: string;
  onValueChange?: (value: string) => void;
}

// ONE VARIANT ONLY - UNDERLINE STYLE WITH FLAT BLUE ACCENT
const Tabs = React.forwardRef<HTMLDivElement, TabsProps>(
  (
    {
      className,
      defaultValue,
      value,
      onValueChange,
      children,
      ...props
    },
    ref
  ) => {
    const [activeTab, setActiveTabState] = React.useState(defaultValue);
    const baseId = React.useId();

    const currentTab = value !== undefined ? value : activeTab;

    const setActiveTab = React.useCallback(
      (newValue: string) => {
        if (value === undefined) {
          setActiveTabState(newValue);
        }
        onValueChange?.(newValue);
      },
      [value, onValueChange]
    );

    return (
      <TabsContext.Provider
        value={{ activeTab: currentTab, setActiveTab, baseId }}
      >
        <div ref={ref} className={cn('w-full', className)} {...props}>
          {children}
        </div>
      </TabsContext.Provider>
    );
  }
);

Tabs.displayName = 'Tabs';

export interface TabsListProps extends React.HTMLAttributes<HTMLDivElement> {}

const TabsList = React.forwardRef<HTMLDivElement, TabsListProps>(
  ({ className, children, onKeyDown, ...props }, ref) => {
    const handleKeyDown = (e: React.KeyboardEvent<HTMLDivElement>) => {
      onKeyDown?.(e);
      const keys = ['ArrowRight', 'ArrowLeft', 'Home', 'End'];
      if (!keys.includes(e.key)) return;
      const tabs = Array.from(
        e.currentTarget.querySelectorAll<HTMLButtonElement>('[role="tab"]:not([disabled])')
      );
      const idx = tabs.indexOf(document.activeElement as HTMLButtonElement);
      if (idx < 0) return;
      const rtl = getComputedStyle(e.currentTarget).direction === 'rtl';
      let next = idx;
      if (e.key === 'ArrowRight') next = rtl ? idx - 1 : idx + 1;
      else if (e.key === 'ArrowLeft') next = rtl ? idx + 1 : idx - 1;
      else if (e.key === 'Home') next = 0;
      else if (e.key === 'End') next = tabs.length - 1;
      e.preventDefault();
      const target = tabs[(next + tabs.length) % tabs.length];
      target?.focus();
      target?.click();
    };

    return (
      <div
        ref={ref}
        role="tablist"
        onKeyDown={handleKeyDown}
        className={cn(
          'flex items-center gap-0 border-b border-[var(--border-default)] overflow-x-auto scrollbar-hide',
          className
        )}
        {...props}
      >
        {children}
      </div>
    );
  }
);

TabsList.displayName = 'TabsList';

export interface TabsTriggerProps
  extends React.ButtonHTMLAttributes<HTMLButtonElement> {
  value: string;
  icon?: React.ReactNode;
}

const TabsTrigger = React.forwardRef<HTMLButtonElement, TabsTriggerProps>(
  ({ className, value, icon, children, ...props }, ref) => {
    const { activeTab, setActiveTab, baseId } = useTabsContext();
    const isActive = activeTab === value;

    return (
      <button
        ref={ref}
        role="tab"
        type="button"
        id={`${baseId}-tab-${value}`}
        aria-controls={`${baseId}-panel-${value}`}
        aria-selected={isActive}
        tabIndex={isActive ? 0 : -1}
        data-state={isActive ? 'active' : 'inactive'}
        onClick={() => setActiveTab(value)}
        className={cn(
          'inline-flex items-center justify-center gap-2 font-medium transition-colors duration-150',
          'px-4 py-2 text-sm border-b-2 -mb-px whitespace-nowrap',
          'focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--accent-default)] focus-visible:ring-offset-2',
          isActive
            ? 'border-[var(--accent-default)] text-[var(--accent-default)]'
            : 'border-transparent text-[var(--text-tertiary)] hover:text-[var(--text-primary)] hover:border-[var(--border-strong)]',
          className
        )}
        {...props}
      >
        {icon}
        {children}
      </button>
    );
  }
);

TabsTrigger.displayName = 'TabsTrigger';

export interface TabsContentProps extends React.HTMLAttributes<HTMLDivElement> {
  value: string;
}

const TabsContent = React.forwardRef<HTMLDivElement, TabsContentProps>(
  ({ className, value, children, ...props }, ref) => {
    const { activeTab, baseId } = useTabsContext();
    const isActive = activeTab === value;

    if (!isActive) return null;

    return (
      <div
        ref={ref}
        role="tabpanel"
        id={`${baseId}-panel-${value}`}
        aria-labelledby={`${baseId}-tab-${value}`}
        data-state={isActive ? 'active' : 'inactive'}
        className={cn('mt-4 focus:outline-none', className)}
        tabIndex={0}
        {...props}
      >
        {children}
      </div>
    );
  }
);

TabsContent.displayName = 'TabsContent';

export { Tabs, TabsList, TabsTrigger, TabsContent };
