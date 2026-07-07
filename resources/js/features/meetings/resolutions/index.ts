// Public surface for the meeting-resolutions feature slice.
// Phase 1 of "Meeting Resolutions Foundation". The legacy
// `features/meetings/components/ResolutionsSection.tsx` (backed by the
// Direction B `Recommendation` model) is replaced by this module; the
// `MeetingView` integration worker swaps the import.

export { default as ResolutionForm } from './ResolutionForm';
export type { ResolutionFormProps } from './ResolutionForm';

export { default as ResolutionCard } from './ResolutionCard';
export type { ResolutionCardProps } from './ResolutionCard';

export { default as ResolutionsSection } from './ResolutionsSection';
export type { ResolutionsSectionProps } from './ResolutionsSection';

export { resolutionsApi } from './api';

export type {
  MeetingResolution,
  ResolutionKind,
  ResolutionStatus,
  ResolutionPriority,
  ActiveResolutionStatus,
  ResolutionCreatePayload,
  ResolutionUpdatePayload,
  ResolutionLinkPayload,
  ResolutionLink,
  ResolutionUserRef,
  ResolutionMeetingRef,
  HoldPayload,
  ConvertToTasksPayload,
  PlannedTaskPayload,
  LinkableType,
  LinkRole,
} from './types';

export {
  RESOLUTION_STATUSES,
  RESOLUTION_KINDS,
  RESOLUTION_PRIORITIES,
  LINKABLE_TYPES,
  LINK_ROLES,
} from './types';