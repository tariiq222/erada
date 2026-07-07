export interface Portfolio {
  id: number;
  code: string;
  name: string;
  description: string | null;
  status: string;
  status_label: string;
  strategic_plan_link: string | null;
  directive_source: string | null;
  directive_source_other: string | null;
  directive_source_label: string | null;
  start_date: string | null;
  end_date: string | null;
  order: number;
  objectives_count: number;
  programs_count: number;
  progress: number;
}

export interface PaginatedResponse {
  data: Portfolio[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}
