import type { CustomBadgeColor } from '@shared/ui';

export const STATUS_COLORS: Record<string, CustomBadgeColor> = {
  proposed: 'warning',
  accepted: 'info',
  rejected: 'danger',
  deferred: 'secondary',
  completed: 'success',
};

export const PRIORITY_COLORS: Record<string, CustomBadgeColor> = {
  low: 'secondary',
  medium: 'info',
  high: 'warning',
  critical: 'danger',
};
