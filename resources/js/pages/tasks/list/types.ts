export interface TimeIndicator {
  days_remaining: number | null;
  days_elapsed: number | null;
  total_days: number | null;
  time_progress: number | null;
  status: 'normal' | 'warning' | 'urgent' | 'overdue' | 'completed';
  has_due_date: boolean;
}

export interface SubTask {
  id: number;
  title: string;
  status: string;
  assignee?: { id: number; name: string } | null;
}

export interface Task {
  id: number;
  type?: 'project' | 'personal' | 'department' | 'recurring';
  title: string;
  status: string;
  priority: string;
  start_date: string | null;
  due_date: string | null;
  time_indicator: TimeIndicator;
  project: { id: number; name: string; code: string; type?: string | null } | null;
  assignee: { id: number; name: string } | null;
  milestone: { id: number; name: string } | null;
  department?: { id: number; name: string } | null;
  owner?: { id: number; name: string } | null;
  is_private?: boolean;
  subtasks_count?: number;
  subtasks?: SubTask[];
}

export interface PaginationMeta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export interface PaginatedResponse {
  data: Task[];
  // Unified API nests pagination under `meta` ({ data, links, meta }).
  meta?: PaginationMeta;
  // Legacy flattened fields kept optional for backward compatibility.
  current_page?: number;
  last_page?: number;
  per_page?: number;
  total?: number;
}

export interface TaskFilters {
  search: string;
  status: string;
  priority: string;
  my_tasks: boolean;
  overdue: boolean;
}

export interface PaginationState {
  currentPage: number;
  lastPage: number;
  total: number;
}
