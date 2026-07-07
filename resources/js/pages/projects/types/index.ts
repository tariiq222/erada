// Types for Project View

export interface TimeIndicator {
  days_remaining: number | null;
  days_elapsed: number | null;
  total_days: number | null;
  time_progress: number | null;
  status: 'normal' | 'warning' | 'urgent' | 'overdue' | 'completed';
  has_due_date: boolean;
}

export interface Deliverable {
  id: number;
  name: string;
  description: string | null;
  status: string;
  progress: number;
}

export interface MilestoneWithDeliverables {
  id: number;
  name: string;
  description: string | null;
  start_date: string | null;
  due_date: string | null;
  completed_date: string | null;
  status: string;
  progress: number;
  deliverables?: Deliverable[];
}

export interface ProjectDetails {
  id: number;
  organization_id?: number | null;
  code: string;
  name: string;
  description: string | null;
  objectives: string[] | null;
  in_scope: string[] | null;
  out_of_scope: string[] | null;
  status: string;
  priority: string;
  progress: number;
  start_date: string | null;
  end_date: string | null;
  budget: number | null;
  actual_cost: number | null;
  department: { id: number; name: string } | null;
  manager: { id: number; name: string } | null;
  creator: { id: number; name: string } | null;
  members: { id: number; name: string; pivot: { role: string } }[];
  milestones: MilestoneWithDeliverables[];
  tasks: TaskType[];
  stakeholders: { id: number; name: string; role: string; organization: string }[];
  kpis: ProjectKPI[];
  risks: { id: number; risk: string; probability: string; impact: string; response?: string; status: string }[];

  // Methodology fields (from ProjectResource)
  type?: 'development' | 'improvement' | null;
  triage_answers?: Record<string, string> | null;

  // PMBOK (development project) fields.
  // success_criteria / requirements / manager_authority are cast to arrays
  // by the backend (Project model casts) and returned as arrays.
  business_case?: string | null;
  success_criteria?: string[] | null;
  requirements?: string[] | null;
  manager_authority?: string[] | null;
  approval_criteria?: string | null;
  exit_criteria?: string | null;

  // Resources & support
  human_resources?: string | null;
  technical_resources?: string | null;
  financial_resources?: string | null;

  // FOCUS-PDCA (improvement project) fields.
  // expected_benefits is cast to an array by the backend (Project model casts).
  problem_statement?: string | null;
  target_process?: string | null;
  root_cause?: string | null;
  expected_benefits?: string[] | null;
  current_pdca_phase?: string | null;
}

export interface ProjectKPI {
  id: number;
  indicator: string;
  target: string;
  current_value: string;
  unit?: string | null;
  performance_link_id?: number | null;
  achievement_percentage?: number | null;
  performance_status?: string | null;
}

export interface TaskType {
  id: number;
  title: string;
  status: string;
  priority: string;
  start_date: string | null;
  due_date: string | null;
  time_indicator: TimeIndicator;
  assignee: { id: number; name: string } | null;
  milestone: { id: number; name: string } | null;
  subtasks_count?: number;
  // Per-record abilities (Phase 9.3 freeze cleanup 2026-07-06). Set
  // server-side via ElementAbilities; consulted by ProjectTasksSection
  // to decide "can this user move THIS task" without an extra round-trip.
  abilities?: {
    view?: boolean;
    edit?: boolean;
    delete?: boolean;
    change_status?: boolean;
    complete?: boolean;
    assign?: boolean;
  };
}

export interface SubTask {
  id: number;
  title: string;
  status: string;
  assignee?: { id: number; name: string } | null;
}

export type TaskStatus = 'todo' | 'in_progress' | 'in_review' | 'completed';
