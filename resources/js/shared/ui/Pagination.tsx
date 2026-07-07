import * as React from 'react';
import { useTranslation } from 'react-i18next';
import { cn } from '@shared/lib/utils';
import {IconChevronLeft, IconChevronRight, IconDots} from '@tabler/icons-react';

export interface PaginationProps {
  currentPage: number;
  totalPages: number;
  onPageChange: (page: number) => void;
  siblingCount?: number;
  className?: string;
}

function range(start: number, end: number): number[] {
  const length = end - start + 1;
  return Array.from({ length }, (_, idx) => idx + start);
}

const Pagination: React.FC<PaginationProps> = ({
  currentPage,
  totalPages,
  onPageChange,
  siblingCount = 1,
  className,
}) => {
  const { t } = useTranslation();
  const paginationRange = React.useMemo(() => {
    const totalPageNumbers = siblingCount + 5;

    if (totalPageNumbers >= totalPages) {
      return range(1, totalPages);
    }

    const leftSiblingIndex = Math.max(currentPage - siblingCount, 1);
    const rightSiblingIndex = Math.min(currentPage + siblingCount, totalPages);

    const shouldShowLeftDots = leftSiblingIndex > 2;
    const shouldShowRightDots = rightSiblingIndex < totalPages - 2;

    const firstPageIndex = 1;
    const lastPageIndex = totalPages;

    if (!shouldShowLeftDots && shouldShowRightDots) {
      const leftItemCount = 3 + 2 * siblingCount;
      const leftRange = range(1, leftItemCount);
      return [...leftRange, 'dots', totalPages];
    }

    if (shouldShowLeftDots && !shouldShowRightDots) {
      const rightItemCount = 3 + 2 * siblingCount;
      const rightRange = range(totalPages - rightItemCount + 1, totalPages);
      return [firstPageIndex, 'dots', ...rightRange];
    }

    if (shouldShowLeftDots && shouldShowRightDots) {
      const middleRange = range(leftSiblingIndex, rightSiblingIndex);
      return [firstPageIndex, 'dots', ...middleRange, 'dots', lastPageIndex];
    }

    return range(1, totalPages);
  }, [totalPages, siblingCount, currentPage]);

  if (totalPages <= 1) return null;

  const baseButtonClass = cn(
    'inline-flex items-center justify-center rounded-lg text-sm font-medium transition-colors duration-200',
    'h-10 min-w-[2.5rem] px-3',
    'focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--accent-default)]'
  );

  return (
    <nav
      className={cn('flex items-center justify-center gap-1', className)}
      aria-label={t('common.pagination', { defaultValue: 'ترقيم الصفحات' })}
    >
      <button
        onClick={() => onPageChange(currentPage - 1)}
        disabled={currentPage === 1}
        className={cn(
          baseButtonClass,
          'text-[var(--text-secondary)] hover:bg-[var(--surface-subtle)]',
          'disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:bg-transparent'
        )}
        aria-label={t('common.previous_page', { defaultValue: 'الصفحة السابقة' })}
      >
        <IconChevronRight className="h-4 w-4 rtl:rotate-180" />
        <span className="hidden sm:inline ms-1">{t('common.previous')}</span>
      </button>

      {paginationRange.map((pageNumber, index) => {
        if (pageNumber === 'dots') {
          return (
            <span
              key={`dots-${index}`}
              className="inline-flex items-center justify-center h-10 w-10 text-[var(--text-tertiary)]"
            >
              <IconDots className="h-4 w-4" />
            </span>
          );
        }

        const isActive = pageNumber === currentPage;
        return (
          <button
            key={pageNumber}
            onClick={() => onPageChange(pageNumber as number)}
            className={cn(
              baseButtonClass,
              isActive
                ? 'bg-[var(--accent-default)] text-[var(--text-inverse)] shadow-sm'
                : 'text-[var(--text-secondary)] hover:bg-[var(--surface-subtle)]'
            )}
            aria-label={`${t('common.page', { defaultValue: 'صفحة' })} ${pageNumber}`}
            aria-current={isActive ? 'page' : undefined}
          >
            {pageNumber}
          </button>
        );
      })}

      <button
        onClick={() => onPageChange(currentPage + 1)}
        disabled={currentPage === totalPages}
        className={cn(
          baseButtonClass,
          'text-[var(--text-secondary)] hover:bg-[var(--surface-subtle)]',
          'disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:bg-transparent'
        )}
        aria-label={t('common.next_page', { defaultValue: 'الصفحة التالية' })}
      >
        <span className="hidden sm:inline me-1">{t('common.next')}</span>
        <IconChevronLeft className="h-4 w-4 rtl:rotate-180" />
      </button>
    </nav>
  );
};

Pagination.displayName = 'Pagination';

export { Pagination };
