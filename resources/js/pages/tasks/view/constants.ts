import type React from 'react';
import {IconCircle, IconCircleCheck, IconPlayerPause, IconCircleX, IconPlayerPlay} from '@tabler/icons-react';
import { TASK_STATUS_BORDERED_CLASS } from '@shared/lib/statusTokens';

export const statusLabels: Record<string, string> = {
  todo: 'status.todo',
  pending: 'status.todo',
  in_progress: 'status.in_progress',
  in_review: 'status.in_review',
  completed: 'status.completed',
  on_hold: 'status.on_hold',
  cancelled: 'status.cancelled',
};

// مصدر واحد للحقيقة: @shared/lib/statusTokens (مشتقّ من TASK_STATUS_TOKENS)
export const statusColors: Record<string, string> = TASK_STATUS_BORDERED_CLASS as Record<string, string>;

export const statusIcons: Record<string, React.FC<{ className?: string }>> = {
  todo: IconCircle,
  pending: IconCircle,
  in_progress: IconPlayerPlay,
  in_review: IconCircle,
  completed: IconCircleCheck,
  on_hold: IconPlayerPause,
  cancelled: IconCircleX,
};
