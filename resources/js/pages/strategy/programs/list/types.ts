export interface Program {
  id: number;
  code: string;
  name: string;
  description: string | null;
  status: string;
  status_label: string;
  priority: string;
  priority_label: string;
  progress: number;
  budget: number | null;
  spent_amount: number | null;
  budget_utilization: number;
  start_date: string | null;
  end_date: string | null;
  portfolio: { id: number; code: string; name: string } | null;
  department: { id: number; name: string } | null;
  owner: { id: number; name: string } | null;
  program_manager: { id: number; name: string } | null;
  projects_count: number;
  blockers_count: number;
}

export interface PortfolioOption {
  id: number;
  code: string;
  name: string;
}

export interface PaginatedResponse {
  data: Program[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}
