import * as React from 'react';
import { useTranslation } from 'react-i18next';
import {IconFilter, IconChevronDown} from '@tabler/icons-react';
import { cn } from '@shared/lib/utils';

export interface FilterButtonProps {
  isOpen: boolean;
  onClick: () => void;
  activeCount?: number;
  className?: string;
}

const FilterButton = React.forwardRef<HTMLButtonElement, FilterButtonProps>(
  ({ isOpen, onClick, activeCount = 0, className }, ref) => {
    const { t } = useTranslation();
    const isActive = isOpen || activeCount > 0;

    return (
      <button
        ref={ref}
        type="button"
        onClick={onClick}
        aria-expanded={isOpen}
        aria-haspopup="true"
        className={cn(
          'flex items-center gap-1 px-3 py-1 rounded-lg border text-sm font-medium transition-colors duration-200',
          isActive
            ? 'bg-[var(--accent-subtle)] border-[var(--accent-default)] text-[var(--accent-default)]'
            : 'bg-[var(--surface-base)] border-[var(--border-default)] text-[var(--text-secondary)] hover:border-[var(--border-strong)] hover:bg-[var(--surface-subtle)]',
          className
        )}
      >
        <IconFilter className="h-4 w-4" />
        <span>{t('common.filter')}</span>
        {activeCount > 0 && (
          <span className="bg-[var(--accent-default)] text-[var(--text-inverse)] text-xs px-1 py-0 rounded-full min-w-[18px] text-center">
            {activeCount}
          </span>
        )}
        <IconChevronDown
          className={cn(
            'h-4 w-4 transition-transform duration-200',
            isOpen && 'rotate-180'
          )}
        />
      </button>
    );
  }
);

FilterButton.displayName = 'FilterButton';

export { FilterButton };
