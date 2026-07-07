/**
 * Strategy entity — model (types & interfaces).
 *
 * PMI Standard: Portfolio -> Program -> Project
 */

// ========================================
// Portfolio Status Types
// ========================================

export type PortfolioStatus = 'active' | 'rebalancing' | 'frozen' | 'closed_strategically';
export type OperationalStatus = 'draft' | 'active' | 'completed' | 'cancelled';
export type ProgramStatus = 'draft' | 'planning' | 'in_progress' | 'on_hold' | 'completed' | 'cancelled';
export type ProgramPriority = 'low' | 'medium' | 'high' | 'critical';
export type ProgressCalculationMethod = 'weighted' | 'average' | 'manual';

// ========================================
// Portfolio Interface (الالتزام التنفيذي)
// ========================================

export interface Portfolio {
  id: number;
  code: string;
  name: string;
  description: string | null;
  rationale: string | null;
  strategic_plan_link: string | null;
  directive_source: string | null;
  directive_source_other: string | null;
  directive_source_label: string | null;
  start_date: string | null;
  end_date: string | null;
  status: OperationalStatus;
  status_label: string;
  portfolio_status: PortfolioStatus;
  portfolio_status_label: string;
  portfolio_progress: number;
  progress?: number;
  weight: number;
  priority_rank: number;
  order: number;
  portfolio_owner_id: number | null;
  creator?: { id: number; name: string };
  owner?: { id: number; name: string } | null;
  programs_count?: number;
  programs?: Program[];
}

// ========================================
// Program Interface (المبادرة)
// ========================================

export interface Program {
  id: number;
  code: string;
  name: string;
  description: string | null;
  portfolio_id: number | null;
  portfolio?: { id: number; code: string; name: string };
  department_id: number | null;
  department?: { id: number; name: string };
  budget: number;
  total_program_budget: number;
  spent_amount: number;
  budget_utilization: number;
  start_date: string | null;
  end_date: string | null;
  progress: number;
  progress_calculation_method: ProgressCalculationMethod;
  progress_method_label: string;
  weight: number;
  status: ProgramStatus;
  status_label: string;
  priority: ProgramPriority;
  priority_label: string;
  owner_id: number | null;
  owner?: { id: number; name: string } | null;
  program_manager_id: number | null;
  program_manager?: { id: number; name: string } | null;
  executive_sponsor_id: number | null;
  executive_sponsor?: { id: number; name: string } | null;
  created_by: number | null;
  creator?: { id: number; name: string } | null;
  order: number;
  projects_count?: number;
  blockers_count?: number;
}

// ========================================
// Portfolio Tree (Phase 7.2)
// GET /api/strategy/dashboard/portfolio/{id}/tree
// ========================================

export type PortfolioTreeDepth = 'full' | 'programs';
export type PortfolioTreeStatusFilter = 'active' | 'all';

export interface PortfolioTreeParams {
  depth?: PortfolioTreeDepth;
  include_status?: PortfolioTreeStatusFilter;
  hide_empty_programs?: boolean;
}

export interface PortfolioTreeNode {
  id: number;
  code: string;
  name: string;
  status: string;
  portfolio_status: string;
  portfolio_progress: number;
  weight: number;
  directive_source: string;
  directive_source_label: string;
  start_date: string | null;
  end_date: string | null;
  can_be_closed: boolean;
}

export interface PortfolioTreeProject {
  id: number;
  code: string;
  name: string;
  status: string;
  priority: string;
  progress: number;
  budget: number | null;
  start_date: string | null;
  end_date: string | null;
  department: { id: number; name: string } | null;
}

export interface PortfolioTreeProgram {
  id: number;
  code: string;
  name: string;
  status: string;
  priority: string;
  progress: number;
  weight: number;
  budget: number | null;
  spent_amount: number | null;
  budget_utilization: number;
  department: { id: number; name: string } | null;
  blockers_count: number;
  projects_count: number;
  projects: PortfolioTreeProject[];
}

export interface PortfolioTreeStats {
  programs_total: number;
  projects_total: number;
  projects_in_progress: number;
  projects_completed: number;
  projects_overdue: number;
  avg_project_progress: number;
  open_blockers: number;
  critical_blockers: number;
}

export interface PortfolioTreeMeta {
  generated_at: string;
  cached: boolean;
  cache_key: string;
}

/** Inner payload returned by the tree endpoint. */
export interface PortfolioTreePayload {
  portfolio: PortfolioTreeNode;
  programs: PortfolioTreeProgram[];
  stats: PortfolioTreeStats;
  meta: PortfolioTreeMeta;
}

/** Full HTTP envelope (matches Laravel resource `{ data: ... }` shape). */
export interface PortfolioTreeResponse {
  data: PortfolioTreePayload;
}
