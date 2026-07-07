import * as React from 'react';
import { cn } from '@shared/lib/utils';
import {IconChevronUp, IconChevronDown, IconSelector} from '@tabler/icons-react';

export interface TableProps extends React.HTMLAttributes<HTMLTableElement> {
  striped?: boolean;
  hoverable?: boolean;
}

const Table = React.forwardRef<HTMLTableElement, TableProps>(
  ({ className, striped, hoverable, ...props }, ref) => (
    <div className="w-full overflow-x-auto rounded-lg sm:rounded-xl border border-[var(--border-default)] scrollbar-thin scrollbar-thumb-[var(--border-strong)] scrollbar-track-transparent">
      <table
        ref={ref}
        className={cn(
          'w-full caption-bottom text-xs sm:text-sm min-w-[600px] bg-[var(--surface-base)]',
          striped && '[&_tbody_tr:nth-child(odd)]:bg-[var(--surface-subtle)]',
          hoverable && '[&_tbody_tr:hover]:bg-[var(--surface-subtle)]',
          className
        )}
        {...props}
      />
    </div>
  )
);

Table.displayName = 'Table';

const TableHeader = React.forwardRef<
  HTMLTableSectionElement,
  React.HTMLAttributes<HTMLTableSectionElement>
>(({ className, ...props }, ref) => (
  <thead
    ref={ref}
    className={cn('bg-[var(--surface-subtle)] border-b border-[var(--border-default)]', className)}
    {...props}
  />
));

TableHeader.displayName = 'TableHeader';

const TableBody = React.forwardRef<
  HTMLTableSectionElement,
  React.HTMLAttributes<HTMLTableSectionElement>
>(({ className, ...props }, ref) => (
  <tbody
    ref={ref}
    className={cn('[&_tr:last-child]:border-0', className)}
    {...props}
  />
));

TableBody.displayName = 'TableBody';

const TableFooter = React.forwardRef<
  HTMLTableSectionElement,
  React.HTMLAttributes<HTMLTableSectionElement>
>(({ className, ...props }, ref) => (
  <tfoot
    ref={ref}
    className={cn('bg-[var(--surface-subtle)] border-t border-[var(--border-default)] font-medium', className)}
    {...props}
  />
));

TableFooter.displayName = 'TableFooter';

const TableRow = React.forwardRef<
  HTMLTableRowElement,
  React.HTMLAttributes<HTMLTableRowElement>
>(({ className, ...props }, ref) => (
  <tr
    ref={ref}
      className={cn(
        'border-b border-[var(--border-default)] transition-colors',
        className
      )}
    {...props}
  />
));

TableRow.displayName = 'TableRow';

export interface TableHeadProps
  extends React.ThHTMLAttributes<HTMLTableCellElement> {
  sortable?: boolean;
  sortDirection?: 'asc' | 'desc' | null;
  onSort?: () => void;
}

const TableHead = React.forwardRef<HTMLTableCellElement, TableHeadProps>(
  ({ className, sortable, sortDirection, onSort, children, ...props }, ref) => {
    const SortIcon = sortDirection === 'asc'
      ? IconChevronUp
      : sortDirection === 'desc'
        ? IconChevronDown
        : IconSelector;

    return (
      <th
        ref={ref}
        className={cn(
          'h-10 sm:h-12 px-2 sm:px-4 text-start align-middle font-semibold text-[var(--text-secondary)] whitespace-nowrap',
          sortable && 'cursor-pointer select-none hover:bg-[var(--surface-subtle)] transition-colors',
          className
        )}
        onClick={sortable ? onSort : undefined}
        {...props}
      >
        <div className="flex items-center gap-1 sm:gap-2">
          {children}
          {sortable && (
            <SortIcon
              className={cn(
                'h-3 w-3 sm:h-4 sm:w-4 shrink-0',
                sortDirection ? 'text-[var(--accent-default)]' : 'text-[var(--text-tertiary)]'
              )}
            />
          )}
        </div>
      </th>
    );
  }
);

TableHead.displayName = 'TableHead';

const TableCell = React.forwardRef<
  HTMLTableCellElement,
  React.TdHTMLAttributes<HTMLTableCellElement>
>(({ className, ...props }, ref) => (
  <td
    ref={ref}
    className={cn('px-2 sm:px-4 py-2 sm:py-3 align-middle text-[var(--text-primary)]', className)}
    {...props}
  />
));

TableCell.displayName = 'TableCell';

const TableCaption = React.forwardRef<
  HTMLTableCaptionElement,
  React.HTMLAttributes<HTMLTableCaptionElement>
>(({ className, ...props }, ref) => (
  <caption
    ref={ref}
    className={cn('mt-4 text-sm text-[var(--text-tertiary)]', className)}
    {...props}
  />
));

TableCaption.displayName = 'TableCaption';

export {
  Table,
  TableHeader,
  TableBody,
  TableFooter,
  TableHead,
  TableRow,
  TableCell,
  TableCaption,
};
