import * as React from 'react';
import { createPortal } from 'react-dom';
import { cn } from '@shared/lib/utils';
import {IconChevronDown, IconCheck, IconSearch} from '@tabler/icons-react';
import { RequiredIndicator } from './RequiredIndicator';
import { FieldHelp } from './FieldHelp';

export interface SelectOption {
  value: string;
  label: string;
  disabled?: boolean;
}

export interface SelectProps {
  label?: string;
  error?: string;
  hint?: string;
  /** Optional "?" tooltip shown next to the label explaining the field. */
  help?: React.ReactNode;
  options: SelectOption[];
  placeholder?: string;
  value?: string;
  defaultValue?: string;
  onChange?: (e: { target: { value: string } }) => void;
  disabled?: boolean;
  className?: string;
  id?: string;
  name?: string;
  required?: boolean;
  searchable?: boolean;
  searchThreshold?: number;
  role?: string;
}

const Select = React.forwardRef<HTMLDivElement, SelectProps>(
  (
    {
      className,
      label,
      error,
      hint,
      help,
      options,
      placeholder = '-- اختر --',
      value: controlledValue,
      defaultValue,
      onChange,
      disabled,
      id,
      name,
      required,
      searchable,
      searchThreshold = 10,
      role,
    },
    ref
  ) => {
    const generatedId = React.useId();
    const selectId = id ?? generatedId;
    const listboxId = `${selectId}-listbox`;
    const [isOpen, setIsOpen] = React.useState(false);
    const [internalValue, setInternalValue] = React.useState(defaultValue || '');
    const [searchQuery, setSearchQuery] = React.useState('');
    const containerRef = React.useRef<HTMLDivElement>(null);
    const buttonRef = React.useRef<HTMLButtonElement>(null);
    const dropdownRef = React.useRef<HTMLDivElement>(null);
    const listRef = React.useRef<HTMLUListElement>(null);
    const searchInputRef = React.useRef<HTMLInputElement>(null);
    const [focusedIndex, setFocusedIndex] = React.useState(-1);
    const [dropdownStyle, setDropdownStyle] = React.useState<React.CSSProperties>({});
    const [listMaxHeight, setListMaxHeight] = React.useState(240);

    const value = controlledValue !== undefined ? controlledValue : internalValue;
    const selectedOption = options.find(opt => opt.value === value);

    const showSearch = searchable === true || (searchable !== false && options.length > searchThreshold);

    const filteredOptions = React.useMemo(() => {
      if (!searchQuery.trim()) return options;
      const query = searchQuery.toLowerCase();
      return options.filter(opt => opt.label.toLowerCase().includes(query));
    }, [options, searchQuery]);

    const activeOptionId =
      isOpen && focusedIndex >= 0 && filteredOptions[focusedIndex]
        ? `${listboxId}-option-${focusedIndex}`
        : undefined;

    // Position the floating panel from the trigger's viewport rect so it can
    // be portaled to <body> and escape any overflow-hidden ancestor (cards/tables).
    const positionDropdown = React.useCallback(() => {
      if (!buttonRef.current) return;
      const rect = buttonRef.current.getBoundingClientRect();
      const pad = 12;
      const gap = 4;
      const searchSpace = showSearch ? 56 : 0;
      const desired = 240 + searchSpace;
      const spaceBelow = window.innerHeight - rect.bottom - pad - gap;
      const spaceAbove = rect.top - pad - gap;
      const placeBelow = spaceBelow >= desired || spaceBelow >= spaceAbove;
      const avail = placeBelow ? spaceBelow : spaceAbove;
      setListMaxHeight(Math.max(Math.min(240, avail - searchSpace), 96));
      const base: React.CSSProperties = { position: 'fixed', left: rect.left, width: rect.width, zIndex: 9999 };
      setDropdownStyle(
        placeBelow
          ? { ...base, top: rect.bottom + gap }
          : { ...base, bottom: window.innerHeight - rect.top + gap }
      );
    }, [showSearch]);

    const openDropdown = () => {
      positionDropdown();
      setIsOpen(true);
    };

    React.useEffect(() => {
      if (!isOpen) return;
      window.addEventListener('resize', positionDropdown);
      window.addEventListener('scroll', positionDropdown, true);
      return () => {
        window.removeEventListener('resize', positionDropdown);
        window.removeEventListener('scroll', positionDropdown, true);
      };
    }, [isOpen, positionDropdown]);

    React.useEffect(() => {
      if (!isOpen) return;

      const handleClickOutside = (event: MouseEvent) => {
        const target = event.target as Node;
        const inContainer = containerRef.current?.contains(target);
        const inDropdown = dropdownRef.current?.contains(target);
        if (!inContainer && !inDropdown) {
          setIsOpen(false);
          setFocusedIndex(-1);
          setSearchQuery('');
        }
      };

      document.addEventListener('mousedown', handleClickOutside);
      return () => document.removeEventListener('mousedown', handleClickOutside);
    }, [isOpen]);

    React.useEffect(() => {
      if (isOpen && showSearch && searchInputRef.current) {
        setTimeout(() => searchInputRef.current?.focus(), 50);
      }
    }, [isOpen, showSearch]);

    React.useEffect(() => {
      if (isOpen && focusedIndex >= 0 && listRef.current) {
        const focusedElement = listRef.current.children[focusedIndex] as HTMLElement;
        if (focusedElement) {
          focusedElement.scrollIntoView({ block: 'nearest' });
        }
      }
    }, [focusedIndex, isOpen]);

    const handleSelect = (optionValue: string) => {
      if (controlledValue === undefined) {
        setInternalValue(optionValue);
      }
      onChange?.({ target: { value: optionValue } });
      setIsOpen(false);
      setFocusedIndex(-1);
      setSearchQuery('');
    };

    const handleKeyDown = (event: React.KeyboardEvent) => {
      if (disabled) return;

      switch (event.key) {
        case 'Enter':
          event.preventDefault();
          if (isOpen && focusedIndex >= 0) {
            const option = filteredOptions[focusedIndex];
            if (option && !option.disabled) {
              handleSelect(option.value);
            }
          } else {
            isOpen ? setIsOpen(false) : openDropdown();
          }
          break;
        case ' ':
          if (!showSearch || !isOpen) {
            event.preventDefault();
            if (!isOpen) {
              openDropdown();
            }
          }
          break;
        case 'ArrowDown':
          event.preventDefault();
          if (!isOpen) {
            openDropdown();
            setFocusedIndex(0);
          } else {
            setFocusedIndex(prev => {
              let next = prev + 1;
              while (next < filteredOptions.length && filteredOptions[next].disabled) {
                next++;
              }
              return next < filteredOptions.length ? next : prev;
            });
          }
          break;
        case 'ArrowUp':
          event.preventDefault();
          if (isOpen) {
            setFocusedIndex(prev => {
              let next = prev - 1;
              while (next >= 0 && filteredOptions[next].disabled) {
                next--;
              }
              return next >= 0 ? next : prev;
            });
          }
          break;
        case 'Escape':
          setIsOpen(false);
          setFocusedIndex(-1);
          setSearchQuery('');
          break;
        case 'Tab':
          setIsOpen(false);
          setFocusedIndex(-1);
          setSearchQuery('');
          break;
      }
    };

    return (
      <div className="w-full" ref={ref}>
        {label && (
          <div className="mb-1 flex items-center gap-1">
            <label
              htmlFor={selectId}
              className="block text-sm font-medium text-[var(--text-secondary)]"
            >
              {label}
              {required && <RequiredIndicator className="ms-1" />}
            </label>
            {help && <FieldHelp content={help} />}
          </div>
        )}

        <input type="hidden" name={name} value={value} />

        <div className="relative" ref={containerRef}>
          <button
            type="button"
            id={selectId}
            ref={buttonRef}
            onClick={() => !disabled && (isOpen ? setIsOpen(false) : openDropdown())}
            onKeyDown={handleKeyDown}
            disabled={disabled}
            role={role}
            aria-haspopup="listbox"
            aria-expanded={isOpen}
            aria-controls={isOpen ? listboxId : undefined}
            aria-activedescendant={activeOptionId}
            aria-invalid={error ? 'true' : 'false'}
            aria-required={required ? 'true' : undefined}
            aria-describedby={
              error ? `${selectId}-error` : hint ? `${selectId}-hint` : undefined
            }
            className={cn(
              'relative w-full text-start',
              'px-3 py-2 pe-10',
              'bg-[var(--surface-base)] border rounded-md',
              'text-[var(--text-primary)] text-sm',
              'transition-colors duration-150',
              'focus:outline-none focus:ring-2 focus:ring-offset-0',
              isOpen && !error && 'border-[var(--accent-default)] ring-2 ring-[var(--accent-subtle)]',
              !isOpen && !error && 'border-[var(--border-default)] hover:border-[var(--border-strong)]',
              error && 'border-[var(--status-danger)] focus:border-[var(--status-danger)] ring-2 ring-[var(--status-danger-subtle)]',
              disabled && 'bg-[var(--surface-muted)] text-[var(--text-disabled)] cursor-not-allowed',
              !disabled && 'cursor-pointer',
              className
            )}
          >
            <span className={cn(
              'block truncate',
              !selectedOption && 'text-[var(--text-secondary)]'
            )}>
              {selectedOption ? selectedOption.label : placeholder}
            </span>

            <span className="absolute inset-y-0 end-0 flex items-center pe-3 pointer-events-none">
              <IconChevronDown
                className={cn(
                  'h-4 w-4 transition-transform duration-150',
                  isOpen ? 'rotate-180 text-[var(--accent-default)]' : 'text-[var(--text-tertiary)]'
                )}
              />
            </span>
          </button>

          {isOpen && createPortal(
            <div
              ref={dropdownRef}
              style={dropdownStyle}
              className={cn(
                'transition-all duration-150 ease-out origin-top',
                'opacity-100 scale-y-100'
              )}
            >
              <div
                className={cn(
                  'bg-[var(--surface-base)] rounded-md',
                  'border border-[var(--border-default)]',
                  'shadow-lg',
                  'overflow-hidden'
                )}
              >
                {showSearch && (
                  <div className="p-2 border-b border-[var(--border-default)]">
                    <div className="relative">
                      <IconSearch className="absolute start-2.5 top-1/2 -translate-y-1/2 h-4 w-4 text-[var(--text-tertiary)]" />
                      <input
                        ref={searchInputRef}
                        type="text"
                        value={searchQuery}
                        onChange={(e) => {
                          setSearchQuery(e.target.value);
                          setFocusedIndex(0);
                        }}
                        onKeyDown={handleKeyDown}
                        placeholder="ابحث..."
                        className={cn(
                          'w-full ps-8 pe-3 py-2 text-sm',
                          'bg-[var(--surface-subtle)] border border-[var(--border-default)] rounded-md',
                          'text-[var(--text-primary)] placeholder:text-[var(--text-secondary)]',
                          'focus:outline-none focus:ring-1 focus:ring-[var(--accent-default)] focus:border-[var(--accent-default)]'
                        )}
                      />
                    </div>
                  </div>
                )}

                <ul
                  ref={listRef}
                  id={listboxId}
                  role="listbox"
                  tabIndex={0}
                  aria-labelledby={selectId}
                  style={{ maxHeight: listMaxHeight }}
                  className="py-1 overflow-auto"
                >
                  {filteredOptions.map((option, index) => {
                    const isSelected = option.value === value;
                    const isFocused = index === focusedIndex;

                    return (
                      <li
                        key={option.value}
                        id={`${listboxId}-option-${index}`}
                        role="option"
                        aria-selected={isSelected}
                        aria-disabled={option.disabled}
                        onClick={() => !option.disabled && handleSelect(option.value)}
                        onMouseEnter={() => !option.disabled && setFocusedIndex(index)}
                        className={cn(
                          'relative px-3 py-2 mx-1 rounded',
                          'text-sm cursor-pointer',
                          'transition-colors duration-100',
                          'flex items-center justify-between gap-2',
                          option.disabled && 'text-[var(--text-disabled)] cursor-not-allowed',
                          !option.disabled && !isSelected && !isFocused && 'text-[var(--text-primary)] hover:bg-[var(--surface-muted)]',
                          !option.disabled && isFocused && !isSelected && 'bg-[var(--surface-muted)] text-[var(--text-primary)]',
                          isSelected && 'bg-[var(--accent-default)] text-[var(--text-inverse)]'
                        )}
                      >
                        <span className="block truncate font-medium">
                          {option.label}
                        </span>

                        {isSelected && (
                          <IconCheck className="h-4 w-4 shrink-0" />
                        )}
                      </li>
                    );
                  })}

                  {filteredOptions.length === 0 && (
                    <li className="px-3 py-2 text-sm text-[var(--text-secondary)] text-center">
                      {searchQuery ? 'لا توجد نتائج للبحث' : 'لا توجد خيارات'}
                    </li>
                  )}
                </ul>
              </div>
            </div>,
            document.body
          )}
        </div>

        {error && (
          <p
            id={`${selectId}-error`}
            className="mt-1 text-sm text-[var(--status-danger)] flex items-center gap-1"
          >
            <svg className="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
              <path fillRule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clipRule="evenodd" />
            </svg>
            {error}
          </p>
        )}

        {hint && !error && (
          <p id={`${selectId}-hint`} className="mt-1 text-sm text-[var(--text-secondary)]">
            {hint}
          </p>
        )}
      </div>
    );
  }
);

Select.displayName = 'Select';

export { Select };
