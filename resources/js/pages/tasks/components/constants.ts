import type React from 'react';
import {IconCircle, IconClock, IconEye, IconCircleCheck, IconCircleX} from '@tabler/icons-react';
import {
  TASK_STATUS_CLASS,
  TASK_PRIORITY_BORDERED_CLASS,
} from '@shared/lib/statusTokens';

export const statusIcons: Record<string, React.FC<{ className?: string }>> = {
  todo: IconCircle,
  pending: IconCircle,
  in_progress: IconClock,
  in_review: IconEye,
  completed: IconCircleCheck,
  on_hold: IconCircle,
  cancelled: IconCircleX,
};

// مصدر واحد للحقيقة: @shared/lib/statusTokens
export const statusColors: Record<string, string> = TASK_STATUS_CLASS as Record<string, string>;

export const statusLabels: Record<string, string> = {
  todo: 'status.todo',
  pending: 'status.pending',
  in_progress: 'status.in_progress',
  in_review: 'status.in_review',
  completed: 'status.completed',
  on_hold: 'status.on_hold',
  cancelled: 'status.cancelled',
};

export const priorityLabels: Record<string, string> = {
  low: 'priority.low',
  medium: 'priority.medium',
  high: 'priority.high',
  urgent: 'priority.urgent',
};

export const priorityColors: Record<string, string> = TASK_PRIORITY_BORDERED_CLASS as Record<string, string>;
