import type React from 'react';
import {IconCircle, IconClock, IconEye, IconCircleCheck, IconPlayerPause, IconCircleX} from '@tabler/icons-react';
import {
  TASK_STATUS_CLASS,
  PRIORITY_TEXT,
  KANBAN_TASK_COLUMN_TOKENS,
} from '@shared/lib/statusTokens';

export const statusLabels: Record<string, string> = {
  todo: 'status.todo',
  pending: 'status.todo',
  in_progress: 'status.in_progress',
  in_review: 'status.in_review',
  completed: 'status.completed',
  on_hold: 'status.on_hold',
  cancelled: 'status.cancelled',
};

export const statusVariants: Record<string, 'default' | 'accent' | 'success' | 'warning' | 'danger'> = {
  todo: 'default',
  pending: 'default',
  in_progress: 'accent',
  in_review: 'warning',
  completed: 'success',
  on_hold: 'warning',
  cancelled: 'danger',
};

export const priorityLabels: Record<string, string> = {
  low: 'priority.low',
  medium: 'priority.medium',
  high: 'priority.high',
  urgent: 'priority.urgent',
};

export const statusIcons: Record<string, React.FC<{ className?: string }>> = {
  todo: IconCircle,
  pending: IconCircle,
  in_progress: IconClock,
  in_review: IconEye,
  completed: IconCircleCheck,
  on_hold: IconPlayerPause,
  cancelled: IconCircleX,
};

// مصدر واحد للحقيقة: @shared/lib/statusTokens
// نُعيد التصدير هنا للحفاظ على توافق المستهلكين الحاليين (TableView, SubtaskCard,
// TaskView، والاختبارات) التي تستورد هذه الأسماء من هذا الملف.
// النوع المُعاد تصديره عريض (`Record<string, ...>`) لتفادي كسر فهرسة
// المستهلكين الذين يستخدمون `string` مباشرة دون تحويل.
export const statusColors: Record<string, string> = TASK_STATUS_CLASS as Record<string, string>;
export const priorityColors: Record<string, string> = PRIORITY_TEXT as Record<string, string>;
export const kanbanColumnStyles: Record<string, { bg: string; border: string; headerBg: string; headerText: string }> =
  KANBAN_TASK_COLUMN_TOKENS as unknown as Record<string, { bg: string; border: string; headerBg: string; headerText: string }>;
