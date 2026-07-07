import * as React from 'react';
import { createPortal } from 'react-dom';
import { cn } from '@shared/lib/utils';
import {IconChevronDown, IconCheck} from '@tabler/icons-react';

interface DropdownContextValue {
  isOpen: boolean;
  setIsOpen: (open: boolean) => void;
  selectedValue: string | null;
  setSelectedValue: (value: string) => void;
  triggerRef: React.RefObject<HTMLButtonElement | null>;
  menuRef: React.RefObject<HTMLDivElement | null>;
}

const DropdownContext = React.createContext<DropdownContextValue | undefined>(
  undefined
);

function useDropdownContext() {
  const context = React.useContext(DropdownContext);
  if (!context) {
    throw new Error('Dropdown components must be used within a Dropdown provider');
  }
  return context;
}

export interface DropdownProps {
  children: React.ReactNode;
  value?: string;
  onValueChange?: (value: string) => void;
}

const Dropdown: React.FC<DropdownProps> = ({
  children,
  value,
  onValueChange,
}) => {
  const [isOpen, setIsOpen] = React.useState(false);
  const [selectedValue, setSelectedValueState] = React.useState<string | null>(
    value || null
  );
  const dropdownRef = React.useRef<HTMLDivElement>(null);
  const triggerRef = React.useRef<HTMLButtonElement>(null);
  const menuRef = React.useRef<HTMLDivElement>(null);

  const setSelectedValue = React.useCallback(
    (newValue: string) => {
      setSelectedValueState(newValue);
      onValueChange?.(newValue);
      setIsOpen(false);
    },
    [onValueChange]
  );

  React.useEffect(() => {
    const handleClickOutside = (event: MouseEvent) => {
      const target = event.target as Node;
      const inTrigger = dropdownRef.current?.contains(target);
      const inMenu = menuRef.current?.contains(target);
      if (!inTrigger && !inMenu) {
        setIsOpen(false);
      }
    };

    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  return (
    <DropdownContext.Provider
      value={{ isOpen, setIsOpen, selectedValue, setSelectedValue, triggerRef, menuRef }}
    >
      <div ref={dropdownRef} className="relative inline-block">
        {children}
      </div>
    </DropdownContext.Provider>
  );
};

Dropdown.displayName = 'Dropdown';

export interface DropdownTriggerProps
  extends React.ButtonHTMLAttributes<HTMLButtonElement> {
  placeholder?: string;
}

const DropdownTrigger = React.forwardRef<HTMLButtonElement, DropdownTriggerProps>(
  ({ className, placeholder = 'اختر...', children, ...props }, ref) => {
    const { isOpen, setIsOpen, selectedValue, triggerRef } = useDropdownContext();

    return (
      <button
        ref={(node) => {
          triggerRef.current = node;
          if (typeof ref === 'function') ref(node);
          else if (ref) ref.current = node;
        }}
        type="button"
        onClick={() => setIsOpen(!isOpen)}
        className={cn(
          'inline-flex items-center justify-between gap-2 rounded-lg border border-[var(--border-default)]',
          'bg-[var(--surface-base)] px-4 py-2 text-sm text-[var(--text-primary)] min-w-[200px]',
          'hover:bg-[var(--surface-subtle)] transition-colors duration-200',
          'focus:outline-none focus:ring-2 focus:ring-[var(--accent-default)] focus:border-[var(--border-focus)]',
          isOpen && 'ring-2 ring-[var(--accent-default)] border-[var(--border-focus)]',
          className
        )}
        aria-expanded={isOpen}
        aria-haspopup="listbox"
        {...props}
      >
        <span className={!selectedValue ? 'text-[var(--text-tertiary)]' : ''}>
          {children || selectedValue || placeholder}
        </span>
        <IconChevronDown
          className={cn(
            'h-4 w-4 text-[var(--text-tertiary)] transition-transform duration-200',
            isOpen && 'rotate-180'
          )}
        />
      </button>
    );
  }
);

DropdownTrigger.displayName = 'DropdownTrigger';

export interface DropdownMenuProps extends React.HTMLAttributes<HTMLDivElement> {
  align?: 'start' | 'end';
}

const DropdownMenu = React.forwardRef<HTMLDivElement, DropdownMenuProps>(
  ({ className, align = 'start', children, ...props }, ref) => {
    const { isOpen, triggerRef, menuRef } = useDropdownContext();
    const [style, setStyle] = React.useState<React.CSSProperties>({});

    // Portal to <body> + fixed positioning so the menu escapes any
    // overflow-hidden ancestor (cards/tables) instead of being clipped.
    const position = React.useCallback(() => {
      if (!triggerRef.current) return;
      const rect = triggerRef.current.getBoundingClientRect();
      const gap = 4;
      const base: React.CSSProperties = {
        position: 'fixed',
        top: rect.bottom + gap,
        minWidth: Math.max(rect.width, 200),
        zIndex: 9999,
      };
      setStyle(
        align === 'end'
          ? { ...base, right: window.innerWidth - rect.right }
          : { ...base, left: rect.left }
      );
    }, [align, triggerRef]);

    React.useLayoutEffect(() => {
      if (isOpen) position();
    }, [isOpen, position]);

    React.useEffect(() => {
      if (!isOpen) return;
      window.addEventListener('resize', position);
      window.addEventListener('scroll', position, true);
      return () => {
        window.removeEventListener('resize', position);
        window.removeEventListener('scroll', position, true);
      };
    }, [isOpen, position]);

    if (!isOpen) return null;

    return createPortal(
      <div
        ref={(node) => {
          menuRef.current = node;
          if (typeof ref === 'function') ref(node);
          else if (ref) ref.current = node;
        }}
        role="listbox"
        style={style}
        className={cn(
          'py-1',
          'bg-[var(--surface-base)] rounded-lg border border-[var(--border-default)] shadow-lg',
          'animate-in fade-in-0 zoom-in-95 duration-100',
          className
        )}
        {...props}
      >
        {children}
      </div>,
      document.body
    );
  }
);

DropdownMenu.displayName = 'DropdownMenu';

export interface DropdownItemProps
  extends React.ButtonHTMLAttributes<HTMLButtonElement> {
  value: string;
  icon?: React.ReactNode;
}

const DropdownItem = React.forwardRef<HTMLButtonElement, DropdownItemProps>(
  ({ className, value, icon, children, ...props }, ref) => {
    const { selectedValue, setSelectedValue } = useDropdownContext();
    const isSelected = selectedValue === value;

    return (
      <button
        ref={ref}
        type="button"
        role="option"
        aria-selected={isSelected}
        onClick={() => setSelectedValue(value)}
        className={cn(
          'flex w-full items-center gap-2 px-4 py-2 text-sm text-[var(--text-secondary)]',
          'hover:bg-[var(--surface-subtle)] transition-colors duration-150',
          'focus:outline-none focus:bg-[var(--surface-subtle)]',
          isSelected && 'bg-[var(--accent-subtle)] text-[var(--accent-default)]',
          className
        )}
        {...props}
      >
        {icon && <span className="shrink-0">{icon}</span>}
        <span className="flex-1 text-start">{children}</span>
        {isSelected && <IconCheck className="h-4 w-4 text-[var(--accent-default)] shrink-0" />}
      </button>
    );
  }
);

DropdownItem.displayName = 'DropdownItem';

const DropdownSeparator = React.forwardRef<
  HTMLDivElement,
  React.HTMLAttributes<HTMLDivElement>
>(({ className, ...props }, ref) => (
  <div
    ref={ref}
    className={cn('h-px my-1 bg-[var(--border-default)]', className)}
    {...props}
  />
));

DropdownSeparator.displayName = 'DropdownSeparator';

export { Dropdown, DropdownTrigger, DropdownMenu, DropdownItem, DropdownSeparator };
