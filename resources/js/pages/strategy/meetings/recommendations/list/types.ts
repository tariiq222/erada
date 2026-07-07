import type { Recommendation } from '@features/meetings/types';

export interface RecommendationPaginated {
  data: Recommendation[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export interface RecommendationListFilters {
  status: string;
  priority: string;
  decision_id: string;
  assignee_id: string;
  overdue: boolean;
  page: number;
}
