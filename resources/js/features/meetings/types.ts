// ─── Direction B unified Recommendation (kind='ruling' | 'action_item') ──

export type RecommendationKind = 'ruling' | 'action_item';

export type RulingType =
  | 'approval'
  | 'change_request'
  | 'escalation'
  | 'resource_allocation'
  | 'scope_change'
  | 'budget_change'
  | 'timeline_change'
  | 'other';

export type RecommendationStatus =
  | 'proposed'
  | 'pending'
  | 'accepted'
  | 'approved'
  | 'rejected'
  | 'deferred'
  | 'completed';

export type RecommendationPriority = 'low' | 'medium' | 'high' | 'critical';

export type DecidableAlias = 'project' | 'portfolio' | 'program' | 'risk';

export interface RecommendationTaskLink {
  id: number;
  title: string;
  status: string;
}

export interface Recommendation {
  id: number;
  reference_number: string;
  kind: RecommendationKind;
  // common
  title: string;
  description: string | null;
  status: RecommendationStatus;
  organization_id: number;
  meeting_id: number | null;
  // optional polymorphic target
  decidable_type: string | null;
  decidable_id: number | null;
  // ruling-only fields
  type: RulingType | null;
  rationale: string | null;
  impact: string | null;
  requested_by: number | null;
  made_by: number | null;
  decision_date: string | null;
  effective_date: string | null;
  // action_item-only fields
  assignee_id: number | null;
  due_date: string | null;
  priority: RecommendationPriority;
  completed_at: string | null;
  // defer metadata (shared)
  defer_reason: string | null;
  deferred_until: string | null;
  deferred_by: number | null;
  deferred_at: string | null;
  // completion gate
  has_pending_tasks: boolean;
  // timestamps
  created_at: string;
  updated_at: string;
  // labels (server-rendered for backwards compat)
  status_label: string;
  priority_label?: string;
  kind_label?: string;
  is_overdue?: boolean;
  // relations
  meeting?: { id: number; title: string; reference_number: string } | null;
  decisionMaker?: { id: number; name: string } | null;
  requester?: { id: number; name: string } | null;
  assignee?: { id: number; name: string } | null;
  decidable?: { id: number; name: string } | null;
  deferredBy?: { id: number; name: string } | null;
  tasks?: RecommendationTaskLink[];
  allowed_actions?: {
    update: boolean;
    delete: boolean;
    approve: boolean;
    accept: boolean;
    reject: boolean;
    defer: boolean;
    complete: boolean;
  };
  // Legacy compat (Direction A → B). The standalone Recommendation pages
  // still read these. They will be deleted in Phase R4.
  /** @deprecated use meeting_id + a separate Decision join if needed. */
  decision_id?: number | null;
  /** @deprecated use meeting relation. */
  decision?: { id: number; title: string; reference_number: string } | null;
}

export interface RecommendationCreatePayload {
  meeting_id?: number | null;
  decidable_type?: DecidableAlias | null;
  decidable_id?: number | null;
  kind: RecommendationKind;
  title: string;
  description?: string | null;
  // ruling-only (required when kind=ruling)
  type?: RulingType | null;
  rationale?: string | null;
  impact?: string | null;
  // action_item-only (required when kind=action_item)
  assignee_id?: number | null;
  due_date?: string | null;
  priority?: RecommendationPriority;
  // Legacy compat (Direction A → B). Kept so the standalone
  // RecommendationForm/List pages (which Phase R4 will delete) keep
  // compiling until then.
  /** @deprecated decisions are gone; this field is ignored by the server. */
  decision_id?: number | null;
}

export interface DeferPayload {
  defer_reason?: string | null;
  deferred_until?: string | null;
}

export interface RejectPayload {
  rationale?: string | null;
}

export const ALIAS_TO_FQCN: Record<DecidableAlias, string> = {
  project: 'App\\Modules\\Projects\\Models\\Project',
  portfolio: 'App\\Modules\\Strategy\\Models\\Portfolio',
  program: 'App\\Modules\\Strategy\\Models\\Program',
  risk: 'App\\Modules\\RiskManagement\\Models\\Risk',
};

export const FQCN_TO_ALIAS: Record<string, DecidableAlias> = Object.fromEntries(
  Object.entries(ALIAS_TO_FQCN).map(([alias, fqcn]) => [fqcn, alias as DecidableAlias]),
) as Record<string, DecidableAlias>;

// ─── Meeting / agenda / notifications (unchanged surface) ───────────────

export type MeetingStatus = 'scheduled' | 'in_progress' | 'completed' | 'cancelled';

export interface MeetingAttendeePivot {
  user_id: number;
  role: 'chair' | 'attendee' | 'observer' | string;
  attended: boolean;
}

export interface MeetingAttendee {
  id: number;
  name: string;
  email?: string;
  pivot?: MeetingAttendeePivot;
}

export interface Meeting {
  id: number;
  reference_number: string;
  title: string;
  description: string | null;
  scheduled_at: string;
  duration_minutes: number;
  location: string | null;
  virtual_link: string | null;
  agenda: string | null;
  minutes: string | null;
  status: MeetingStatus;
  organizer_id: number;
  subject_type: string | null;
  subject_id: number | null;
  category_id: number | null;
  organization_id: number;
  created_at: string;
  updated_at: string;
  status_label: string;
  organizer?: { id: number; name: string } | null;
  attendees?: MeetingAttendee[];
  subject?: { id: number; name: string } | null;
  category?: MeetingCategory | null;
  decisions_count?: number;
  // Backwards-compat alias: some legacy components still read this
  recommendations_count?: number;
  decisions?: Array<{ id: number; title: string; reference_number: string }>;
  recommendations?: Array<{ id: number; title: string; reference_number: string }>;
  allowed_actions?: {
    update: boolean;
    delete: boolean;
    view_agenda: boolean;
  };
}

export interface MeetingCategory {
  id: number;
  name: string;
  is_active: boolean;
  sort_order: number;
}

export interface MeetingSettings {
  id: number;
  organization_id: number | null;
  default_duration_minutes: number;
  reminder_window_hours: number;
  attendee_roles: string[];
  default_category_id: number | null;
  agenda_request_enabled: boolean;
  agenda_request_lead_hours: number;
  decision_pending_expiry_days: number;
  recommendation_overdue_grace_days: number;
  created_at?: string;
  updated_at?: string;
}

export type MeetingSettingsPayload = Omit<
  MeetingSettings,
  'id' | 'organization_id' | 'created_at' | 'updated_at'
>;

export interface MeetingCreatePayload {
  title: string;
  description?: string | null;
  scheduled_at: string;
  duration_minutes: number;
  location?: string | null;
  virtual_link?: string | null;
  agenda?: string | null;
  minutes?: string | null;
  status?: MeetingStatus;
  organizer_id: number;
  subject_type?: DecidableAlias | null;
  subject_id?: number | null;
  category_id?: number | null;
  attendee_ids?: number[];
}

export type AgendaItemStatus = 'pending' | 'approved' | 'rejected';

export interface AgendaItem {
  id: number;
  meeting_id: number;
  title: string;
  description: string | null;
  proposed_by_id: number | null;
  status: AgendaItemStatus;
  position: number;
  review_note: string | null;
  status_label: string;
  created_at: string;
  updated_at: string;
  proposed_by?: { id: number; name: string } | null;
}

export interface AgendaItemsResponse {
  data: AgendaItem[];
  can_manage: boolean;
  agenda_requested_at: string | null;
}

export type NotificationType =
  | 'meeting_scheduled'
  | 'meeting_reminder'
  | 'agenda_requested'
  | 'decision_approved'
  | 'recommendation_assigned'
  | 'recommendation_overdue';

export interface AppNotification {
  id: string;
  type: NotificationType;
  data: Record<string, unknown>;
  read_at: string | null;
  created_at: string;
}

export interface Notification {
  id: string;
  type: string;
  data: {
    type: NotificationType;
    meeting_id?: number;
    decision_id?: number;
    recommendation_id?: number;
    reference_number?: string;
    title?: string;
    scheduled_at?: string;
    priority?: string;
    due_date?: string;
    days_overdue?: number;
    message: string;
  };
  read_at: string | null;
  created_at: string;
}

export interface UnreadCount {
  unread: number;
}

export interface NotificationListParams {
  unread_only?: boolean;
  type?: NotificationType;
  per_page?: number;
  page?: number;
}
