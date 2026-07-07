import React from 'react';
import { useTranslation } from 'react-i18next';
import {IconCalendar, IconChevronDown} from '@tabler/icons-react';
import { DatePicker } from '@shared/ui';

export type DateRange = 'last7' | 'last30' | 'last90' | 'thisYear' | 'custom';

interface DateRangeFilterProps {
  value: DateRange;
  onChange: (range: DateRange, startDate?: string, endDate?: string) => void;
  customStartDate?: string;
  customEndDate?: string;
}

const rangeLabelKeys: Record<DateRange, string> = {
  last7: 'dashboard.last7',
  last30: 'dashboard.last30',
  last90: 'dashboard.last90',
  thisYear: 'dashboard.this_year',
  custom: 'dashboard.custom',
};

const CUSTOM_START_DATE_ID = 'dashboard-date-range-start';
const CUSTOM_END_DATE_ID = 'dashboard-date-range-end';

export const DateRangeFilter: React.FC<DateRangeFilterProps> = ({
  value,
  onChange,
  customStartDate,
  customEndDate,
}) => {
  const { t } = useTranslation();
  const [isOpen, setIsOpen] = React.useState(false);
  const [showCustomInputs, setShowCustomInputs] = React.useState(value === 'custom');
  const [localStartDate, setLocalStartDate] = React.useState(customStartDate || '');
  const [localEndDate, setLocalEndDate] = React.useState(customEndDate || '');
  const dropdownRef = React.useRef<HTMLDivElement>(null);

  React.useEffect(() => {
    const handleClickOutside = (event: MouseEvent) => {
      const target = event.target as Node;
      const startDateDialog = document.getElementById(`${CUSTOM_START_DATE_ID}-dialog`);
      const endDateDialog = document.getElementById(`${CUSTOM_END_DATE_ID}-dialog`);
      const inDatePickerPortal = target instanceof Element && target.closest('[data-datepicker-portal]');

      if (
        dropdownRef.current &&
        !dropdownRef.current.contains(target) &&
        !startDateDialog?.contains(target) &&
        !endDateDialog?.contains(target) &&
        !inDatePickerPortal
      ) {
        setIsOpen(false);
      }
    };
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  const calculateDates = (range: DateRange): { start: string; end: string } => {
    const now = new Date();
    const end = now.toISOString().split('T')[0];
    let start: Date;

    switch (range) {
      case 'last7':
        start = new Date(now);
        start.setDate(start.getDate() - 7);
        break;
      case 'last30':
        start = new Date(now);
        start.setDate(start.getDate() - 30);
        break;
      case 'last90':
        start = new Date(now);
        start.setDate(start.getDate() - 90);
        break;
      case 'thisYear':
        start = new Date(now.getFullYear(), 0, 1);
        break;
      default:
        start = new Date(now);
        start.setDate(start.getDate() - 30);
    }

    return {
      start: start.toISOString().split('T')[0],
      end,
    };
  };

  const handleRangeSelect = (range: DateRange) => {
    if (range === 'custom') {
      setShowCustomInputs(true);
    } else {
      setShowCustomInputs(false);
      const { start, end } = calculateDates(range);
      onChange(range, start, end);
      setIsOpen(false);
    }
  };

  const handleCustomApply = () => {
    if (localStartDate && localEndDate) {
      onChange('custom', localStartDate, localEndDate);
      setIsOpen(false);
    }
  };

  return (
    <div className="relative" ref={dropdownRef}>
      <button
        onClick={() => setIsOpen(!isOpen)}
        className="flex items-center gap-2 px-3 py-2 text-sm bg-[var(--surface-base)] border border-[var(--border-default)] rounded-lg hover:border-[var(--accent-default)] transition-colors"
      >
        <IconCalendar className="h-4 w-4 text-[var(--text-tertiary)]" />
        <span className="text-[var(--text-primary)]">{t(rangeLabelKeys[value])}</span>
        <IconChevronDown className={`h-4 w-4 text-[var(--text-tertiary)] transition-transform ${isOpen ? 'rotate-180' : ''}`} />
      </button>

      {isOpen && (
        <div className="absolute start-0 top-full mt-1 w-64 bg-[var(--surface-base)] border border-[var(--border-default)] rounded-lg shadow-lg z-50">
          <div className="p-2">
            {(Object.keys(rangeLabelKeys) as DateRange[]).map((range) => (
              <button
                key={range}
                onClick={() => handleRangeSelect(range)}
                className={`w-full text-start px-3 py-2 text-sm rounded-md transition-colors ${
                  value === range && !showCustomInputs
                    ? 'bg-[var(--accent-subtle)] text-[var(--accent-default)]'
                    : 'hover:bg-[var(--surface-muted)] text-[var(--text-primary)]'
                }`}
              >
                {t(rangeLabelKeys[range])}
              </button>
            ))}
          </div>

          {showCustomInputs && (
            <div className="border-t border-[var(--border-default)] p-3 space-y-3">
              <div>
                <label htmlFor={CUSTOM_START_DATE_ID} className="block text-xs text-[var(--text-tertiary)] mb-1">{t('common.from')}</label>
                <DatePicker
                  id={CUSTOM_START_DATE_ID}
                  value={localStartDate}
                  onChange={setLocalStartDate}
                  className="min-h-9 px-2 py-1 rounded-md"
                />
              </div>
              <div>
                <label htmlFor={CUSTOM_END_DATE_ID} className="block text-xs text-[var(--text-tertiary)] mb-1">{t('common.to')}</label>
                <DatePicker
                  id={CUSTOM_END_DATE_ID}
                  value={localEndDate}
                  onChange={setLocalEndDate}
                  className="min-h-9 px-2 py-1 rounded-md"
                />
              </div>
              <button
                onClick={handleCustomApply}
                disabled={!localStartDate || !localEndDate}
                className="w-full py-2 text-sm font-medium bg-[var(--accent-default)] text-[var(--text-inverse)] rounded-md hover:bg-[var(--accent-hover)] disabled:opacity-50 disabled:cursor-not-allowed"
              >
                {t('common.apply')}
              </button>
            </div>
          )}
        </div>
      )}
    </div>
  );
};

export default DateRangeFilter;
