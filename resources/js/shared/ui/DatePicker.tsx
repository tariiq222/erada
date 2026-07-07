import * as React from 'react';
import { useState, useRef, useEffect, useCallback } from 'react';
import { createPortal } from 'react-dom';
import { cn } from '@shared/lib/utils';
import {IconCalendar, IconChevronRight, IconChevronLeft, IconX} from '@tabler/icons-react';
import { FieldError } from './FieldError';
import { RequiredIndicator } from './RequiredIndicator';

export interface DatePickerProps {
  value?: string;
  onChange: (value: string) => void;
  minDate?: string;
  maxDate?: string;
  label?: string;
  error?: string;
  hint?: string;
  placeholder?: string;
  disabled?: boolean;
  required?: boolean;
  className?: string;
  id?: string;
  title?: string;
  'aria-label'?: string;
  'aria-labelledby'?: string;
  'aria-describedby'?: string;
  'aria-invalid'?: React.AriaAttributes['aria-invalid'];
  'aria-required'?: React.AriaAttributes['aria-required'];
}

const ARABIC_MONTHS = [
  'يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو',
  'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'
];

const ARABIC_DAYS = ['أحد', 'إثن', 'ثلا', 'أرب', 'خمي', 'جمع', 'سبت'];

const DatePicker: React.FC<DatePickerProps> = ({
  value,
  onChange,
  minDate,
  maxDate,
  label,
  error,
  hint,
  placeholder = 'اختر التاريخ',
  disabled = false,
  required = false,
  className,
  id,
  title,
  'aria-label': ariaLabel,
  'aria-labelledby': ariaLabelledBy,
  'aria-describedby': ariaDescribedBy,
  'aria-invalid': ariaInvalid,
  'aria-required': ariaRequired,
}) => {
  const [isOpen, setIsOpen] = useState(false);
  const [currentMonth, setCurrentMonth] = useState(new Date());
  const [showYearPicker, setShowYearPicker] = useState(false);
  const [showMonthPicker, setShowMonthPicker] = useState(false);
  const [dropdownStyle, setDropdownStyle] = useState<React.CSSProperties>({});
  const containerRef = useRef<HTMLDivElement>(null);
  const buttonRef = useRef<HTMLButtonElement>(null);
  const dropdownRef = useRef<HTMLDivElement>(null);
  const generatedId = React.useId();
  const inputId = id ?? generatedId;
  const dropdownId = `${inputId}-dialog`;

  // Parse dates
  const selectedDate = value ? new Date(value) : null;
  const min = minDate ? new Date(minDate) : null;
  const max = maxDate ? new Date(maxDate) : null;

  // Initialize current month based on value or constraints
  useEffect(() => {
    if (selectedDate) {
      setCurrentMonth(new Date(selectedDate.getFullYear(), selectedDate.getMonth(), 1));
    } else if (min) {
      setCurrentMonth(new Date(min.getFullYear(), min.getMonth(), 1));
    } else {
      setCurrentMonth(new Date(new Date().getFullYear(), new Date().getMonth(), 1));
    }
  }, [value, minDate]);

  // Compute dropdown position when opening
  const positionDropdown = useCallback(() => {
    if (!buttonRef.current) return;
    const rect = buttonRef.current.getBoundingClientRect();
    const viewportPadding = 12;
    const dropdownHeight = 340;
    const availableWidth = Math.max(window.innerWidth - viewportPadding * 2, 240);
    const width = Math.min(Math.max(rect.width, 264), availableWidth);
    const left = Math.min(
      Math.max(rect.left, viewportPadding),
      Math.max(window.innerWidth - width - viewportPadding, viewportPadding),
    );
    const spaceBelow = Math.max(window.innerHeight - rect.bottom - viewportPadding - 4, 0);
    const spaceAbove = Math.max(rect.top - viewportPadding - 4, 0);
    const placeBelow = spaceBelow >= dropdownHeight || spaceBelow >= spaceAbove;
    const maxHeight = placeBelow ? spaceBelow : spaceAbove;
    const baseStyle: React.CSSProperties = { position: 'fixed', left, width, maxHeight, zIndex: 9999 };

    if (placeBelow) {
      setDropdownStyle({ ...baseStyle, top: rect.bottom + 4 });
    } else {
      setDropdownStyle({ ...baseStyle, bottom: window.innerHeight - rect.top + 4 });
    }
  }, []);

  const openDropdown = () => {
    positionDropdown();
    setIsOpen(true);
  };

  useEffect(() => {
    if (!isOpen) return;

    window.addEventListener('resize', positionDropdown);
    window.addEventListener('scroll', positionDropdown, true);

    return () => {
      window.removeEventListener('resize', positionDropdown);
      window.removeEventListener('scroll', positionDropdown, true);
    };
  }, [isOpen, positionDropdown]);

  // Close on click outside
  useEffect(() => {
    if (!isOpen) return;

    const handleClickOutside = (event: MouseEvent) => {
      const target = event.target as Node;
      const inContainer = containerRef.current?.contains(target);
      const inDropdown = dropdownRef.current?.contains(target);
      if (!inContainer && !inDropdown) {
        setIsOpen(false);
        setShowYearPicker(false);
        setShowMonthPicker(false);
      }
    };

    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, [isOpen]);

  // Close on Escape
  useEffect(() => {
    if (!isOpen) return;

    const handleEscape = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        setIsOpen(false);
        setShowYearPicker(false);
        setShowMonthPicker(false);
      }
    };

    document.addEventListener('keydown', handleEscape);
    return () => document.removeEventListener('keydown', handleEscape);
  }, [isOpen]);

  const isDateDisabled = useCallback((date: Date): boolean => {
    // Normalize to start of day for comparison
    const normalizedDate = new Date(date.getFullYear(), date.getMonth(), date.getDate());

    if (min) {
      const normalizedMin = new Date(min.getFullYear(), min.getMonth(), min.getDate());
      if (normalizedDate < normalizedMin) return true;
    }

    if (max) {
      const normalizedMax = new Date(max.getFullYear(), max.getMonth(), max.getDate());
      if (normalizedDate > normalizedMax) return true;
    }

    return false;
  }, [min, max]);

  const isDateSelected = useCallback((date: Date): boolean => {
    if (!selectedDate) return false;
    return (
      date.getFullYear() === selectedDate.getFullYear() &&
      date.getMonth() === selectedDate.getMonth() &&
      date.getDate() === selectedDate.getDate()
    );
  }, [selectedDate]);

  const isToday = useCallback((date: Date): boolean => {
    const today = new Date();
    return (
      date.getFullYear() === today.getFullYear() &&
      date.getMonth() === today.getMonth() &&
      date.getDate() === today.getDate()
    );
  }, []);

  const getDaysInMonth = useCallback((date: Date): (Date | null)[] => {
    const year = date.getFullYear();
    const month = date.getMonth();
    const firstDay = new Date(year, month, 1);
    const lastDay = new Date(year, month + 1, 0);
    const daysInMonth = lastDay.getDate();
    const startDayOfWeek = firstDay.getDay(); // 0 = Sunday

    const days: (Date | null)[] = [];

    // Add empty slots for days before the first day of month
    for (let i = 0; i < startDayOfWeek; i++) {
      days.push(null);
    }

    // Add all days of the month
    for (let day = 1; day <= daysInMonth; day++) {
      days.push(new Date(year, month, day));
    }

    return days;
  }, []);

  const handleSelectDate = (date: Date) => {
    if (isDateDisabled(date)) return;

    // Format as YYYY-MM-DD
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    onChange(`${year}-${month}-${day}`);
    setIsOpen(false);
  };

  const handlePrevMonth = () => {
    const newMonth = new Date(currentMonth.getFullYear(), currentMonth.getMonth() - 1, 1);

    // Check if we can go back (at least one day should be valid)
    if (min) {
      const minMonth = new Date(min.getFullYear(), min.getMonth(), 1);
      if (newMonth < minMonth) return;
    }

    setCurrentMonth(newMonth);
  };

  const handleNextMonth = () => {
    const newMonth = new Date(currentMonth.getFullYear(), currentMonth.getMonth() + 1, 1);

    // Check if we can go forward (at least one day should be valid)
    if (max) {
      const maxMonth = new Date(max.getFullYear(), max.getMonth(), 1);
      if (newMonth > maxMonth) return;
    }

    setCurrentMonth(newMonth);
  };

  const canGoPrevMonth = useCallback((): boolean => {
    if (!min) return true;
    const prevMonth = new Date(currentMonth.getFullYear(), currentMonth.getMonth() - 1, 1);
    const minMonth = new Date(min.getFullYear(), min.getMonth(), 1);
    return prevMonth >= minMonth;
  }, [currentMonth, min]);

  const canGoNextMonth = useCallback((): boolean => {
    if (!max) return true;
    const nextMonth = new Date(currentMonth.getFullYear(), currentMonth.getMonth() + 1, 1);
    const maxMonth = new Date(max.getFullYear(), max.getMonth(), 1);
    return nextMonth <= maxMonth;
  }, [currentMonth, max]);

  const handleClear = (e: React.MouseEvent<HTMLButtonElement>) => {
    e.stopPropagation();
    onChange('');
  };

  const handleToday = () => {
    const today = new Date();
    if (!isDateDisabled(today)) {
      handleSelectDate(today);
    }
  };

  // حساب نطاق السنوات المتاحة
  const getYearRange = useCallback((): number[] => {
    const currentYear = new Date().getFullYear();
    const minYear = min ? min.getFullYear() : currentYear - 10;
    const maxYear = max ? max.getFullYear() : currentYear + 10;
    const years: number[] = [];
    for (let year = minYear; year <= maxYear; year++) {
      years.push(year);
    }
    return years;
  }, [min, max]);

  // التحقق من توفر الشهر
  const isMonthAvailable = useCallback((monthIndex: number): boolean => {
    const year = currentMonth.getFullYear();
    const monthStart = new Date(year, monthIndex, 1);
    const monthEnd = new Date(year, monthIndex + 1, 0);

    if (min && monthEnd < new Date(min.getFullYear(), min.getMonth(), 1)) {
      return false;
    }
    if (max && monthStart > new Date(max.getFullYear(), max.getMonth() + 1, 0)) {
      return false;
    }
    return true;
  }, [currentMonth, min, max]);

  // التحقق من توفر السنة
  const isYearAvailable = useCallback((year: number): boolean => {
    if (min && year < min.getFullYear()) return false;
    if (max && year > max.getFullYear()) return false;
    return true;
  }, [min, max]);

  const handleYearSelect = (year: number) => {
    setCurrentMonth(new Date(year, currentMonth.getMonth(), 1));
    setShowYearPicker(false);
  };

  const handleMonthSelect = (monthIndex: number) => {
    setCurrentMonth(new Date(currentMonth.getFullYear(), monthIndex, 1));
    setShowMonthPicker(false);
  };

  const formatDisplayDate = (dateStr: string): string => {
    if (!dateStr) return '';
    const date = new Date(dateStr);
    const day = date.getDate();
    const month = ARABIC_MONTHS[date.getMonth()];
    const year = date.getFullYear();
    return `${day} ${month} ${year}`;
  };

  const formatDateLabel = (date: Date): string => (
    `${date.getDate()} ${ARABIC_MONTHS[date.getMonth()]} ${date.getFullYear()}`
  );

  const days = isOpen ? getDaysInMonth(currentMonth) : [];

  return (
    <div className="w-full" ref={containerRef}>
      {label && (
        <label
          htmlFor={inputId}
          className="block text-sm font-medium text-[var(--text-secondary)] mb-1"
        >
          {label}
          {required && <RequiredIndicator className="ms-1" />}
        </label>
      )}

      <div className="relative">
        {/* Input Field */}
        <button
          type="button"
          ref={buttonRef}
          id={inputId}
          title={title}
          onClick={() => !disabled && (isOpen ? setIsOpen(false) : openDropdown())}
          disabled={disabled}
          className={cn(
            'w-full flex min-h-9 items-center justify-between gap-2 px-3 py-2 text-sm rounded-md',
            'bg-[var(--surface-base)] text-[var(--text-primary)]',
            'border transition-colors duration-150',
            'focus:outline-none focus:ring-2 focus:ring-offset-0',
            'disabled:bg-[var(--surface-muted)] disabled:text-[var(--text-disabled)] disabled:cursor-not-allowed',
            error
              ? 'border-[var(--status-danger)] focus:border-[var(--status-danger)] focus:ring-[var(--status-danger-subtle)]'
              : 'border-[var(--border-default)] hover:border-[var(--border-strong)] focus:border-[var(--accent-default)] focus:ring-[var(--accent-subtle)]',
            isOpen && 'border-[var(--accent-default)] ring-2 ring-[var(--accent-subtle)]',
            className
          )}
          aria-label={ariaLabel}
          aria-labelledby={ariaLabelledBy}
          aria-invalid={ariaInvalid ?? (error ? 'true' : 'false')}
          aria-describedby={ariaDescribedBy ?? (error ? `${inputId}-error` : undefined)}
          aria-required={ariaRequired}
          aria-haspopup="dialog"
          aria-expanded={isOpen}
          aria-controls={isOpen ? dropdownId : undefined}
        >
          <div className="flex items-center gap-2">
            <IconCalendar className="h-4 w-4 text-[var(--text-tertiary)]" />
            <span className={cn(!value && 'text-[var(--text-secondary)]')}>
              {value ? formatDisplayDate(value) : placeholder}
            </span>
          </div>
          {value && !disabled && <span className="w-9 shrink-0" aria-hidden="true" />}
        </button>

        {value && !disabled && (
          <button
            type="button"
            onClick={handleClear}
            aria-label="مسح التاريخ"
            className="absolute inset-y-0 end-0 my-auto flex h-9 w-9 items-center justify-center rounded transition-colors hover:bg-[var(--surface-muted)]"
          >
            <IconX className="h-3.5 w-3.5 text-[var(--text-tertiary)] hover:text-[var(--text-secondary)]" aria-hidden="true" />
          </button>
        )}

        {/* IconCalendar Dropdown */}
        {isOpen && createPortal(
          <div id={dropdownId} ref={dropdownRef} role="dialog" aria-label={label || placeholder} data-datepicker-portal style={dropdownStyle} className="w-full max-w-[calc(100vw-1.5rem)] bg-[var(--surface-base)] rounded-xl shadow-xl border border-[var(--border-default)] overflow-y-auto overflow-x-hidden animate-in fade-in-0 zoom-in-95 duration-150 motion-reduce:animate-none">
            {/* Header */}
            <div className="flex items-center justify-between px-2.5 py-1.5 bg-[var(--accent-subtle)] border-b border-[var(--border-default)]">
              <button
                type="button"
                onClick={handlePrevMonth}
                disabled={!canGoPrevMonth()}
                aria-label="الشهر السابق"
                className={cn(
                  'flex h-10 w-10 items-center justify-center rounded-lg transition-colors',
                  canGoPrevMonth()
                    ? 'hover:bg-[var(--surface-base)] text-[var(--text-secondary)]'
                    : 'text-[var(--text-disabled)] cursor-not-allowed'
                )}
              >
                <IconChevronRight className="h-4 w-4" aria-hidden="true" />
              </button>

              <div className="flex items-center gap-1">
                {/* Month Selector */}
                <button
                  type="button"
                  onClick={() => {
                    setShowMonthPicker(!showMonthPicker);
                    setShowYearPicker(false);
                  }}
                  aria-label={`اختيار الشهر، الشهر الحالي ${ARABIC_MONTHS[currentMonth.getMonth()]}`}
                  className="min-h-10 rounded-lg px-2.5 py-1.5 font-semibold text-[var(--text-primary)] hover:bg-[var(--surface-base)] transition-colors"
                >
                  {ARABIC_MONTHS[currentMonth.getMonth()]}
                </button>
                {/* Year Selector */}
                <button
                  type="button"
                  onClick={() => {
                    setShowYearPicker(!showYearPicker);
                    setShowMonthPicker(false);
                  }}
                  aria-label={`اختيار السنة، السنة الحالية ${currentMonth.getFullYear()}`}
                  className="min-h-10 rounded-lg px-2.5 py-1.5 text-[var(--text-secondary)] hover:bg-[var(--surface-base)] hover:text-[var(--text-primary)] transition-colors"
                >
                  {currentMonth.getFullYear()}
                </button>
              </div>

              <button
                type="button"
                onClick={handleNextMonth}
                disabled={!canGoNextMonth()}
                aria-label="الشهر التالي"
                className={cn(
                  'flex h-10 w-10 items-center justify-center rounded-lg transition-colors',
                  canGoNextMonth()
                    ? 'hover:bg-[var(--surface-base)] text-[var(--text-secondary)]'
                    : 'text-[var(--text-disabled)] cursor-not-allowed'
                )}
              >
                <IconChevronLeft className="h-4 w-4" aria-hidden="true" />
              </button>
            </div>

            {/* Year Picker */}
            {showYearPicker && (
              <div className="p-2.5 border-b border-[var(--border-default)] max-h-44 overflow-y-auto">
                <div className="grid grid-cols-4 gap-1">
                  {getYearRange().map((year) => {
                    const isAvailable = isYearAvailable(year);
                    const isSelected = year === currentMonth.getFullYear();
                    return (
                      <button
                        key={year}
                        type="button"
                        onClick={() => isAvailable && handleYearSelect(year)}
                        disabled={!isAvailable}
                        aria-label={`اختيار سنة ${year}`}
                        className={cn(
                          'min-h-10 py-1.5 px-1 rounded-lg text-sm font-medium transition-all',
                          !isAvailable && 'text-[var(--text-disabled)] cursor-not-allowed',
                          isAvailable && !isSelected && 'hover:bg-[var(--accent-subtle)] hover:text-[var(--accent-default)] text-[var(--text-primary)]',
                          isSelected && 'bg-[var(--accent-default)] text-[var(--text-inverse)]'
                        )}
                      >
                        {year}
                      </button>
                    );
                  })}
                </div>
              </div>
            )}

            {/* Month Picker */}
            {showMonthPicker && (
              <div className="p-2.5 border-b border-[var(--border-default)]">
                <div className="grid grid-cols-3 gap-1">
                  {ARABIC_MONTHS.map((month, index) => {
                    const isAvailable = isMonthAvailable(index);
                    const isSelected = index === currentMonth.getMonth();
                    return (
                      <button
                        key={month}
                        type="button"
                        onClick={() => isAvailable && handleMonthSelect(index)}
                        disabled={!isAvailable}
                        aria-label={`اختيار شهر ${month}`}
                        className={cn(
                          'min-h-10 py-1.5 px-1 rounded-lg text-sm font-medium transition-all',
                          !isAvailable && 'text-[var(--text-disabled)] cursor-not-allowed',
                          isAvailable && !isSelected && 'hover:bg-[var(--accent-subtle)] hover:text-[var(--accent-default)] text-[var(--text-primary)]',
                          isSelected && 'bg-[var(--accent-default)] text-[var(--text-inverse)]'
                        )}
                      >
                        {month}
                      </button>
                    );
                  })}
                </div>
              </div>
            )}

            {/* Days Grid */}
            <div className="p-2">
              {/* Week Days Header */}
              <div className="grid grid-cols-7 mb-1">
                {ARABIC_DAYS.map((day) => (
                  <div
                    key={day}
                    className="h-7 flex items-center justify-center text-xs font-medium text-[var(--text-tertiary)]"
                  >
                    {day}
                  </div>
                ))}
              </div>

              {/* Days */}
              <div className="grid grid-cols-7 gap-0">
                {days.map((date, index) => {
                  if (!date) {
                    return <div key={`empty-${index}`} className="h-10" />;
                  }

                  const disabled = isDateDisabled(date);
                  const selected = isDateSelected(date);
                  const today = isToday(date);

                  return (
                    <button
                      key={date.toISOString()}
                      type="button"
                      onClick={() => !disabled && handleSelectDate(date)}
                      disabled={disabled}
                      aria-label={`اختيار تاريخ ${formatDateLabel(date)}`}
                      className={cn(
                        'h-10 w-full rounded-lg text-sm font-medium transition-all duration-150',
                        'focus:outline-none focus:ring-2 focus:ring-[var(--accent-default)] focus:ring-offset-1',
                        disabled && 'text-[var(--text-disabled)] cursor-not-allowed line-through',
                        !disabled && !selected && 'hover:bg-[var(--accent-subtle)] hover:text-[var(--accent-default)]',
                        selected && 'bg-[var(--accent-default)] text-[var(--text-inverse)] hover:bg-[var(--accent-hover)]',
                        today && !selected && 'ring-1 ring-[var(--accent-default)] text-[var(--accent-default)]',
                        !disabled && !selected && !today && 'text-[var(--text-primary)]'
                      )}
                    >
                      {date.getDate()}
                    </button>
                  );
                })}
              </div>
            </div>

            {/* Footer */}
            <div className="flex items-center justify-between px-2.5 py-1.5 bg-[var(--surface-subtle)] border-t border-[var(--border-default)]">
              <button
                type="button"
                onClick={handleToday}
                disabled={isDateDisabled(new Date())}
                aria-label="اختيار تاريخ اليوم"
                className={cn(
                  'min-h-10 px-2.5 text-xs font-medium transition-colors',
                  isDateDisabled(new Date())
                    ? 'text-[var(--text-disabled)] cursor-not-allowed'
                    : 'text-[var(--accent-default)] hover:text-[var(--accent-hover)]'
                )}
              >
                اليوم
              </button>
              <button
                type="button"
                onClick={handleClear}
                aria-label="مسح التاريخ"
                className="min-h-10 px-2.5 text-xs text-[var(--text-tertiary)] hover:text-[var(--text-secondary)] transition-colors"
              >
                مسح
              </button>
            </div>
          </div>,
          document.body
        )}
      </div>

      {/* Error & Hint Messages */}
      <FieldError id={`${inputId}-error`}>{error}</FieldError>
      {hint && !error && (
        <p className="mt-1 text-sm text-[var(--text-secondary)]">
          {hint}
        </p>
      )}
    </div>
  );
};

DatePicker.displayName = 'DatePicker';

export { DatePicker };
