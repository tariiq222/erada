import * as React from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { cn } from '@shared/lib/utils';
// Phase 4C — direct leaf import (not via @shared/ui barrel). The
// barrel re-exports DataTable and Pagination; importing Pagination
// through it would route DataTable's chunk through the same chunk
// as the barrel, creating a circular chunk shape on every code
// split. Direct leaf imports keep the chunk graph acyclic.
import { Pagination } from './Pagination';
import {type LucideIcon} from '@tabler/icons-react';

export interface DataTableColumn<T> {
  key: string;
  header: React.ReactNode;
  render: (row: T) => React.ReactNode;
  align?: 'start' | 'center' | 'end';
  width?: string;
  cellClassName?: string;
  headerClassName?: string;
  /** أخفِ العمود تحت نقطة كسر معيّنة لتكييف الكثافة على الشاشات الصغيرة. */
  hideBelow?: 'sm' | 'md' | 'lg';
}

export interface DataTablePagination {
  currentPage: number;
  lastPage: number;
  total: number;
  onPageChange: (page: number) => void;
}

export interface DataTableEmpty {
  icon?: LucideIcon;
  title: string;
  description?: string;
  action?: React.ReactNode;
}

export interface DataTableProps<T> {
  columns: DataTableColumn<T>[];
  data: T[];
  rowKey: (row: T) => string | number;
  loading?: boolean;
  /** يجعل الصف بالكامل قابلاً للنقر للانتقال (الإجراءات تبقى مستقلة). */
  rowHref?: (row: T) => string;
  /** عمود إجراءات يظهر عند المرور على الصف (إظهار تدريجي). */
  actions?: (row: T) => React.ReactNode;
  /** شريط أدوات (بحث/فلاتر) مدمج أعلى الجدول. */
  toolbar?: React.ReactNode;
  pagination?: DataTablePagination;
  empty?: DataTableEmpty;
  skeletonRows?: number;
  minWidth?: string;
  className?: string;
  /** وصف الجدول لقارئ الشاشة (يُعرض كـ caption مخفي بصرياً). */
  caption?: string;
}

const alignClass = {
  start: 'text-start',
  center: 'text-center',
  end: 'text-end',
} as const;

const hideClass = {
  sm: 'hidden sm:table-cell',
  md: 'hidden md:table-cell',
  lg: 'hidden lg:table-cell',
} as const;

function EmptyBlock({ empty }: { empty?: DataTableEmpty }) {
  const { t } = useTranslation();
  if (!empty) {
    return (
      <div className="px-6 py-16 text-center text-sm text-[var(--text-tertiary)]">
        {t('common.no_data', { defaultValue: 'لا توجد بيانات' })}
      </div>
    );
  }
  const Icon = empty.icon;
  return (
    <div className="flex flex-col items-center gap-3 px-6 py-16 text-center">
      {Icon && (
        <div className="grid h-12 w-12 place-items-center rounded-xl bg-[var(--surface-muted)] text-[var(--text-tertiary)]">
          <Icon className="h-6 w-6" />
        </div>
      )}
      <div className="max-w-sm">
        <h3 className="text-base font-semibold text-[var(--text-primary)]">{empty.title}</h3>
        {empty.description && (
          <p className="mt-1 text-sm leading-relaxed text-[var(--text-tertiary)]">
            {empty.description}
          </p>
        )}
      </div>
      {empty.action && <div className="mt-1">{empty.action}</div>}
    </div>
  );
}

/**
 * جدول بيانات موحّد للصفحات الداخلية.
 * حاوية واحدة تجمع: شريط أدوات + رأس خفيف + صفوف بإظهار إجراءات تدريجي
 * + حالات تحميل/فراغ موحّدة + ترقيم مدمج. كل الألوان من tokens (dark-safe).
 */
export function DataTable<T,>({
  columns,
  data,
  rowKey,
  loading = false,
  rowHref,
  actions,
  toolbar,
  pagination,
  empty,
  skeletonRows = 6,
  minWidth = '720px',
  className,
  caption,
}: DataTableProps<T>) {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const colCount = columns.length + (actions ? 1 : 0);

  return (
    <div
      className={cn(
        'overflow-hidden rounded-lg border border-[var(--border-default)] bg-[var(--surface-base)]',
        className
      )}
    >
      {toolbar && (
        <div className="border-b border-[var(--border-default)] p-3">{toolbar}</div>
      )}

      <div
        className="overflow-x-auto"
        tabIndex={0}
        role="region"
        aria-label={caption || t('common.data_table', { defaultValue: 'جدول البيانات' })}
      >
        <table className="w-full border-collapse text-sm" style={{ minWidth }}>
          {caption && <caption className="sr-only">{caption}</caption>}
          <thead>
            <tr className="bg-[var(--surface-subtle)]">
              {columns.map((col) => (
                <th
                  key={col.key}
                  scope="col"
                  className={cn(
                    'h-11 whitespace-nowrap px-4 text-start align-middle text-xs font-semibold text-[var(--text-tertiary)]',
                    col.align && alignClass[col.align],
                    col.width,
                    col.hideBelow && hideClass[col.hideBelow],
                    col.headerClassName
                  )}
                >
                  {col.header}
                </th>
              ))}
              {actions && <th className="h-11 w-px px-4" aria-hidden />}
            </tr>
          </thead>
          <tbody>
            {loading ? (
              Array.from({ length: skeletonRows }).map((_, i) => (
                <tr key={i} className="border-t border-[var(--border-default)]">
                  {Array.from({ length: colCount }).map((__, j) => (
                    <td key={j} className="px-4 py-3">
                      <div
                        className="h-4 animate-pulse rounded bg-[var(--surface-muted)]"
                        style={{ width: j === 0 ? '65%' : '45%' }}
                      />
                    </td>
                  ))}
                </tr>
              ))
            ) : data.length === 0 ? (
              <tr>
                <td colSpan={colCount} className="p-0">
                  <EmptyBlock empty={empty} />
                </td>
              </tr>
            ) : (
              data.map((row) => {
                const href = rowHref?.(row);
                return (
                  <tr
                    key={rowKey(row)}
                    onClick={href ? () => navigate(href) : undefined}
                    onKeyDown={
                      href
                        ? (e) => {
                            if (e.key === 'Enter' || e.key === ' ') {
                              e.preventDefault();
                              navigate(href);
                            }
                          }
                        : undefined
                    }
                    tabIndex={href ? 0 : undefined}
                    className={cn(
                      'group border-t border-[var(--border-default)] transition-colors hover:bg-[var(--surface-muted)]',
                      href &&
                        'cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-[var(--accent-default)]'
                    )}
                  >
                    {columns.map((col) => (
                      <td
                        key={col.key}
                        className={cn(
                          'px-4 py-3 align-middle text-[var(--text-primary)]',
                          col.align && alignClass[col.align],
                          col.hideBelow && hideClass[col.hideBelow],
                          col.cellClassName
                        )}
                      >
                        {col.render(row)}
                      </td>
                    ))}
                    {actions && (
                      <td
                        className="px-4 py-3 text-end"
                        onClick={(e) => e.stopPropagation()}
                      >
                        <div className="flex items-center justify-end gap-0 opacity-100 transition-opacity sm:opacity-0 sm:group-hover:opacity-100 sm:group-focus-within:opacity-100">
                          {actions(row)}
                        </div>
                      </td>
                    )}
                  </tr>
                );
              })
            )}
          </tbody>
        </table>
      </div>

      {pagination && pagination.lastPage > 1 && !loading && (
        <div className="flex flex-col items-center justify-between gap-2 border-t border-[var(--border-default)] px-4 py-3 sm:flex-row">
          <p className="text-xs text-[var(--text-tertiary)]">
            {t('common.total', { defaultValue: 'الإجمالي' })}:{' '}
            <span className="font-semibold text-[var(--text-secondary)]">{pagination.total}</span>
          </p>
          <Pagination
            currentPage={pagination.currentPage}
            totalPages={pagination.lastPage}
            onPageChange={pagination.onPageChange}
          />
        </div>
      )}
    </div>
  );
}

export interface RowActionProps {
  icon: LucideIcon;
  label: string;
  to?: string;
  onClick?: () => void;
  tone?: 'default' | 'danger';
}

/** زر إجراء مضغوط داخل صف الجدول (أيقونة فقط مع tooltip/aria-label). */
export const RowAction: React.FC<RowActionProps> = ({
  icon: Icon,
  label,
  to,
  onClick,
  tone = 'default',
}) => {
  const cls = cn(
    'relative grid h-8 w-8 shrink-0 place-items-center rounded-md text-[var(--text-tertiary)] transition-colors',
    'before:absolute before:inset-[-6px] before:content-[""]',
    'focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--accent-default)]',
    tone === 'danger'
      ? 'hover:bg-[var(--status-danger-subtle)] hover:text-[var(--status-danger)]'
      : 'hover:bg-[var(--accent-subtle)] hover:text-[var(--accent-default)]'
  );
  if (to) {
    return (
      <Link to={to} className={cls} title={label} aria-label={label}>
        <Icon className="h-4 w-4" />
      </Link>
    );
  }
  return (
    <button type="button" onClick={onClick} className={cls} title={label} aria-label={label}>
      <Icon className="h-4 w-4" />
    </button>
  );
};

export default DataTable;
