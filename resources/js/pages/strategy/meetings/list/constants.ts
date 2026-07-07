import type { CustomBadgeColor } from '@shared/ui';

export const STATUS_COLORS: Record<string, CustomBadgeColor> = {
  scheduled: 'warning',
  in_progress: 'info',
  completed: 'success',
  cancelled: 'secondary',
};
