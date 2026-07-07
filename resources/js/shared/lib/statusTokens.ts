/**
 * statusTokens.ts — Single Source of Truth for status→color decisions.
 *
 * Every status pill, chart slice, kanban column, or priority icon across the
 * app reads its color from this file. Consumers must NOT redefine these
 * maps locally. If a consumer needs a different visual for a given status,
 * add a new map here with a domain-specific name rather than forking inline.
 *
 * Why split by vocabulary?
 *   Task, Project, Priority, Time-Indicator, Kanban-Column, OVR chart, and
 *   the subtask-aggregate state all use DIFFERENT key sets and have
 *   intentionally different palettes in some cases (e.g. ProjectReportCard
 *   uses `var(--status-warning)` for `in_progress` while StatusBadge uses
 *   `var(--accent-default)`). Merging them would either be lossy or force
 *   an unwanted visual unification. Each domain has its own map.
 *
 * Conventions:
 *   - `*_CLASS` maps return a single Tailwind class string (e.g. for pills).
 *   - `*_TOKENS` maps return a decomposed object of CSS-variable classes so
 *     the consumer can compose them into larger className strings.
 *   - `*_CHART` maps return raw `var(--token)` values, used for recharts
 *     fills via `style={{ backgroundColor: ... }}`.
 *   - `OVR_STATUS_BADGE_VARIANT` is the only enum-typed map; it maps an
 *     OVR status to a Badge variant (the Badge component owns the color).
 */

// =============================================================================
// 1. Task status
// =============================================================================

/**
 * Canonical task status keys used across the task views (Kanban, Cards,
 * Table, SubtaskCard, TaskView, etc.). Includes `pending`, `on_hold`, and
 * `cancelled` even though StatusBadge's task map only covers the 4 main
 * states — those three are valid task lifecycle values that appear in
 * legacy data and the time-indicator UI.
 */
export type TaskStatusKey =
  | 'todo'
  | 'pending'
  | 'in_progress'
  | 'in_review'
  | 'completed'
  | 'on_hold'
  | 'cancelled';

/**
 * Single-class pill format. Order is `text-... bg-...` (matches the original
 * `pages/tasks/list/constants.ts` style). Used wherever a `<div>` or `<span>`
 * needs a one-liner class for a task-status pill.
 */
export const TASK_STATUS_CLASS: Record<TaskStatusKey, string> = {
  todo: 'text-[var(--text-secondary)] bg-[var(--surface-muted)]',
  pending: 'text-[var(--text-secondary)] bg-[var(--surface-muted)]',
  in_progress: 'text-[var(--accent-default)] bg-[var(--accent-subtle)]',
  in_review: 'text-[var(--status-warning-text)] bg-[var(--status-warning-subtle)]',
  completed: 'text-[var(--status-success-text)] bg-[var(--status-success-subtle)]',
  on_hold: 'text-[var(--status-warning-text)] bg-[var(--status-warning-subtle)]',
  cancelled: 'text-[var(--status-danger-text)] bg-[var(--status-danger-subtle)]',
};

/**
 * Decomposed task status tokens. Used by TaskStatusChanger's dropdown rows
 * where bg, text, border, and hoverBg each become their own Tailwind class.
 */
export const TASK_STATUS_TOKENS: Record<TaskStatusKey, {
  bg: string;
  text: string;
  border: string;
  hoverBg: string;
}> = {
  todo: {
    bg: 'bg-[var(--surface-muted)]',
    text: 'text-[var(--text-secondary)]',
    border: 'border-[var(--border-default)]',
    hoverBg: 'hover:bg-[var(--surface-muted)]',
  },
  in_progress: {
    bg: 'bg-[var(--accent-subtle)]',
    text: 'text-[var(--accent-default)]',
    border: 'border-[var(--accent-subtle)]',
    hoverBg: 'hover:bg-[var(--accent-default)]',
  },
  in_review: {
    bg: 'bg-[var(--status-warning-subtle)]',
    text: 'text-[var(--status-warning-text)]',
    border: 'border-[var(--status-warning-subtle)]',
    hoverBg: 'hover:bg-[var(--status-warning)]',
  },
  completed: {
    bg: 'bg-[var(--status-success-subtle)]',
    text: 'text-[var(--status-success-text)]',
    border: 'border-[var(--status-success-subtle)]',
    hoverBg: 'hover:bg-[var(--status-success)]',
  },
  pending: {
    bg: 'bg-[var(--surface-muted)]',
    text: 'text-[var(--text-secondary)]',
    border: 'border-[var(--border-default)]',
    hoverBg: 'hover:bg-[var(--surface-muted)]',
  },
  on_hold: {
    bg: 'bg-[var(--status-warning-subtle)]',
    text: 'text-[var(--status-warning-text)]',
    border: 'border-[var(--status-warning-subtle)]',
    hoverBg: 'hover:bg-[var(--status-warning)]',
  },
  cancelled: {
    bg: 'bg-[var(--status-danger-subtle)]',
    text: 'text-[var(--status-danger-text)]',
    border: 'border-[var(--status-danger-subtle)]',
    hoverBg: 'hover:bg-[var(--status-danger)]',
  },
};

/**
 * Bordered single-class task-status pill (`bg ... text ... border ...`),
 * composed from TASK_STATUS_TOKENS so there is no second source of truth.
 * Used by the task detail view (`pages/tasks/view`) and the subtask card,
 * which render the status as a bordered chip rather than the borderless
 * TASK_STATUS_CLASS pill.
 */
export const TASK_STATUS_BORDERED_CLASS: Record<TaskStatusKey, string> =
  Object.fromEntries(
    (Object.keys(TASK_STATUS_TOKENS) as TaskStatusKey[]).map((k) => [
      k,
      `${TASK_STATUS_TOKENS[k].bg} ${TASK_STATUS_TOKENS[k].text} ${TASK_STATUS_TOKENS[k].border}`,
    ]),
  ) as Record<TaskStatusKey, string>;

/**
 * Bordered priority chip (`text ... bg ... border ...`) used by the task
 * components list (`pages/tasks/components`). Distinct from PRIORITY_CLASS
 * (which is borderless and uses a different `low` palette); kept as its own
 * named map per the single-source rule rather than forked inline.
 */
export const TASK_PRIORITY_BORDERED_CLASS: Record<'low' | 'medium' | 'high' | 'urgent', string> = {
  low: 'text-[var(--text-tertiary)] bg-[var(--surface-base)] border-[var(--border-default)]',
  medium: 'text-[var(--accent-default)] bg-[var(--accent-subtle)] border-[var(--accent-subtle)]',
  high: 'text-[var(--status-warning-text)] bg-[var(--status-warning-subtle)] border-[var(--status-warning-subtle)]',
  urgent: 'text-[var(--status-danger-text)] bg-[var(--status-danger-subtle)] border-[var(--status-danger-subtle)]',
};

// =============================================================================
// 2. Project status
// =============================================================================

export type ProjectStatusKey =
  | 'draft'
  | 'planning'
  | 'in_progress'
  | 'on_hold'
  | 'completed'
  | 'cancelled';

/**
 * Project status tokens for the StatusBadge. Uses accent for active work
 * and warning/success/danger for the lifecycle endpoints.
 */
export const PROJECT_STATUS_CLASS: Record<ProjectStatusKey, string> = {
  draft: 'bg-[var(--surface-muted)] text-[var(--text-secondary)]',
  planning: 'bg-[var(--accent-subtle)] text-[var(--accent-default)]',
  in_progress: 'bg-[var(--accent-subtle)] text-[var(--accent-default)]',
  on_hold: 'bg-[var(--status-warning-subtle)] text-[var(--status-warning)]',
  completed: 'bg-[var(--status-success-subtle)] text-[var(--status-success)]',
  cancelled: 'bg-[var(--status-danger-subtle)] text-[var(--status-danger)]',
};

/**
 * Project status tokens for the printable ProjectReportCard. Decomposed
 * (color, bgColor, dotColor) because the card renders the status as a
 * small dot + text pair plus a separate background swatch. Visually
 * distinct from PROJECT_STATUS_CLASS: the report card uses `var(--status-info)`
 * for planning and `var(--status-warning)` for in_progress.
 */
export const PROJECT_STATUS_TOKENS: Record<ProjectStatusKey, {
  color: string;
  bgColor: string;
  dotColor: string;
}> = {
  draft: {
    color: 'text-[var(--text-secondary)]',
    bgColor: 'bg-[var(--surface-muted)]',
    dotColor: 'bg-[var(--surface-muted)]',
  },
  planning: {
    color: 'text-[var(--status-info)]',
    bgColor: 'bg-[var(--status-info-bg)]',
    dotColor: 'bg-[var(--accent-default)]',
  },
  in_progress: {
    color: 'text-[var(--status-warning)]',
    bgColor: 'bg-[var(--status-warning-bg)]',
    dotColor: 'bg-[var(--status-warning)]',
  },
  on_hold: {
    color: 'text-[var(--status-warning)]',
    bgColor: 'bg-[var(--status-warning-bg)]',
    dotColor: 'bg-[var(--status-warning)]',
  },
  completed: {
    color: 'text-[var(--status-success)]',
    bgColor: 'bg-[var(--status-success-bg)]',
    dotColor: 'bg-[var(--status-success)]',
  },
  cancelled: {
    color: 'text-[var(--status-danger)]',
    bgColor: 'bg-[var(--status-danger-bg)]',
    dotColor: 'bg-[var(--status-danger)]',
  },
};

// =============================================================================
// 3. Priority
// =============================================================================

export type PriorityKey =
  | 'low'
  | 'medium'
  | 'high'
  | 'urgent'
  | 'critical';

/**
 * Priority as a single text class. Used by Kanban/Cards/Table where
 * priority is rendered next to a flag icon. `urgent` and `critical` are
 * aliased because the task list uses `urgent` while the report card and
 * the badge use `critical`; the visual escalation is the same.
 */
export const PRIORITY_TEXT: Record<PriorityKey, string> = {
  low: 'text-[var(--text-tertiary)]',
  medium: 'text-[var(--accent-default)]',
  high: 'text-[var(--status-warning)]',
  urgent: 'text-[var(--status-danger)]',
  critical: 'text-[var(--status-danger)]',
};

/**
 * Priority used by the StatusBadge (with background). Mirrors the badge's
 * `STATUS_STYLES.priority` map. The four-color set is intentional; if you
 * need a 5th color slot, extend the StatusBadge component, not this map.
 */
export const PRIORITY_CLASS: Record<PriorityKey, string> = {
  low: 'bg-[var(--surface-muted)] text-[var(--text-secondary)]',
  medium: 'bg-[var(--accent-subtle)] text-[var(--accent-default)]',
  high: 'bg-[var(--status-warning-subtle)] text-[var(--status-warning)]',
  urgent: 'bg-[var(--status-warning-subtle)] text-[var(--status-warning)]',
  critical: 'bg-[var(--status-danger-subtle)] text-[var(--status-danger)]',
};

/**
 * Priority used by ProjectReportCard. Decomposed (color only) because the
 * card renders priority as text next to the status pill, with no bg.
 * The `medium` color is `var(--status-info)` here, not `var(--accent-default)`,
 * matching the card's original visual.
 */
export const PROJECT_REPORT_PRIORITY_COLOR: Record<PriorityKey, string> = {
  low: 'text-[var(--text-secondary)]',
  medium: 'text-[var(--status-info)]',
  high: 'text-[var(--status-warning)]',
  urgent: 'text-[var(--status-warning)]',
  critical: 'text-[var(--status-danger)]',
};

// =============================================================================
// 4. Time indicator (deadline-progress bar)
// =============================================================================

export type TimeIndicatorKey = 'normal' | 'warning' | 'urgent' | 'overdue' | 'completed';

/**
 * Colors for the inline time-progress bar shown on Kanban/Cards.
 * `bg` is the track, `fill` is the filled portion, `text` is the label.
 */
export const TIME_INDICATOR_TOKENS: Record<TimeIndicatorKey, {
  bg: string;
  fill: string;
  text: string;
}> = {
  normal: {
    bg: 'bg-[var(--border-strong)]',
    fill: 'bg-[var(--accent-default)]',
    text: 'text-[var(--text-secondary)]',
  },
  warning: {
    bg: 'bg-[var(--status-warning-subtle)]',
    fill: 'bg-[var(--status-warning)]',
    text: 'text-[var(--status-warning-text)]',
  },
  urgent: {
    bg: 'bg-[var(--status-warning-subtle)]',
    fill: 'bg-[var(--status-warning)]',
    text: 'text-[var(--status-warning-text)]',
  },
  overdue: {
    bg: 'bg-[var(--status-danger-subtle)]',
    fill: 'bg-[var(--status-danger)]',
    text: 'text-[var(--status-danger-text)]',
  },
  completed: {
    bg: 'bg-[var(--status-success-subtle)]',
    fill: 'bg-[var(--status-success)]',
    text: 'text-[var(--status-success-text)]',
  },
};

// =============================================================================
// 5. Kanban task column
// =============================================================================

/**
 * Per-column styling for the task Kanban. Same keys as TASK_STATUS_KEY
 * plus `pending` (the legacy alias). `bg` is the column body, `border`
 * is the column border, `headerBg` is the column header strip, and
 * `headerText` is the column header text/icon color.
 */
export const KANBAN_TASK_COLUMN_TOKENS: Record<TaskStatusKey, {
  bg: string;
  border: string;
  headerBg: string;
  headerText: string;
}> = {
  todo: {
    bg: 'bg-[var(--surface-subtle)]/80',
    border: 'border-[var(--border-default)]',
    headerBg: 'bg-[var(--surface-muted)]',
    headerText: 'text-[var(--text-secondary)]',
  },
  pending: {
    bg: 'bg-[var(--surface-subtle)]/80',
    border: 'border-[var(--border-default)]',
    headerBg: 'bg-[var(--surface-muted)]',
    headerText: 'text-[var(--text-secondary)]',
  },
  in_progress: {
    bg: 'bg-[var(--accent-subtle)]/50',
    border: 'border-[var(--accent-subtle)]',
    headerBg: 'bg-[var(--accent-subtle)]',
    headerText: 'text-[var(--accent-default)]',
  },
  in_review: {
    bg: 'bg-[var(--status-warning-subtle)]/50',
    border: 'border-[var(--status-warning-subtle)]',
    headerBg: 'bg-[var(--status-warning-subtle)]',
    headerText: 'text-[var(--status-warning-text)]',
  },
  completed: {
    bg: 'bg-[var(--status-success-subtle)]/50',
    border: 'border-[var(--status-success-subtle)]',
    headerBg: 'bg-[var(--status-success-subtle)]',
    headerText: 'text-[var(--status-success-text)]',
  },
  on_hold: {
    bg: 'bg-[var(--status-warning-subtle)]/50',
    border: 'border-[var(--status-warning-subtle)]',
    headerBg: 'bg-[var(--status-warning-subtle)]',
    headerText: 'text-[var(--status-warning-text)]',
  },
  cancelled: {
    bg: 'bg-[var(--status-danger-subtle)]/50',
    border: 'border-[var(--status-danger-subtle)]',
    headerBg: 'bg-[var(--status-danger-subtle)]',
    headerText: 'text-[var(--status-danger-text)]',
  },
};

// =============================================================================
// 6. Subtask aggregate state (Kanban card subtask toggle)
// =============================================================================

export type SubtaskAggregateKey = 'default' | 'allCompleted' | 'hasInProgressOrReview';

/**
 * Color for the Kanban card's subtask toggle button. The decision is a
 * status aggregate of the *subtask* list, not the parent task's status:
 *   - allCompleted          → green ("everything is done")
 *   - hasInProgressOrReview → red ("something is still being worked on")
 *   - default               → neutral (no subtasks or mixed)
 */
export const SUBTASK_AGGREGATE_TOKENS: Record<SubtaskAggregateKey, {
  iconColor: string;
  bgColor: string;
}> = {
  default: {
    iconColor: 'text-[var(--text-secondary)]',
    bgColor: 'hover:bg-[var(--surface-muted)]',
  },
  allCompleted: {
    iconColor: 'text-[var(--status-success)]',
    bgColor: 'bg-[var(--status-success-subtle)] hover:bg-[var(--status-success-subtle)]',
  },
  hasInProgressOrReview: {
    iconColor: 'text-[var(--status-danger)]',
    bgColor: 'bg-[var(--status-danger-subtle)] hover:bg-[var(--status-danger-subtle)]',
  },
};

// =============================================================================
// 7. OVR (incident) status chart fill
// =============================================================================

export type OvrStatusKey =
  | 'draft'
  | 'new'
  | 'under_review'
  | 'pending_info'
  | 'in_progress'
  | 'resolved'
  | 'closed'
  | 'rejected'
  | 'archived';

/**
 * Raw CSS values for the OVR status donut chart. Recharts consumes these
 * as `fill` and as `style={{ backgroundColor: ... }}` for the legend swatches.
 */
export const OVR_STATUS_CHART_TOKENS: Record<OvrStatusKey, string> = {
  draft: 'var(--text-tertiary)',
  new: 'var(--accent-default)',
  under_review: 'var(--status-warning)',
  pending_info: 'var(--status-warning)',
  in_progress: 'var(--status-info)',
  resolved: 'var(--status-success)',
  closed: 'var(--status-success)',
  rejected: 'var(--status-danger)',
  archived: 'var(--text-tertiary)',
};

export type OvrSeverityKey = 'low' | 'medium' | 'high' | 'critical';

export const OVR_SEVERITY_CHART_TOKENS: Record<OvrSeverityKey, string> = {
  low: 'var(--status-success)',
  medium: 'var(--status-warning)',
  high: 'var(--status-danger)',
  critical: 'var(--status-danger)',
};

// =============================================================================
// 8. OVR status → Badge variant
// =============================================================================

/**
 * Maps an OVR incident status to the <Badge> variant enum. The Badge
 * component owns the color rendering — this is a status→component-variant
 * map, not a status→color map. Kept in this module because the brief
 * lists it as a duplicate of a status→decision that lived in
 * `pages/ovr/components/constants.ts`.
 */
export const OVR_STATUS_BADGE_VARIANT: Record<OvrStatusKey,
  'default' | 'accent' | 'warning' | 'success' | 'danger' | 'info'
> = {
  draft: 'default',
  new: 'accent',
  under_review: 'warning',
  pending_info: 'warning',
  in_progress: 'accent',
  resolved: 'success',
  closed: 'success',
  rejected: 'danger',
  archived: 'default',
};
