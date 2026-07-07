import * as React from 'react';
import { cn } from '@shared/lib/utils';
import {type LucideIcon} from '@tabler/icons-react';

export type SectionHeaderLevel = 2 | 3;
export type SectionHeaderSize = 'default' | 'compact';
export type SectionHeaderIconTone =
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
export type SectionHeaderIconVariant = 'subtle' | 'solid' | 'plain';

export interface SectionHeaderProps {
  title: React.ReactNode;
  description?: React.ReactNode;
  level?: SectionHeaderLevel;
  size?: SectionHeaderSize;
  icon?: LucideIcon;
  iconTone?: SectionHeaderIconTone;
  iconVariant?: SectionHeaderIconVariant;
  meta?: React.ReactNode;
  actions?: React.ReactNode;
  className?: string;
}

const sizeClasses: Record<SectionHeaderSize, {
  root: string;
  icon: string;
  title: string;
  description: string;
}> = {
  default: {
    root: 'gap-3',
    icon: 'h-9 w-9 rounded-[var(--radius-md)]',
    title: 'text-[length:var(--text-h2)]',
    description: 'text-[length:var(--text-body)]',
  },
  compact: {
    root: 'gap-2',
    icon: 'h-8 w-8 rounded-[var(--radius-sm)]',
    title: 'text-[length:var(--text-h3)]',
    description: 'text-[length:var(--text-small)]',
  },
};

const iconToneClasses: Record<SectionHeaderIconTone, Record<SectionHeaderIconVariant, string>> = {
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

export const SectionHeader: React.FC<SectionHeaderProps> = ({
  title,
  description,
  level = 2,
  size = 'default',
  icon: Icon,
  iconTone = 'neutral',
  iconVariant = 'subtle',
  meta,
  actions,
  className,
}) => {
  const Heading = level === 3 ? 'h3' : 'h2';
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
            <Icon className="h-4 w-4" strokeWidth={2} />
          </div>
        )}

        <div className="min-w-0 flex-1 text-start">
          <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
            <Heading
              className={cn(
                'min-w-0 text-balance font-[var(--weight-semibold)] leading-[var(--leading-snug)] text-[var(--text-primary)]',
                classes.title
              )}
            >
              {title}
            </Heading>
            {meta && (
              <div className="flex shrink-0 items-center gap-2 text-[length:var(--text-small)] leading-[var(--leading-snug)] text-[var(--text-tertiary)]">
                {meta}
              </div>
            )}
          </div>

          {description && (
            <p
              className={cn(
                'mt-1 max-w-3xl text-pretty leading-[var(--leading-normal)] text-[var(--text-tertiary)]',
                classes.description
              )}
            >
              {description}
            </p>
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

export default SectionHeader;
