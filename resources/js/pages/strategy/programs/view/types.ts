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
  executive_sponsor: { id: number; name: string } | null;
  projects_count: number;
  blockers_count: number;
}

export interface Project {
  id: number;
  code: string;
  name: string;
  status: string;
  priority: string;
  progress: number;
  start_date: string | null;
  end_date: string | null;
  manager: { id: number; name: string } | null;
  department: { id: number; name: string } | null;
}

export interface UnlinkedProject {
  id: number;
  code: string;
  name: string;
  status: string;
  department: { id: number; name: string } | null;
}

export const projectStatusLabels: Record<string, string> = {
  draft: 'status.draft',
  planning: 'status.planning',
  in_progress: 'status.in_progress',
  on_hold: 'status.on_hold',
  completed: 'status.completed',
  cancelled: 'status.cancelled',
};

export const projectStatusVariants: Record<string, 'default' | 'accent' | 'success' | 'warning' | 'danger'> = {
  draft: 'default',
  planning: 'accent',
  in_progress: 'accent',
  on_hold: 'warning',
  completed: 'success',
  cancelled: 'danger',
};

export const projectPriorityVariants: Record<string, 'default' | 'success' | 'warning' | 'danger'> = {
  low: 'default',
  medium: 'success',
  high: 'warning',
  critical: 'danger',
};

export const formatDate = (dateStr: string | null): string => {
  if (!dateStr) return '-';
  const date = new Date(dateStr);
  return date.toLocaleDateString('ar-EG-u-nu-latn', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  });
};
