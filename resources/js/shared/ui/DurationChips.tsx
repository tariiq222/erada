import { memo } from 'react';
import { useTranslation } from 'react-i18next';

export interface DurationOption {
  labelKey: string;
  days?: number;
  months?: number;
}

// Quick-pick presets. Project spans default to months; task/milestone spans to
// shorter day-based steps.
export const PROJECT_DURATIONS: DurationOption[] = [
  { labelKey: 'projects.dur_1m', months: 1 },
  { labelKey: 'projects.dur_3m', months: 3 },
  { labelKey: 'projects.dur_6m', months: 6 },
  { labelKey: 'projects.dur_1y', months: 12 },
];

export const TASK_DURATIONS: DurationOption[] = [
  { labelKey: 'projects.dur_3d', days: 3 },
  { labelKey: 'projects.dur_1w', days: 7 },
  { labelKey: 'projects.dur_2w', days: 14 },
  { labelKey: 'projects.dur_1m', months: 1 },
];

const pad = (n: number) => String(n).padStart(2, '0');
const toISO = (d: Date) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
const parseISO = (s: string) => {
  const [y, m, d] = s.split('-').map(Number);
  return new Date(y, m - 1, d);
};

// ponytail: month math via setMonth rolls e.g. Jan 31 + 1m into early March on
// short months. Acceptable for a duration shortcut — the user can fine-tune the
// resulting end date with the adjacent DatePicker if the edge case bites.
export const shiftDate = (iso: string, opt: DurationOption): string => {
  const d = parseISO(iso);
  if (opt.months) d.setMonth(d.getMonth() + opt.months);
  else d.setDate(d.getDate() + (opt.days ?? 0));
  return toISO(d);
};

export interface DurationChipsProps {
  startDate: string;
  endDate: string;
  /** Sets both the start (defaulting to today when empty) and the end date. */
  onApply: (startDate: string, endDate: string) => void;
  options?: DurationOption[];
  /** Start to use when no start date is set yet (e.g. parent milestone start). */
  fallbackStart?: string;
  disabled?: boolean;
  className?: string;
}

/**
 * A row of one-tap duration presets. Picking a preset fills the start date
 * (today by default) and derives the end date, so the common case avoids two
 * manual date-picker interactions.
 */
export const DurationChips = memo<DurationChipsProps>(({
  startDate,
  endDate,
  onApply,
  options = PROJECT_DURATIONS,
  fallbackStart,
  disabled = false,
  className,
}) => {
  const { t } = useTranslation();
  const activeKey = startDate && endDate
    ? options.find((o) => shiftDate(startDate, o) === endDate)?.labelKey
    : undefined;

  const apply = (opt: DurationOption) => {
    const base = startDate || fallbackStart || toISO(new Date());
    onApply(base, shiftDate(base, opt));
  };

  return (
    <div className={`flex flex-wrap items-center gap-1.5 ${className ?? ''}`}>
      <span className="text-xs text-[var(--text-tertiary)]">{t('projects.quick_duration')}:</span>
      {options.map((opt) => {
        const isActive = activeKey === opt.labelKey;
        return (
          <button
            key={opt.labelKey}
            type="button"
            disabled={disabled}
            onClick={() => apply(opt)}
            className={`rounded-full border px-2.5 py-1 text-xs font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-50 focus-visible:outline-none focus-visible:border-[var(--accent-default)] focus-visible:shadow-[0_0_0_2px_var(--accent-subtle)] ${
              isActive
                ? 'border-[var(--accent-default)] bg-[var(--accent-subtle)] text-[var(--accent-default)]'
                : 'border-[var(--border-default)] bg-[var(--surface-base)] text-[var(--text-secondary)] hover:border-[var(--accent-default)] hover:text-[var(--accent-default)]'
            }`}
          >
            {t(opt.labelKey)}
          </button>
        );
      })}
    </div>
  );
});

DurationChips.displayName = 'DurationChips';
