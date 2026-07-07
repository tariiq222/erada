import * as React from 'react';
import { useTranslation } from 'react-i18next';
import { cn } from '@shared/lib/utils';
import {
  PROJECT_STATUS_CLASS,
  TASK_STATUS_CLASS,
  PRIORITY_CLASS,
  type ProjectStatusKey,
  type TaskStatusKey,
  type PriorityKey,
} from '@shared/lib/statusTokens';

/**
 * StatusBadge - مكون موحد لعرض حالات المشاريع والمهام
 *
 * استخدم هذا المكون بدلاً من تكرار ألوان الحالات في كل صفحة.
 * مصدر الألوان: `@shared/lib/statusTokens` (مصدر واحد للحقيقة).
 *
 * @example
 * <StatusBadge type="project" status="in_progress" />
 * <StatusBadge type="task" status="completed" />
 */

export type ProjectStatus = ProjectStatusKey;
export type TaskStatus = TaskStatusKey;
export type TaskPriority = PriorityKey;

// مفاتيح الترجمة لكل حالة (لا تخص اللون — تبقى هنا)
const STATUS_LABEL_KEYS = {
  project: {
    draft: 'status.draft',
    planning: 'status.planning',
    in_progress: 'status.in_progress',
    on_hold: 'status.on_hold',
    completed: 'status.completed',
    cancelled: 'status.cancelled',
  },
  task: {
    todo: 'status.todo',
    in_progress: 'status.in_progress',
    in_review: 'status.in_review',
    completed: 'status.completed_female',
  },
  priority: {
    low: 'priority.low',
    medium: 'priority.medium',
    high: 'priority.high',
    critical: 'priority.critical',
  },
} as const;

// ألوان موحدة للحالات - مصدرها @shared/lib/statusTokens
const STATUS_STYLES = {
  project: {
    draft: { key: STATUS_LABEL_KEYS.project.draft, classes: PROJECT_STATUS_CLASS.draft },
    planning: { key: STATUS_LABEL_KEYS.project.planning, classes: PROJECT_STATUS_CLASS.planning },
    in_progress: { key: STATUS_LABEL_KEYS.project.in_progress, classes: PROJECT_STATUS_CLASS.in_progress },
    on_hold: { key: STATUS_LABEL_KEYS.project.on_hold, classes: PROJECT_STATUS_CLASS.on_hold },
    completed: { key: STATUS_LABEL_KEYS.project.completed, classes: PROJECT_STATUS_CLASS.completed },
    cancelled: { key: STATUS_LABEL_KEYS.project.cancelled, classes: PROJECT_STATUS_CLASS.cancelled },
  },
  task: {
    todo: { key: STATUS_LABEL_KEYS.task.todo, classes: TASK_STATUS_CLASS.todo },
    in_progress: { key: STATUS_LABEL_KEYS.task.in_progress, classes: TASK_STATUS_CLASS.in_progress },
    in_review: { key: STATUS_LABEL_KEYS.task.in_review, classes: TASK_STATUS_CLASS.in_review },
    completed: { key: STATUS_LABEL_KEYS.task.completed, classes: TASK_STATUS_CLASS.completed },
  },
  priority: {
    low: { key: STATUS_LABEL_KEYS.priority.low, classes: PRIORITY_CLASS.low },
    medium: { key: STATUS_LABEL_KEYS.priority.medium, classes: PRIORITY_CLASS.medium },
    high: { key: STATUS_LABEL_KEYS.priority.high, classes: PRIORITY_CLASS.high },
    critical: { key: STATUS_LABEL_KEYS.priority.critical, classes: PRIORITY_CLASS.critical },
  },
} as const;

// Color configurations for custom badges
const CUSTOM_COLORS = {
  primary: 'bg-[var(--accent-subtle)] text-[var(--accent-default)]',
  secondary: 'bg-[var(--surface-muted)] text-[var(--text-secondary)]',
  success: 'bg-[var(--status-success-subtle)] text-[var(--status-success)]',
  warning: 'bg-[var(--status-warning-subtle)] text-[var(--status-warning)]',
  danger: 'bg-[var(--status-danger-subtle)] text-[var(--status-danger)]',
  info: 'bg-[var(--accent-subtle)] text-[var(--accent-default)]',
} as const;

export type CustomBadgeColor = keyof typeof CUSTOM_COLORS;

export type StatusBadgeProps = {
  size?: 'sm' | 'md';
  showDot?: boolean;
  className?: string;
} & (
  | { type: 'project' | 'task' | 'priority'; status: string; label?: never; color?: never }
  | { type: 'custom'; status: string; label: string; color: CustomBadgeColor }
);

const StatusBadge: React.FC<StatusBadgeProps> = (props) => {
  const { t } = useTranslation();
  const { type, status, size = 'md', showDot = false, className } = props;

  // Handle custom type
  if (type === 'custom') {
    const { label, color } = props as { label: string; color: CustomBadgeColor };
    const colorClasses = CUSTOM_COLORS[color] || CUSTOM_COLORS.secondary;

    return (
      <span
        className={cn(
          'inline-flex items-center font-medium rounded-full',
          colorClasses,
          size === 'sm' ? 'px-2 py-0 text-[11px] leading-4' : 'px-2 py-1 text-[11px] leading-4',
          className
        )}
      >
        {showDot && (
          <span className="w-1.5 h-1.5 rounded-full bg-current ms-1 opacity-70" />
        )}
        {label}
      </span>
    );
  }

  // Handle standard types
  const styleSet = STATUS_STYLES[type];
  const statusConfig = styleSet ? styleSet[status as keyof typeof styleSet] : undefined;

  if (!statusConfig) {
    return (
      <span className={cn(
        'inline-flex items-center font-medium rounded-full',
        'bg-[var(--surface-muted)] text-[var(--text-secondary)]',
        size === 'sm' ? 'px-2 py-0 text-[11px] leading-4' : 'px-2 py-1 text-[11px] leading-4',
        className
      )}>
        {status}
      </span>
    );
  }

  const config = statusConfig as { key: string; classes: string };

  return (
    <span
      className={cn(
        'inline-flex items-center font-medium rounded-full',
        config.classes,
        size === 'sm' ? 'px-2 py-0 text-[11px] leading-4' : 'px-2 py-1 text-[11px] leading-4',
        className
      )}
    >
      {showDot && (
        <span className="w-1.5 h-1.5 rounded-full bg-current ms-1 opacity-70" />
      )}
      {t(config.key)}
    </span>
  );
};

StatusBadge.displayName = 'StatusBadge';

// Helper functions للحصول على الألوان (لاستخدامها في الأماكن الخاصة)
// عند تمرير t function يتم ترجمة المفتاح، وإلا يُعاد المفتاح كما هو
export const getProjectStatusLabel = (status: ProjectStatus, t?: (key: string) => string): string => {
  const config = (STATUS_STYLES.project as Record<string, { key: string; classes: string }>)[status];
  if (!config) return status;
  return t ? t(config.key) : config.key;
};

export const getTaskStatusLabel = (status: TaskStatus, t?: (key: string) => string): string => {
  const config = (STATUS_STYLES.task as Record<string, { key: string; classes: string }>)[status];
  if (!config) return status;
  return t ? t(config.key) : config.key;
};

export const getPriorityLabel = (priority: TaskPriority, t?: (key: string) => string): string => {
  const config = (STATUS_STYLES.priority as Record<string, { key: string; classes: string }>)[priority];
  if (!config) return priority;
  return t ? t(config.key) : config.key;
};

// Export status maps for backward compatibility and special cases
export const PROJECT_STATUS_MAP = STATUS_STYLES.project;
export const TASK_STATUS_MAP = STATUS_STYLES.task;
export const PRIORITY_MAP = STATUS_STYLES.priority;

export { StatusBadge };
