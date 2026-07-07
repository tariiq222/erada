export { default as CommentsSection } from './CommentsSection';
export { default as SubtaskCard } from './SubtaskCard';
export { default as DetailsTab } from './DetailsTab';
export { default as SubtasksTab } from './SubtasksTab';
export { default as AttachmentsTab } from './AttachmentsTab';

export * from './types';
export * from './constants';
export { useTaskViewModal } from './useTaskViewModal';

export type { CommentsSectionProps } from './CommentsSection';
export type { SubtaskCardProps } from './SubtaskCard';

// Re-export from shared components
export { MentionInput, type UserOption, type MentionInputProps } from '@shared/ui';
