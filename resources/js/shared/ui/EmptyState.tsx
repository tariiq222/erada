import * as React from 'react';
import { type LucideIcon } from '@tabler/icons-react';
import { cn } from '@shared/lib/utils';
import { type PageHeaderIconTone } from './PageHeader';

export type EmptyStateSize = 'sm' | 'md' | 'lg';

export interface EmptyStateProps {
  icon?: LucideIcon;
  title: React.ReactNode;
  description?: React.ReactNode;
  action?: React.ReactNode;
  iconTone?: PageHeaderIconTone;
  size?: EmptyStateSize;
  role?: string;
  className?: string;
}

const sizeClasses: Record<EmptyStateSize, {
  root: string;
  iconTile: string;
  icon: string;
  title: string;
  description: string;
}> = {
  sm: {
    root: 'py-6 gap-2',
    iconTile: 'h-9 w-9 rounded-[var(--radius-md)]',
    icon: 'h-4 w-4',
    title: 'text-sm',
    description: 'text-xs',
  },
  md: {
    root: 'py-8 gap-3',
    iconTile: 'h-11 w-11 rounded-[var(--radius-lg)]',
    icon: 'h-5 w-5',
    title: 'text-base',
    description: 'text-sm',
  },
  lg: {
    root: 'py-12 gap-3',
    iconTile: 'h-12 w-12 rounded-[var(--radius-lg)]',
    icon: 'h-6 w-6',
    title: 'text-lg',
    description: 'text-sm',
  },
};

const iconToneClasses: Record<PageHeaderIconTone, string> = {
  accent: 'bg-[var(--accent-subtle)] text-[var(--accent-text)]',
  neutral: 'bg-[var(--surface-muted)] text-[var(--text-secondary)]',
  success: 'bg-[var(--status-success-subtle)] text-[var(--status-success-text)]',
  warning: 'bg-[var(--status-warning-subtle)] text-[var(--status-warning-text)]',
  danger: 'bg-[var(--status-danger-subtle)] text-[var(--status-danger-text)]',
  info: 'bg-[var(--status-info-subtle)] text-[var(--status-info-text)]',
  project: 'bg-[var(--support-indigo-subtle)] text-[var(--support-indigo-text)]',
  task: 'bg-[var(--support-teal-subtle)] text-[var(--support-teal-text)]',
  risk: 'bg-[var(--support-amber-subtle)] text-[var(--support-amber-text)]',
  survey: 'bg-[var(--support-violet-subtle)] text-[var(--support-violet-text)]',
  admin: 'bg-[var(--support-violet-subtle)] text-[var(--support-violet-text)]',
};

/**
 * EmptyState — حالة فارغة موحّدة (لا توجد مهام / سجلات / مصروفات...).
 * أيقونة داخل مربع هادئ + عنوان + وصف + إجراء اختياري.
 */
export const EmptyState: React.FC<EmptyStateProps> = ({
  icon: Icon,
  title,
  description,
  action,
  iconTone = 'neutral',
  size = 'md',
  role = 'status',
  className,
}) => {
  const classes = sizeClasses[size];

  return (
    <div
      role={role}
      className={cn(
        'flex w-full flex-col items-center justify-center text-center',
        classes.root,
        className
      )}
    >
      {Icon && (
        <div
          className={cn(
            'grid shrink-0 place-items-center',
            classes.iconTile,
            iconToneClasses[iconTone]
          )}
        >
          <Icon className={classes.icon} strokeWidth={2} aria-hidden="true" />
        </div>
      )}
      <p
        className={cn(
          'font-[var(--weight-medium)] text-[var(--text-secondary)]',
          classes.title
        )}
      >
        {title}
      </p>
      {description && (
        <p
          className={cn(
            'max-w-md text-[var(--text-tertiary)]',
            classes.description
          )}
        >
          {description}
        </p>
      )}
      {action && <div className="mt-2">{action}</div>}
    </div>
  );
};

export default EmptyState;