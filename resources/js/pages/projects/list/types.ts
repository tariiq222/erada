export type ProjectType = 'improvement' | 'development';

/**
 * Per-record abilities are computed server-side by `ElementAbilities` via
 * `AccessDecision::can`. The list endpoint must include them so the table
 * can gate the row-level Delete action without a second round-trip.
 * Optional on the type so the existing tests (which mock older fixtures
 * without `abilities`) still compile.
 */
export interface ProjectAbilities {
  view?: boolean;
  edit?: boolean;
  delete?: boolean;
  manage_members?: boolean;
  change_status?: boolean;
  close?: boolean;
  assign_roles?: boolean;
}

export interface Project {
  id: number;
  code: string;
  name: string;
  type: ProjectType;
  status: string;
  priority: string;
  progress: number;
  start_date: string | null;
  end_date: string | null;
  department: { id: number; name: string } | null;
  program: { id: number; code: string; name: string } | null;
  manager: { id: number; name: string } | null;
  abilities?: ProjectAbilities;
}

export interface ProgramOption {
  id: number;
  code: string;
  name: string;
}

export interface PaginatedResponse {
  data: Project[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}
