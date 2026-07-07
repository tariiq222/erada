export const statusLabels: Record<string, string> = {
  draft: 'status.draft',
  planning: 'status.planning',
  in_progress: 'status.in_progress',
  on_hold: 'status.on_hold',
  completed: 'status.completed',
  cancelled: 'status.cancelled',
};

export const statusVariants: Record<string, 'default' | 'accent' | 'success' | 'warning' | 'danger'> = {
  draft: 'default',
  planning: 'accent',
  in_progress: 'accent',
  on_hold: 'warning',
  completed: 'success',
  cancelled: 'danger',
};

export const priorityLabels: Record<string, string> = {
  low: 'priority.low',
  medium: 'priority.medium',
  high: 'priority.high',
  urgent: 'priority.urgent',
};

import type { Icon } from '@tabler/icons-react';
import { IconRefresh, IconRocket } from '@tabler/icons-react';

export const priorityVariants: Record<string, 'default' | 'success' | 'warning' | 'danger'> = {
  low: 'default',
  medium: 'success',
  high: 'warning',
  urgent: 'danger',
};

export type ProjectTypeToken = {
  icon: Icon;
  bg: string;
  fg: string;
};

export const projectTypeTokens: Record<string, ProjectTypeToken> = {
  improvement: {
    icon: IconRefresh,
    bg: 'var(--support-teal-subtle)',
    fg: 'var(--support-teal-text)',
  },
  development: {
    icon: IconRocket,
    bg: 'var(--support-indigo-subtle)',
    fg: 'var(--support-indigo-text)',
  },
};
