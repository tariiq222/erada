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
  critical: 'priority.critical',
};

export const priorityVariants: Record<string, 'default' | 'success' | 'warning' | 'danger'> = {
  low: 'default',
  medium: 'success',
  high: 'warning',
  critical: 'danger',
};
