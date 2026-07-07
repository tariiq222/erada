export interface DeliverableItem {
  id?: number;
  name: string;
}

export interface MilestoneItem {
  id?: number;
  name: string;
  start_date: string;
  due_date: string;
  description: string;
  deliverables: DeliverableItem[];
}

export interface RiskItem {
  id?: number;
  description: string;
  impact: string;
  probability: string;
  mitigation: string;
  status?: string;
}

export interface KpiInput {
  id?: number;
  name: string;
  baseline: string;
  target: string;
  unit: string;
  measurement_method: string;
}

export interface StakeholderItem {
  user_id?: number;
  name: string;
  role: string;
  contact: string;
  influence: string;
}

export interface TeamMemberItem {
  user_id?: number;
  name: string;
  role: string;
}

export interface TaskItem {
  id?: number;
  name: string;
  description: string;
  milestone_index?: number;
  milestone_id?: number;
  assigned_to?: number;
  assigned_to_name?: string;
  priority: string;
  start_date: string;
  due_date: string;
}

export interface ProjectFormData {
  name: string;
  description: string;
  objectives: string[];
  in_scope: string[];
  out_of_scope: string[];
  department_id: string;
  program_id: string;
  status: string;
  priority: string;
  start_date: string;
  end_date: string;
  budget: string;
  milestones: MilestoneItem[];
  tasks: TaskItem[];
  risks: RiskItem[];
  kpis: KpiInput[];
  team_members: TeamMemberItem[];
  stakeholders: StakeholderItem[];
  human_resources: string;
  technical_resources: string;
  financial_resources: string;

  // Project type
  type: string; // 'development' | 'improvement'

  // PMBOK fields (for type='development')
  business_case: string;
  success_criteria: string;
  requirements: string;
  manager_authority: string;
  approval_criteria: string;
  exit_criteria: string;

  // FOCUS-PDCA fields (for type='improvement')
  target_process: string;
  problem_statement: string;
  root_cause: string;
  expected_benefits: string;
  current_pdca_phase: string; // 'P' | 'D' | 'C' | 'A'
}

export interface ProgramOption {
  id: number;
  code: string;
  name: string;
  portfolio?: { id: number; name: string };
}

export interface UserOption {
  id: number;
  name: string;
}

export interface DepartmentOption {
  id: number;
  name: string;
  code: string | null;
  parent_id: number | null;
  level: number;
  level_name: string;
}

export interface ValidationErrors {
  [key: string]: string[];
}
