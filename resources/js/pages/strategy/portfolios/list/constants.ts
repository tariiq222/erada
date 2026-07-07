export const statusLabels: Record<string, string> = {
  draft: 'status.draft',
  active: 'common.active',
  completed: 'status.completed',
  cancelled: 'status.cancelled',
};

export const statusVariants: Record<string, 'default' | 'accent' | 'success' | 'warning' | 'danger'> = {
  draft: 'default',
  active: 'accent',
  completed: 'success',
  cancelled: 'danger',
};
