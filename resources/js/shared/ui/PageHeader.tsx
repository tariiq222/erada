import * as React from 'react';
import { cn } from '@shared/lib/utils';
import {type LucideIcon} from '@tabler/icons-react';

export type PageHeaderSize = 'default' | 'compact';
export type PageHeaderIconTone =
  | 'accent'
  | 'neutral'
  | 'success'
  | 'warning'
  | 'danger'
  | 'info'
  | 'project'
  | 'task'
  | 'risk'
  | 'survey'
  | 'admin';
export type PageHeaderIconVariant = 'subtle' | 'solid' | 'plain';

export interface PageHeaderProps {
  title: React.ReactNode;
  subtitle?: React.ReactNode;
  description?: React.ReactNode;
  breadcrumb?: React.ReactNode;
  back?: React.ReactNode;
  status?: React.ReactNode;
  metadata?: React.ReactNode;
  icon?: LucideIcon;
  actions?: React.ReactNode;
  size?: PageHeaderSize;
  iconTone?: PageHeaderIconTone;
  iconVariant?: PageHeaderIconVariant;
  className?: string;
}

const sizeClasses: Record<PageHeaderSize, {
  root: string;
  icon: string;
  title: string;
  description: string;
  metadata: string;
}> = {
  default: {
    root: 'gap-4',
    icon: 'h-11 w-11 rounded-[var(--radius-lg)]',
    title: 'text-[length:var(--text-h1)]',
    description: 'text-[length:var(--text-body)]',
    metadata: 'text-[length:var(--text-small)]',
  },
  compact: {
    root: 'gap-3',
    icon: 'h-9 w-9 rounded-[var(--radius-md)]',
    title: 'text-[length:var(--text-h2)]',
    description: 'text-[length:var(--text-small)]',
    metadata: 'text-[length:var(--text-caption)]',
  },
};

const iconToneClasses: Record<PageHeaderIconTone, Record<PageHeaderIconVariant, string>> = {
  accent: {
    subtle: 'bg-[var(--accent-subtle)] text-[var(--accent-text)]',
    solid: 'bg-[var(--accent-default)] text-[var(--text-inverse)]',
    plain: 'text-[var(--accent-text)]',
  },
  neutral: {
    subtle: 'bg-[var(--surface-muted)] text-[var(--text-secondary)]',
    solid: 'bg-[var(--text-secondary)] text-[var(--text-inverse)]',
    plain: 'text-[var(--text-secondary)]',
  },
  success: {
    subtle: 'bg-[var(--status-success-subtle)] text-[var(--status-success-text)]',
    solid: 'bg-[var(--status-success)] text-[var(--text-inverse)]',
    plain: 'text-[var(--status-success-text)]',
  },
  warning: {
    subtle: 'bg-[var(--status-warning-subtle)] text-[var(--status-warning-text)]',
    solid: 'bg-[var(--status-warning)] text-[var(--text-inverse)]',
    plain: 'text-[var(--status-warning-text)]',
  },
  danger: {
    subtle: 'bg-[var(--status-danger-subtle)] text-[var(--status-danger-text)]',
    solid: 'bg-[var(--status-danger)] text-[var(--text-inverse)]',
    plain: 'text-[var(--status-danger-text)]',
  },
  info: {
    subtle: 'bg-[var(--status-info-subtle)] text-[var(--status-info-text)]',
    solid: 'bg-[var(--status-info)] text-[var(--text-inverse)]',
    plain: 'text-[var(--status-info-text)]',
  },
  project: {
    subtle: 'bg-[var(--support-indigo-subtle)] text-[var(--support-indigo-text)]',
    solid: 'bg-[var(--support-indigo-default)] text-[var(--text-inverse)]',
    plain: 'text-[var(--support-indigo-text)]',
  },
  task: {
    subtle: 'bg-[var(--support-teal-subtle)] text-[var(--support-teal-text)]',
    solid: 'bg-[var(--support-teal-default)] text-[var(--text-inverse)]',
    plain: 'text-[var(--support-teal-text)]',
  },
  risk: {
    subtle: 'bg-[var(--support-amber-subtle)] text-[var(--support-amber-text)]',
    solid: 'bg-[var(--support-amber-default)] text-[var(--text-inverse)]',
    plain: 'text-[var(--support-amber-text)]',
  },
  survey: {
    subtle: 'bg-[var(--support-violet-subtle)] text-[var(--support-violet-text)]',
    solid: 'bg-[var(--support-violet-default)] text-[var(--text-inverse)]',
    plain: 'text-[var(--support-violet-text)]',
  },
  admin: {
    subtle: 'bg-[var(--support-violet-subtle)] text-[var(--support-violet-text)]',
    solid: 'bg-[var(--support-violet-default)] text-[var(--text-inverse)]',
    plain: 'text-[var(--support-violet-text)]',
  },
};

/**
 * رأس صفحة موحّد للصفحات الداخلية.
 * عنوان + وصف + إجراءات. أيقونة هادئة (accent-subtle) لا مربّع لون صارخ.
 */
export const PageHeader: React.FC<PageHeaderProps> = ({
  title,
  subtitle,
  description,
  breadcrumb,
  back,
  status,
  metadata,
  icon: Icon,
  actions,
  size = 'default',
  iconTone = 'accent',
  iconVariant = 'subtle',
  className,
}) => {
  const resolvedDescription = description ?? subtitle;
  const classes = sizeClasses[size];

  return (
    <div
      className={cn(
        'flex w-full flex-col sm:flex-row sm:items-start sm:justify-between',
        classes.root,
        className
      )}
    >
      <div className="flex min-w-0 flex-1 items-start gap-3">
        {Icon && (
          <div
            className={cn(
              'grid shrink-0 place-items-center',
              classes.icon,
              iconToneClasses[iconTone][iconVariant]
            )}
          >
            <Icon className="h-5 w-5" strokeWidth={2} />
          </div>
        )}

        <div className="min-w-0 flex-1 text-start">
          {(back || breadcrumb) && (
            <div className="mb-2 flex flex-wrap items-center gap-2 text-[length:var(--text-small)] text-[var(--text-tertiary)]">
              {back}
              {breadcrumb}
            </div>
          )}

          <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
            <h1
              className={cn(
                'min-w-0 text-balance font-[var(--weight-bold)] leading-[var(--leading-tight)] text-[var(--text-primary)]',
                classes.title
              )}
            >
              {title}
            </h1>
            {status && <div className="shrink-0">{status}</div>}
          </div>

          {resolvedDescription && (
            <p
              className={cn(
                'mt-1 max-w-3xl text-pretty leading-[var(--leading-normal)] text-[var(--text-tertiary)]',
                classes.description
              )}
            >
              {resolvedDescription}
            </p>
          )}

          {metadata && (
            <div
              className={cn(
                'mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 leading-[var(--leading-snug)] text-[var(--text-secondary)]',
                classes.metadata
              )}
            >
              {metadata}
            </div>
          )}
        </div>
      </div>

      {actions && (
        <div className="flex shrink-0 flex-wrap items-center justify-start gap-2 sm:justify-end">
          {actions}
        </div>
      )}
    </div>
  );
};

export default PageHeader;
