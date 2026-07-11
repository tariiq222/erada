import * as React from 'react';
import { useTranslation } from 'react-i18next';
import {IconSearch, IconX} from '@tabler/icons-react';
import { cn } from '@shared/lib/utils';
// Phase 4C — direct leaf import (not via @shared/ui barrel).
import { Input } from './Input';

export interface FilterBarProps {
  search: string;
  onSearchChange: (value: string) => void;
  searchPlaceholder?: string;
  /** عناصر الفلترة (Select... إلخ). تأخذ عرضاً موحّداً. */
  children?: React.ReactNode;
  hasActiveFilters?: boolean;
  onClear?: () => void;
  className?: string;
}

/**
 * شريط أدوات موحّد فوق الجدول: بحث فوري + فلاتر + مسح.
 * بحث وفلاتر مجمّعة على البداية (يمين في RTL)، وزر المسح يُدفع للطرف المقابل.
 */
export const FilterBar: React.FC<FilterBarProps> = ({
  search,
  onSearchChange,
  searchPlaceholder,
  children,
  hasActiveFilters,
  onClear,
  className,
}) => {
  const { t } = useTranslation();
  return (
    <div
      className={cn(
        'flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between sm:gap-3',
        className
      )}
    >
      <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:gap-3">
        <div className="w-full sm:w-64 lg:w-72">
          <Input
            value={search}
            onChange={(e) => onSearchChange(e.target.value)}
            placeholder={searchPlaceholder ?? t('common.search', { defaultValue: 'بحث' })}
            leftIcon={<IconSearch className="h-4 w-4" />}
          />
        </div>
        {children && (
          <div className="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap sm:items-center [&>*]:w-full sm:[&>*]:w-40">
            {children}
          </div>
        )}
      </div>
      {hasActiveFilters && onClear && (
        <button
          type="button"
          onClick={onClear}
          className="inline-flex shrink-0 items-center gap-1 self-start rounded-md px-2 py-2 text-sm font-medium text-[var(--text-tertiary)] transition-colors hover:bg-[var(--surface-muted)] hover:text-[var(--text-primary)] sm:self-auto"
        >
          <IconX className="h-4 w-4" />
          {t('common.clear', { defaultValue: 'مسح' })}
        </button>
      )}
    </div>
  );
};

export default FilterBar;
