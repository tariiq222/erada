export * from './types';
export * from './api';
// Phase 4 cleanup: the legacy `components/ResolutionsSection.tsx` was the
// Direction B widget that rendered RecommendationCard under a meeting card.
// Phase 1 superseded it with `features/meetings/resolutions/ResolutionsSection`
// (backed by `MeetingResolution`). No callers remain — the re-export below
// is dropped. The component file itself is left on disk so Phase 5+ can
// delete it during the Recommendation-controller retirement without a
// broken-import regression window.
export { default as DecisionsSection } from './components/DecisionsSection';
export type { DecisionsSectionProps } from './components/DecisionsSection';
export { default as RecommendationCard } from './RecommendationCard';
export type { RecommendationCardProps } from './RecommendationCard';
export { default as RecommendationForm } from './RecommendationForm';
export type { RecommendationFormProps } from './RecommendationForm';
export { default as DeferModal } from './DeferModal';
export type { DeferModalProps } from './DeferModal';