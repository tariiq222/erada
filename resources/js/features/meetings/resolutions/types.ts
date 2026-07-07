// ─── Meeting Resolution (Phase 1 — Meeting Resolutions Foundation) ──────
// Direction: a unified, action-oriented successor to the legacy
// `recommendations.*` kind tree. No approve/reject/adopt semantics — a
// resolution either runs to completion (via `convert-to-tasks` + `complete`)
// or is cancelled. Lifecycle: open → in_progress → (converted_to_tasks |
// completed | cancelled), with an `is_on_hold` orthogonal state.

export type ResolutionKind = 'recommendation' | 'decision';

export type ResolutionStatus =
  | 'open'
  | 'in_progress'
  | 'converted_to_tasks'
  | 'completed'
  | 'cancelled';

export type ResolutionPriority = 'low' | 'medium' | 'high' | 'critical';

// `project` mirrors App\Modules\Projects\Models\Project; `risk` mirrors
// App\Modules\RiskManagement\Models\Risk. The server enforces the FQCN
// mapping on the resolver side.
export type LinkableType = 'project' | 'risk';

export type LinkRole = 'related_to' | 'implementation_scope';

export interface ResolutionLink {
  id: number;
  linkable_type: LinkableType;
  linkable_id: number;
  link_role: LinkRole;
  /** Optional server-resolved label for display (e.g. project name). */
  linkable_label?: string;
}

export interface ResolutionUserRef {
  id: number;
  name: string;
}

export interface ResolutionMeetingRef {
  id: number;
  title: string;
  reference_number: string;
}

export interface MeetingResolution {
  id: number;
  reference_number: string | null;
  organization_id: number | null;
  meeting_id: number;
  kind: ResolutionKind;
  title: string;
  description: string | null;
  owner_id: number;
  status: ResolutionStatus;
  priority: ResolutionPriority;
  due_date: string | null;
  hold_reason: string | null;
  hold_until: string | null;
  hold_by: number | null;
  hold_at: string | null;
  created_by: number;
  completed_at: string | null;
  cancelled_at: string | null;
  created_at: string;
  updated_at: string;
  // server-rendered Arabic labels (kept for backwards compat with the
  // legacy RecommendationCard label plumbing — safe to read).
  status_label: string;
  kind_label?: string;
  priority_label?: string;
  /** Server-computed accessor — true iff `hold_at` is set and `status` is
   *  not in a terminal state. UI uses this to render the hold banner. */
  is_on_hold?: boolean;
  // relations
  owner?: ResolutionUserRef | null;
  creator?: ResolutionUserRef | null;
  holder?: ResolutionUserRef | null;
  meeting?: ResolutionMeetingRef | null;
  links?: ResolutionLink[];
  // Phase 3: task-progress aggregates surfaced on list + detail endpoints.
  // Populated by withCount() in the controller so the SPA does not N+1.
  tasks_count?: number;
  completed_tasks_count?: number;
  pending_tasks_count?: number;
  completion_percentage?: number;
  // Phase 3: detail endpoint also returns the actual tasks list (capped).
  tasks?: ResolutionTask[];
}

/**
 * Minimal Task shape returned on the show endpoint alongside a
 * MeetingResolution. The full paginated list still flows through
 * /api/tasks filtered by source_type/source_id.
 */
export interface ResolutionTask {
  id: number;
  title: string;
  status: string;
  priority?: string;
  due_date: string | null;
  assignee_id: number | null;
  owner_id?: number | null;
  completed_date?: string | null;
  assignee?: ResolutionUserRef | null;
}

export interface ResolutionLinkPayload {
  linkable_type: LinkableType;
  linkable_id: number;
  link_role?: LinkRole;
}

export interface ResolutionCreatePayload {
  meeting_id: number;
  kind: ResolutionKind;
  title: string;
  description?: string | null;
  owner_id: number;
  priority?: ResolutionPriority;
  due_date?: string | null;
  links?: ResolutionLinkPayload[];
}

export type ResolutionUpdatePayload = Partial<ResolutionCreatePayload>;

export interface HoldPayload {
  hold_reason: string;
  hold_until?: string | null;
}

export interface PlannedTaskPayload {
  title: string;
  description?: string | null;
  assignee_id: number;
  due_date?: string | null;
  priority?: ResolutionPriority;
  project_id?: number | null;
  /** Not yet supported on the tasks table — the request rejects this. */
  risk_id?: never;
}

export interface ConvertToTasksPayload {
  tasks: PlannedTaskPayload[];
}

export interface ConvertToTasksResponse {
  message: string;
  resolution: MeetingResolution;
  tasks: ResolutionTask[];
}

// Status metadata for UI gating. Keep in sync with the backend
// `ResolutionStatus` enum and the engine's transition guards.
export const RESOLUTION_STATUSES: readonly ResolutionStatus[] = [
  'open',
  'in_progress',
  'converted_to_tasks',
  'completed',
  'cancelled',
] as const;

export const RESOLUTION_KINDS: readonly ResolutionKind[] = [
  'recommendation',
  'decision',
] as const;

export const RESOLUTION_PRIORITIES: readonly ResolutionPriority[] = [
  'low',
  'medium',
  'high',
  'critical',
] as const;

export const LINKABLE_TYPES: readonly LinkableType[] = ['project', 'risk'] as const;

export const LINK_ROLES: readonly LinkRole[] = [
  'related_to',
  'implementation_scope',
] as const;

// Active statuses (non-terminal). Used for transition gating — you cannot
// start / hold / complete / cancel a terminal resolution.
export type ActiveResolutionStatus = Extract<
  ResolutionStatus,
  'open' | 'in_progress'
>;