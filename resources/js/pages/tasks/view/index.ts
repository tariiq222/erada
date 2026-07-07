export type {
  TimeIndicator,
  TaskDetails,
  CommentAttachment,
  Comment,
} from './types';

export { statusLabels, statusColors, statusIcons } from './constants';
export { default as CommentsSection } from './CommentsSection';
export { default as TaskViewSkeleton } from './TaskViewSkeleton';

// Re-export from shared components
export { MentionInput, type UserOption, type MentionInputProps } from '@shared/ui';
