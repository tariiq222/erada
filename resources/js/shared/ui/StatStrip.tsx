import * as React from 'react';
import { cn, formatNumber } from '@shared/lib/utils';

export type StatTone = 'neutral' | 'accent' | 'success' | 'warning' | 'danger';

export interface StatStripItem {
  label: string;
  value: number | string;
  tone?: StatTone;
}

export interface StatStripProps {
  items: StatStripItem[];
  className?: string;
  'data-testid'?: string;
}

const toneDot: Record<StatTone, string> = {
  neutral: 'bg-[var(--text-disabled)]',
  accent: 'bg-[var(--accent-default)]',
  success: 'bg-[var(--status-success)]',
  warning: 'bg-[var(--status-warning)]',
  danger: 'bg-[var(--status-danger)]',
};

/**
 * شريط إحصائيات مدمج — بديل عصري عن شبكة البطاقات المتطابقة.
 * حاوية واحدة مقسّمة بفواصل رفيعة؛ نقطة لون خافتة + رقم بارز + تسمية.
 * الأرقام محايدة اللون (هرمية بالوزن لا باللون)؛ اللون نادر ودلالي فقط.
 */
export const StatStrip: React.FC<StatStripProps> = ({ items, className, ...rest }) => (
  <div
    {...rest}
    className={cn(
      'grid grid-cols-2 overflow-hidden rounded-lg border border-[var(--border-default)] bg-[var(--surface-base)]',
      'sm:flex sm:divide-x sm:divide-x-reverse sm:divide-[var(--border-default)]',
      className
    )}
  >
    {items.map((item, i) => (
      <div
        key={i}
        className={cn(
          'flex flex-col gap-1 px-4 py-3 sm:flex-1',
          i % 2 === 1 && 'border-s border-[var(--border-default)] sm:border-s-0',
          i >= 2 && 'border-t border-[var(--border-default)] sm:border-t-0'
        )}
      >
        <div className="flex items-center gap-2">
          <span className={cn('h-1.5 w-1.5 shrink-0 rounded-full', toneDot[item.tone ?? 'neutral'])} />
          <span className="truncate text-xs font-medium text-[var(--text-tertiary)]">
            {item.label}
          </span>
        </div>
        <span className="text-2xl font-bold leading-none text-[var(--text-primary)]">
          {item.value === null || item.value === undefined
            ? '—'
            : typeof item.value === 'number'
            ? formatNumber(item.value)
            : item.value}
        </span>
      </div>
    ))}
  </div>
);

export default StatStrip;
